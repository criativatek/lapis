<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Models\AcademicPeriod;
use App\Models\CohortUniverse;
use App\Models\ExternalSubjectResult;
use App\Models\ProfileVersionPeriod;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\SchoolClass;
use App\Support\Assessment\AssessmentCutoff;
use InvalidArgumentException;

/**
 * The class read as a whole, instead of student by student.
 *
 * NOTHING ACADEMIC IS COMPUTED HERE. Every figure this aggregates was already
 * decided by BuildResultsProgression — which in turn assembles what
 * ClassResultsCalculator produced — and this makes exactly one call to it. What
 * is added is the layer above: counting, averaging across students, and
 * grouping. Estatística must never be able to disagree with Resultados or with
 * the Quadro Síntese, and the only way to guarantee that is to read the same
 * numbers rather than to derive them again (§0).
 *
 * The arithmetic that IS here is statistical and says so:
 *
 *  - the class average is the mean of the students' own canonical averages,
 *    each student counting once. Not a mean of domain means, which would weigh
 *    a domain by how many students happen to have evidence in it;
 *  - a null never enters a mean. A student with no evidence is not a zero, and
 *    dividing by them would move a class average by the mere absence of a
 *    result (§37);
 *  - counts of movement come from the evolution the progression already
 *    decided: standalone against the previous standalone, never accumulated
 *    against accumulated (§10).
 *
 * Rounded to the same precision the rest of the application shows, so that
 * «72,4%» on Resultados is «72,4%» here (§38).
 *
 * IT NORMALLY BUILDS ITS OWN PROGRESSION, and every screen but one lets it. A
 * caller that already holds one — because it needs the longitudinal model in its
 * own right, which is Evolução do Aluno's whole situation — may hand it over
 * instead, and the second walk over the class's year simply does not happen. The
 * progression must be one built for the SAME class and cutoff; it says which,
 * and one from anywhere else is refused rather than used.
 */
class BuildClassStatistics
{
    /** The same precision the results are read at. */
    public const PRECISION = BuildResultsProgression::PRECISION;

    public function __construct(
        protected BuildResultsProgression $progression,
        protected ScaleProposalResolver $proposals,
        protected PrimaryResultScope $scope,
    ) {}

    /**
     * The progression this build reads — asked for, or accepted from a caller
     * that already has it.
     *
     * NORMALLY IT BUILDS ITS OWN, and every existing caller still does. The
     * parameter exists for the one case where building it again would be doing
     * the same work twice: Evolução do Aluno needs the longitudinal model in its
     * own right — the per-period classifications and self-assessments are only
     * there — and then needs this aggregate layer on top of it. Before the
     * parameter, that page ran the progression twice and paid for the class's
     * whole year twice with it.
     *
     * IT IS REUSE, NOT A CACHE. Nothing is remembered between calls, no state is
     * held, and there is no key to invalidate. The caller has the array in hand
     * and hands it over; that is the entire mechanism.
     *
     * A PROGRESSION FROM ANOTHER CONTEXT IS REFUSED, LOUDLY. It is stamped by
     * BuildResultsProgression with the class and cutoff it answers about, and a
     * mismatch throws rather than being quietly rebuilt. Silently recovering
     * would hide the bug; quietly accepting would put a photograph's numbers
     * under a screen that says «hoje», which is the one failure this whole
     * module is built to avoid.
     *
     * @param  array<string, mixed>|null  $supplied
     * @return array<string, mixed>
     */
    protected function progressionFor(SchoolClass $class, ?AssessmentCutoff $cutoff, ?array $supplied): array
    {
        if ($supplied === null) {
            return $this->progression->for($class, $cutoff);
        }

        $expected = BuildResultsProgression::contextFor($class, $cutoff);

        if (($supplied['context'] ?? null) !== $expected) {
            throw new InvalidArgumentException(
                'The supplied results progression was built for a different class or cutoff.',
            );
        }

        return $supplied;
    }

    /**
     * @param  array<string, mixed>|null  $progression  a progression this caller
     *                                                  already built for the SAME class and cutoff. Null — the ordinary
     *                                                  case — builds one here.
     * @return array<string, mixed>
     */
    public function for(
        SchoolClass $class,
        ?AcademicPeriod $period = null,
        ?AssessmentCutoff $cutoff = null,
        ?array $progression = null,
        CohortUniverse $universe = CohortUniverse::AttendingOnly,
    ): array {
        // THE ONE CALL for the progression, still. Everything below is
        // arithmetic over its output — EXCEPT `universeRowsFor()`, which, only
        // when the universe is `AllClassStudents` and only for the students who
        // do not attend, issues a CONSTANT number of extra queries (one for the
        // period's `profile_version_periods` row, one for their
        // `external_subject_results`) to resolve external results — never one
        // per student, and never at all for `AttendingOnly` (M5). That cost does
        // not grow with the size of the class; it is bounded by the number of
        // periods and universes read, not by how many students are in it.
        $progression = $this->progressionFor($class, $cutoff, $progression);

        $periods = $progression['periods'];
        $domains = $progression['domains'];
        $students = $progression['students'];

        $selected = $this->selectedPeriod($periods, $students, $period);

        if ($selected === null) {
            return $this->empty($periods, $domains);
        }

        $scale = $class->profileVersion?->scale()->with('levels')->first();
        $rows = $this->rowsFor($students, $selected['id']);

        $universeResolution = $this->universeRowsFor($rows, $class, $selected, $universe, $scale);
        $universeRows = $universeResolution['rows'];

        // H1: A ANÁLISE POR DOMÍNIO NUNCA É DECIDIDA PELO COHORT À DATA DE
        // REFERÊNCIA (`period.participation`, a pergunta B) — é decidida pela
        // DATA DA PRÓPRIA EVIDÊNCIA (a pergunta A). Um aluno que já não
        // frequenta à data de referência mas que respondeu a um instrumento
        // DENTRO da janela em que ainda frequentava mantém essa evidência
        // válida na análise por domínio; a decisão do product owner (§ regra
        // das duas perguntas) exige-o explicitamente. `domainCell()` já é o
        // portão certo — só devolve algo quando existem DADOS — por isso basta
        // alimentar `domainStatistics()` com a progressão COMPLETA (`$rows`),
        // nunca com um subconjunto filtrado pelo cohort de referência.
        $domainEligibleRows = array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->rowHasDomainEvidence($row, $domains),
        ));

        // O denominador «X de Y» impresso pelo Vue: quem PODERIA ter tido
        // dados de domínio nesta leitura — nunca mais que isto, e nunca o
        // cohort de referência (que responderia à pergunta errada).
        $domainEligibleIds = array_map(
            fn (array $row): int => (int) $row['enrollment_id'],
            $domainEligibleRows,
        );

        // A nota «não inclui N alunos…» só pode falar de quem foi
        // GENUINAMENTE excluído por FALTA DE DADOS DE DOMÍNIO — tipicamente
        // quem não frequenta à data de referência e não tem nenhuma evidência
        // dentro da janela em que frequentou. Um aluno não-frequentante à
        // data de referência mas presente em `$domainEligibleRows` (porque
        // respondeu dentro da janela) NUNCA entra aqui — as duas notas
        // (`domain_exclusion` e `partial_period_attendance`) nunca podem
        // contradizer-se sobre o mesmo aluno.
        $domainExcludedRows = array_values(array_filter(
            $rows,
            fn (array $row): bool => ($row['period']['participation'] ?? null) !== null
                && ! in_array((int) $row['enrollment_id'], $domainEligibleIds, true),
        ));

        /** @var list<string> $domainExcludedOrigins */
        $domainExcludedOrigins = array_values(array_unique(array_filter(
            array_map(
                fn (array $row): ?string => $row['period']['participation']['reason_detail'] ?? null,
                $domainExcludedRows,
            ),
            fn (?string $detail): bool => $detail !== null && $detail !== '',
        )));

        // WHICH FIGURE IS THE ANSWER, at each moment of this year. Read from
        // the profile version's own periods, so a school that configures
        // continuity differently gets a different answer here without a line
        // of code changing (§2).
        $scopes = $this->scope->forClass($class, $periods);
        $primaryKind = $scopes[$selected['id']] ?? 'period';

        $previous = $this->previousPeriod($periods, $selected['id']);
        // The same students, read at the period before — so a change of side on
        // the scale can be seen at all. Another reshaping of what is already in
        // hand, not another read.
        $previousRowsRaw = $previous === null ? [] : $this->rowsFor($students, $previous['id']);
        $previousResolution = $previous === null
            ? null
            // H2: nunca aplicar resultados externos ao período anterior — só
            // servem para ler o período selecionado, nunca para fabricar uma
            // comparação.
            : $this->universeRowsFor($previousRowsRaw, $class, $previous, $universe, $scale, applyExternalResults: false);
        $previousRows = $previousResolution['rows'] ?? [];

        return [
            'periods' => $periods,
            'selected_period' => $selected,
            'previous_period' => $previous,
            'domains' => $domains,
            'scale' => $this->scalePayload($scale),
            // O universo escolhido, devolvido para nunca ficar implícito (req
            // 8): quem lê um número aqui sabe sempre qual foi o denominador.
            'universe' => [
                'value' => $universe->value,
                'label' => $universe->label(),
            ],
            // Os factos sobre quem não frequenta, para as frases que os
            // relatórios e o ecrã constroem por cima (req 3, req 12).
            'cohort' => [
                'not_attending_count' => $universeResolution['not_attending_count'],
                'not_attending_origins' => $universeResolution['not_attending_origins'],
                'external_included_count' => $universeResolution['external_included_count'],
                // H1: quantos não frequentam E não têm resultado externo — a
                // única forma honesta de o ecrã dizer que ficaram fora de
                // toda a fração, em vez de aparecerem como «sem
                // classificação» (req 9).
                'not_attending_without_result' => $universeResolution['not_attending_without_result'],
            ],
            'notes' => [
                // H1: já não lê `$universeResolution['not_attending_count']`
                // (a pergunta B, cohort à data de referência) — lê quem ficou
                // GENUINAMENTE sem dados de domínio, calculado acima.
                'domain_exclusion' => $this->domainExclusionNote(
                    count($domainExcludedRows),
                    $domainExcludedOrigins,
                ),
                'external_inclusion' => $universe === CohortUniverse::AllClassStudents
                    ? $this->externalInclusionNote(
                        $universeResolution['external_included_count'],
                        $universeResolution['not_attending_origins'],
                    )
                    : null,
                // §6 da decisão do product owner (regra das duas perguntas):
                // uma nota neutra, sem nome de produto, para quando pelo
                // menos um aluno frequentou a disciplina apenas durante
                // PARTE deste período — para que quem lê a análise saiba que
                // a evidência de antes da janela abrir continua válida nela.
                'partial_period_attendance' => $this->partialPeriodAttendanceNote($rows, $selected, $domains),
            ],
            // Which reading answers «como está a turma» at this moment, and
            // which one is the supplementary «e só neste período?» (§1, §5).
            'primary' => $this->primaryPayload($primaryKind, $scopes, $periods, $selected),
            'summary' => $this->summary($universeRows, $scale, $primaryKind, count($domainEligibleRows)),
            'evolution' => $this->evolution($universeRows, $previousRows, $scale),
            'continuous_evolution' => $this->continuousEvolution(
                $universeRows,
                $previousRows,
                $primaryKind,
                $previous === null ? null : ($scopes[$previous['id']] ?? 'period'),
            ),
            // The grades, and the averages. Two readings, never averaged into
            // one, each named on screen by what it counts (§1, §8).
            'assigned_distribution' => $this->assignedDistribution($universeRows, $scale),
            'distribution' => $this->distribution($universeRows, $scale),
            // SEMPRE `$rows` (a progressão completa, H1) — a análise por
            // domínio nunca segue o universo (req 10) NEM o cohort à data de
            // referência: `domainCell()` já é o portão data-driven correto
            // (só devolve algo quando há dados), e é ele — nunca
            // `period.participation` — que decide quem aparece aqui.
            'domain_statistics' => $this->domainStatistics($rows, $domains, $scale),
            'period_series' => $this->periodSeries($students, $periods, $domains, $scopes),
            'students' => $this->students(
                $universeRows,
                $scale,
                $students,
                $previousRows,
                $primaryKind,
                $scopes,
                $previous === null ? null : ($scopes[$previous['id']] ?? 'period'),
            ),
        ];
    }

    /**
     * H1: se esta linha tem QUALQUER dado de domínio — o único portão que a
     * análise por domínio pode usar, DATA-DRIVEN, nunca o cohort à data de
     * referência (`period.participation`, que responde à pergunta B, não à
     * pergunta A). Um aluno que já não frequenta à data de referência mas que
     * respondeu a um instrumento dentro da janela em que ainda frequentava
     * tem `domainCell()` não-nulo aqui e conta para este grupo.
     *
     * @param  array<string, mixed>  $row
     * @param  list<array{id: int, name: string}>  $domains
     */
    protected function rowHasDomainEvidence(array $row, array $domains): bool
    {
        foreach ($domains as $domain) {
            if ($this->domainCell($row, $domain['id']) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * O universo pedido, resolvido: `AttendingOnly` fica só com quem frequenta;
     * `AllClassStudents` (req 9) acrescenta quem não frequenta e tem um
     * resultado externo compatível — rotulado (req 6/7), nunca disfarçado de
     * evidência desta disciplina. Quem não frequenta e não tem resultado
     * externo simplesmente fica de fora da fração: nunca um zero, nunca uma
     * insucesso, nunca «sem classificação» (req 9).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $period
     * @return array{rows: list<array<string, mixed>>, not_attending_count: int, not_attending_origins: list<string>, external_included_count: int, not_attending_without_result: int}
     */
    protected function universeRowsFor(
        array $rows,
        SchoolClass $class,
        array $period,
        CohortUniverse $universe,
        ?Scale $scale,
        bool $applyExternalResults = true,
    ): array {
        $attending = [];
        $notAttending = [];

        foreach ($rows as $row) {
            if (($row['period']['participation'] ?? null) === null) {
                $attending[] = $row;
            } else {
                $notAttending[] = $row;
            }
        }

        /** @var list<string> $origins */
        $origins = array_values(array_unique(array_filter(
            array_map(
                fn (array $row): ?string => $row['period']['participation']['reason_detail'] ?? null,
                $notAttending,
            ),
            fn (?string $detail): bool => $detail !== null && $detail !== '',
        )));

        if ($notAttending === []) {
            return [
                'rows' => $attending,
                'not_attending_count' => 0,
                'not_attending_origins' => $origins,
                'external_included_count' => 0,
                'not_attending_without_result' => 0,
            ];
        }

        // H2: um resultado externo NUNCA é aplicado à comparação do período
        // anterior — só ao período selecionado. Aplicá-lo aos dois lados
        // fabricaria uma evolução/transição que ninguém observou (§H2).
        $externals = $applyExternalResults
            ? $this->externalResultsFor(
                $class,
                $period,
                array_map(fn (array $row): int => (int) $row['enrollment_id'], $notAttending),
            )
            : [];

        // M3: estes três números são calculados AQUI, sempre — mesmo sob
        // `AttendingOnly`, que não usa `$processed` em `rows`. Antes desta
        // correção, o ramo de cima devolvia `not_attending_without_result =
        // count($notAttending)` sem consultar nenhum resultado externo, o que
        // dizia "sem resultado" de alunos que de facto o têm registado noutro
        // sítio — a mesma figura é exportada por `ClassReportSource`, que
        // herdava o mesmo erro.
        $included = 0;
        $withoutResult = 0;
        $processed = [];

        foreach ($notAttending as $row) {
            $external = $externals[(int) $row['enrollment_id']] ?? null;
            $classified = $external === null ? null : $this->withExternalClassification($row, $external, $scale);

            // H1: só conta como incluído — e só sai de
            // `not_attending_without_result` — quem o resultado externo
            // EFETIVAMENTE RESOLVEU a um nível desta escala
            // (`classification.final` não nulo). Um `ExternalSubjectResult`
            // existe mas com `scale_level_id` nulo (só `level_code` ou só
            // `numeric_value`) não dá origem a classificação nenhuma — «não
            // frequenta» nunca pode reproduzir «sem classificação» só porque
            // o registo externo não coube nesta escala.
            if ($classified === null || ($classified['period']['classification']['final'] ?? null) === null) {
                // Sem classificação resolvida: continua na lista (rotulado,
                // nunca omitido), carregando a proveniência externa quando
                // ela existe (via `withExternalClassification()`), mas sem
                // classificação nenhuma — fora de toda a fração, nunca um
                // zero (req 9). Marcado, para que `countableRows()` o exclua
                // de todos os numeradores e denominadores — «não frequenta»
                // nunca é «sem classificação».
                $row = $classified ?? $row;
                $row['period'] ??= [];
                $row['period']['participation_only'] = true;

                // DECISÃO DO PRODUCT OWNER (substitui a redação original de
                // H3): uma classificação interna PRÉ-EXISTENTE nunca é
                // apagada na base de dados — nada é escrito aqui, isto é só
                // uma estrutura em memória — mas também nunca é
                // automaticamente a resposta FINAL desta análise para um
                // aluno que, à data de referência, não frequenta a
                // disciplina e cujo resultado externo (quando existe) não se
                // resolveu a um nível. Sem isso, uma decisão tomada quando
                // ele ainda frequentava sobreviveria como «final» mesmo
                // depois de deixar de frequentar, sem ninguém a ter
                // reafirmado nessa condição. Só se aplica quando não há
                // resultado externo NENHUM — quando existe mas não resolveu,
                // `withExternalClassification()` já escreveu o seu próprio
                // `classification` (final nulo, proveniência presente), que
                // fica exatamente como está.
                if ($classified === null) {
                    $row['period']['classification'] = null;
                }

                $processed[] = $row;
                $withoutResult++;

                continue;
            }

            // O resultado externo RESOLVEU A UM NÍVEL: é a resposta final
            // desta análise para este período, e substitui — só nesta
            // estrutura em memória, nunca na base de dados — qualquer
            // classificação interna pré-existente que o aluno tivesse. Essa
            // classificação interna nunca é apagada onde vive (§ decisão do
            // product owner que revoga a redação original de H3); só deixa
            // de ser lida como a resposta desta leitura específica.
            $included++;
            $processed[] = $classified;
        }

        // `AttendingOnly` nunca mostra quem não frequenta — os três números
        // acima são calculados da mesma forma para os dois universos (M3),
        // só a lista de linhas devolvida é que difere.
        $rows = $universe === CohortUniverse::AttendingOnly ? $attending : array_merge($attending, $processed);

        return [
            'rows' => $rows,
            'not_attending_count' => count($notAttending),
            'not_attending_origins' => $origins,
            // `AttendingOnly` nunca mostra estes alunos, por isso nunca
            // "inclui" nenhum — o `$included` calculado é só o que
            // `AllClassStudents` de facto acrescenta a `rows`. `M3` só pede
            // `not_attending_without_result` correto nos dois universos, não
            // este.
            'external_included_count' => $universe === CohortUniverse::AttendingOnly ? 0 : $included,
            'not_attending_without_result' => $withoutResult,
        ];
    }

    /**
     * As linhas que contam para qualquer fração — numerador ou denominador.
     *
     * H1: quem não frequenta esta disciplina e não tem resultado externo
     * fica marcado (`period.participation_only`) mas continua visível em
     * `students[]`, rotulado. Esta é a ÚNICA regra que os retira de toda a
     * aritmética estatística — enunciada aqui uma vez, e usada por todos os
     * agregados que recebem o universo alargado.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function countableRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row): bool => ($row['period']['participation_only'] ?? false) !== true,
        ));
    }

    /**
     * O resultado externo compatível com este período — o próprio, ou o do ano
     * completo (`period_id` nulo) quando não há um mais específico — para cada
     * matrícula pedida. UMA SÓ CONSULTA para o período todo, nunca uma por
     * aluno.
     *
     * @param  array<string, mixed>  $period  a linha do período (id da
     *                                        AcademicPeriod, como a progressão a devolve)
     * @param  list<int>  $enrollmentIds
     * @return array<int, ExternalSubjectResult>
     */
    protected function externalResultsFor(SchoolClass $class, array $period, array $enrollmentIds): array
    {
        if ($enrollmentIds === [] || $class->assessment_profile_version_id === null) {
            return [];
        }

        // `external_subject_results.period_id` aponta para
        // `profile_version_periods`, não para `academic_periods` — a mesma
        // distinção que a migração documenta: o resultado externo prende-se ao
        // período TAL COMO O PERFIL DE AVALIAÇÃO EM VIGOR O DEFINE.
        $profilePeriodId = ProfileVersionPeriod::query()
            ->where('assessment_profile_version_id', $class->assessment_profile_version_id)
            ->where('academic_period_id', $period['id'])
            ->value('id');

        $results = ExternalSubjectResult::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->where(function ($query) use ($profilePeriodId): void {
                $query->whereNull('period_id');

                if ($profilePeriodId !== null) {
                    $query->orWhere('period_id', $profilePeriodId);
                }
            })
            ->get();

        $byEnrollment = [];

        foreach ($results as $result) {
            $id = (int) $result->enrollment_id;
            $current = $byEnrollment[$id] ?? null;

            // O do período concreto ganha ao do ano completo, quando os dois
            // existem para a mesma matrícula.
            if ($current === null || ($current->period_id === null && $result->period_id !== null)) {
                $byEnrollment[$id] = $result;
            }
        }

        return $byEnrollment;
    }

    /**
     * O nível da escala que este resultado externo corresponde — SÓ um
     * `scale_level_id` que pertença à ESCALA DESTA TURMA (req H4).
     *
     * NUNCA `level_code`: um código sem se saber a que escala pertence não
     * pode ser comparado ao código dos níveis desta escala sem adivinhar que
     * as duas escalas usam a mesma numeração — e um PLNM «4» não é um nível 4
     * desta disciplina só por coincidência de código.
     *
     * NUNCA um `scale_level_id` de outra escala: aceitá-lo colocaria o aluno
     * numa banda que a escala desta turma nunca decidiu.
     */
    protected function resolveExternalLevel(ExternalSubjectResult $external, ?Scale $scale): ?ScaleLevel
    {
        if ($external->scale_level_id === null || $scale === null) {
            return null;
        }

        return $scale->levels->first(
            fn (ScaleLevel $level): bool => (int) $level->id === (int) $external->scale_level_id,
        );
    }

    /**
     * O valor deste resultado externo, levado para o MESMO espaço normalizado
     * (0–100) que os resultados calculados já usam.
     *
     * NUNCA O `numeric_value` EM BRUTO (req H4). Sem a escala de origem
     * registada, um número não diz nada: um PLNM «4 em 5» e um teste «4 em
     * 20» são o mesmo `numeric_value` e significam coisas opostas — normalizar
     * contra a escala DESTA turma seria adivinhar a escala de origem e pôr
     * uma nota na boca de quem a atribuiu.
     *
     * SÓ QUANDO HÁ UM NÍVEL RESOLVIDO NESTA ESCALA (via `scale_level_id`): o
     * PONTO MÉDIO da sua banda normalizada — a única leitura numérica que um
     * nível já colocado nesta escala pode honestamente dar a uma média. Sem
     * isso, fica de fora da média, nunca coagido a zero (req 9).
     */
    protected function normalizedExternalValue(ExternalSubjectResult $external, ?Scale $scale, ?ScaleLevel $level): ?string
    {
        if ($level !== null && $level->band_min_normalized !== null && $level->band_max_normalized !== null) {
            return Bc::div(
                Bc::add(Bc::of((string) $level->band_min_normalized), Bc::of((string) $level->band_max_normalized)),
                '2',
            );
        }

        return null;
    }

    /**
     * Rotula uma matrícula que não frequenta com a classificação do seu
     * resultado externo — pela MESMA lógica de escala que qualquer outra
     * classificação já usa (`is_negative`, `bandFor()`), nunca uma segunda
     * noção de «passar» (req 2). NUNCA escrito em `student_overall_results`,
     * `student_domain_results` ou `calculation_snapshots`: isto é só a forma
     * de o ler numa estatística, a estrutura em memória nunca é gravada.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function withExternalClassification(array $row, ExternalSubjectResult $external, ?Scale $scale): array
    {
        $level = $this->resolveExternalLevel($external, $scale);
        $normalized = $this->normalizedExternalValue($external, $scale, $level);

        $row['period'] ??= [];
        $row['period']['classification'] = [
            'status' => 'confirmed',
            'final' => $this->bandPayload($level),
            // H2/H4: NUNCA o `numeric_value` em bruto, incondicionalmente —
            // mesmo quando um nível já foi resolvido. `final` acima já carrega
            // a colocação nesta escala; `final_value` é o que
            // `assignedValueDistribution()`/`assignedKeyOf()` usam para meter
            // um valor num BALDE ou numa BANDA desta escala, e a escala de
            // origem do número em bruto nunca é conhecida — um PLNM «4 em 5»
            // colocado num balde/banda de um 0–20 seria exatamente a
            // adivinhação de escala que este requisito proíbe (H2, corrige a
            // fuga que sobrevivia à primeira ronda).
            'final_value' => null,
            // H3: a proveniência TAMBÉM dentro de `classification`, não só no
            // `period.external` irmão — para que nenhum leitor deste array
            // (nem o Statistics.vue, nem um export futuro) possa apresentar
            // um resultado externo como se esta disciplina o tivesse
            // calculado. `status` continua «confirmed» de propósito: é o que
            // `assignedOutcomeOf()` precisa para continuar a funcionar.
            'external' => [
                'source' => 'external',
                'origin' => $external->origin,
            ],
        ];
        // Só entra na média/distribuição calculada quando há um valor
        // normalizado significativo — nunca o número em bruto (req 9).
        $row['period']['accumulated_average'] = $normalized;
        // A PROVENIÊNCIA, visível em quem consome esta linha (req 6/7): nunca
        // apresentado como se fosse evidência desta disciplina.
        $row['period']['external'] = [
            'source' => 'external',
            'origin' => $external->origin,
        ];

        return $row;
    }

    /**
     * «Esta análise por domínio não inclui N alunos avaliados em X, por não
     * existirem dados disponíveis para este domínio.» (req 3) — singular,
     * plural, várias proveniências unidas por «e», e o recurso sem inventar
     * uma proveniência quando nenhuma é conhecida.
     *
     * @param  list<string>  $origins
     */
    protected function domainExclusionNote(int $notAttendingCount, array $origins): ?string
    {
        if ($notAttendingCount === 0) {
            return null;
        }

        if ($origins === []) {
            $subject = $notAttendingCount === 1
                ? '1 aluno que não frequenta esta disciplina'
                : "{$notAttendingCount} alunos que não frequentam esta disciplina";

            return "Esta análise por domínio não inclui {$subject}, por não existirem dados disponíveis para este domínio.";
        }

        $subject = $notAttendingCount === 1 ? '1 aluno avaliado em' : "{$notAttendingCount} alunos avaliados em";

        return "Esta análise por domínio não inclui {$subject} {$this->joinOrigins($origins)}, por não existirem dados disponíveis para este domínio.";
    }

    /**
     * «Incluem-se N alunos avaliados em X, com base na classificação final
     * registada.» (req 12) — só quando o universo pedido é «toda a turma» e
     * pelo menos um resultado externo foi usado.
     *
     * @param  list<string>  $origins
     */
    protected function externalInclusionNote(int $includedCount, array $origins): ?string
    {
        if ($includedCount === 0) {
            return null;
        }

        if ($origins === []) {
            $subject = $includedCount === 1 ? '1 aluno' : "{$includedCount} alunos";

            return "Incluem-se {$subject}, com base na classificação final registada.";
        }

        $subject = $includedCount === 1 ? '1 aluno avaliado em' : "{$includedCount} alunos avaliados em";

        return "Incluem-se {$subject} {$this->joinOrigins($origins)}, com base na classificação final registada.";
    }

    /**
     * §6 da decisão do product owner: «Alguns alunos frequentaram a
     * disciplina apenas durante parte do período. Os resultados obtidos
     * durante esse intervalo são considerados nas análises
     * correspondentes.» — NEUTRA, SEM NOME DE PRODUTO NENHUM, e só quando
     * pelo menos um aluno desta análise frequentou de facto só PARTE do
     * período (nunca quando um aluno não frequentou o período nenhum, que já
     * tem a sua própria nota em `domainExclusionNote()`).
     *
     * M1c: A CONSULTA DO PERÍODO NUNCA CORRE NO CASO COMUM — uma turma sem
     * nenhuma matrícula marcada como não-frequentante à data de referência
     * não pode ter janela nenhuma que se sobreponha ao período, e o
     * invariante de consultas do ficheiro (ver docblock da classe) exige que
     * esse caso continue a não pagar nenhuma consulta extra.
     *
     * M1b: FREQUÊNCIA PARCIAL = QUALQUER JANELA DE NÃO-FREQUÊNCIA QUE SE
     * SOBREPONHA AO PERÍODO, aberta OU fechada dentro dele — lida sobre
     * `ClassCohort::windowsFor()`, nunca só sobre a janela em vigor à data de
     * referência (`participation.since`). Um aluno cuja janela abriu a 10/01
     * e FECHOU a 20/02 (voltou a meio do período) teve frequência
     * genuinamente parcial e tinha ficado sem nota nenhuma antes desta
     * correção, porque `participation` (a data de referência) já não a via.
     *
     * M1d: NUNCA emitida para um aluno sem NENHUMA evidência neste período —
     * a frase afirma que resultados «são considerados», e não há nada a
     * considerar quando não existe nenhum.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $selected
     * @param  list<array{id: int, name: string}>  $domains
     */
    protected function partialPeriodAttendanceNote(array $rows, array $selected, array $domains): ?string
    {
        // TODOS OS ALUNOS SÃO CANDIDATOS, e não só os que não frequentam À
        // DATA DE REFERÊNCIA.
        //
        // Semear esta lista a partir de `period.participation` era o que
        // tornava o caso da RE-ENTRADA inalcançável: uma janela que abriu a 10
        // de janeiro e FECHOU a 20 de fevereiro, dentro de um período que
        // termina a 31 de março, deixa `participation` a null à data de
        // referência — o aluno voltou a frequentar — e a frequência dele foi,
        // ainda assim, genuinamente parcial. É precisamente o caso que esta
        // nota existe para dizer.
        //
        // Quem decide é `windowsFor()`, que carrega TODAS as janelas (abertas
        // e fechadas); um aluno sem janela nenhuma sai no `continue` abaixo
        // sem custo. A consulta é uma só para a turma inteira.
        $enrollmentIds = array_map(
            static fn (array $row): int => (int) $row['enrollment_id'],
            $rows,
        );

        if ($enrollmentIds === []) {
            return null;
        }

        $windows = ClassCohort::windowsFor(collect($enrollmentIds));

        if ($windows->isEmpty()) {
            // O caso esmagadoramente comum: nenhuma turma com zero janelas
            // paga a consulta do período abaixo.
            return null;
        }

        $period = AcademicPeriod::query()->whereKey($selected['id'])->first(['starts_on', 'ends_on']);

        if ($period === null) {
            return null;
        }

        $startsOn = $period->starts_on->toDateString();
        $endsOn = $period->ends_on->toDateString();

        $byEnrollment = [];

        foreach ($rows as $row) {
            $byEnrollment[(int) $row['enrollment_id']] = $row;
        }

        foreach ($enrollmentIds as $enrollmentId) {
            $enrollmentWindows = $windows->get($enrollmentId);

            if ($enrollmentWindows === null || $enrollmentWindows->isEmpty()) {
                continue;
            }

            $row = $byEnrollment[$enrollmentId] ?? null;

            if ($row === null) {
                continue;
            }

            // M1d: sem evidência nenhuma neste período, a frase não tem nada
            // a que se referir.
            if (($row['period']['weighted_average'] ?? null) === null
                && ! $this->rowHasDomainEvidence($row, $domains)) {
                continue;
            }

            foreach ($enrollmentWindows as $window) {
                $from = $window->effective_from->toDateString();
                $until = $window->effective_until?->toDateString();

                // A janela abriu DENTRO do período (a matrícula frequentou a
                // primeira parte e deixou de frequentar), ou fechou DENTRO
                // dele (voltou a meio do período, M1b). Uma janela que já
                // estava aberta antes do período começar E que não fechou
                // dentro dele não é frequência parcial DESTE período — é
                // simplesmente não o ter frequentado nenhum.
                // `$until < $endsOn`, E NÃO `<=`, DE PROPÓSITO.
                // `effective_until` é INCLUSIVO — é o último dia em que o
                // aluno NÃO frequentou (ver `ClassCohort::wasAttendingOn()`,
                // que o lê com `>= $on`) — logo o aluno regressa em
                // `until + 1`. Para que o regresso caia dentro deste período é
                // preciso `until + 1 <= endsOn`, que é exatamente
                // `until < endsOn`. Com `<=`, uma janela que fecha no próprio
                // último dia do período contaria como frequência parcial dele,
                // quando na verdade o aluno só regressou depois de o período
                // acabar — não frequentou dia nenhum.
                $opensDuringPeriod = $from > $startsOn && $from <= $endsOn;
                $closesDuringPeriod = $until !== null && $until >= $startsOn && $until < $endsOn;

                if ($opensDuringPeriod || $closesDuringPeriod) {
                    return 'Alguns alunos frequentaram a disciplina apenas durante parte do período. '
                        .'Os resultados obtidos durante esse intervalo são considerados nas análises correspondentes.';
                }
            }
        }

        return null;
    }

    /**
     * «PLNM» sozinho, ou «PLNM e Espanhol» quando há mais que uma proveniência.
     *
     * @param  list<string>  $origins
     */
    protected function joinOrigins(array $origins): string
    {
        if (count($origins) <= 1) {
            return $origins[0] ?? '';
        }

        $last = array_pop($origins);

        return implode(', ', $origins).' e '.$last;
    }

    /**
     * The chosen figure of a student's period row.
     *
     * @param  array<string, mixed>  $row
     */
    protected function primaryValueOf(array $row, string $kind): ?string
    {
        return $kind === 'accumulated'
            ? ($row['period']['accumulated_average'] ?? null)
            : ($row['period']['weighted_average'] ?? null);
    }

    /**
     * The other one — null when the two would be the same number.
     *
     * @param  array<string, mixed>  $row
     */
    protected function supplementaryValueOf(array $row, string $kind): ?string
    {
        return $kind === 'accumulated' ? ($row['period']['weighted_average'] ?? null) : null;
    }

    /**
     * What the page should call the two readings, decided once and server-side.
     *
     * @param  array<int, string>  $scopes
     * @param  list<array<string, mixed>>  $periods
     * @param  array<string, mixed>  $selected
     * @return array<string, mixed>
     */
    protected function primaryPayload(string $kind, array $scopes, array $periods, array $selected): array
    {
        $continuous = $kind === 'accumulated';

        return [
            'kind' => $kind,
            'label' => $continuous ? 'Média Ponderada Acumulada' : 'Média Ponderada',
            'short_label' => $continuous ? 'Média acumulada da turma' : 'Média da turma',
            'caption' => $continuous
                ? 'Resultado acumulado · avaliação contínua'
                : "Resultado do {$selected['label']}",
            'supplementary_label' => $continuous ? "Só no {$selected['label']}" : null,
            // Whether there is a second reading at all. At the first moment the
            // two figures are the same number and showing both would invent a
            // distinction the data does not have.
            'has_supplementary' => $continuous,
            // Every period of the year and which figure answers for it, so the
            // longitudinal readings can follow the same rule.
            'scopes' => $scopes,
        ];
    }

    /**
     * The period under analysis, and its row.
     *
     * With none asked for, the LAST period that any student has a standalone
     * result in — the one a teacher is working on. Falling back to the first
     * would open the year on a period that closed months ago.
     *
     * @param  list<array<string, mixed>>  $periods
     * @param  list<array<string, mixed>>  $students
     * @return array<string, mixed>|null
     */
    protected function selectedPeriod(array $periods, array $students, ?AcademicPeriod $period): ?array
    {
        if ($periods === []) {
            return null;
        }

        if ($period !== null) {
            foreach ($periods as $row) {
                if ($row['id'] === $period->id) {
                    return $row;
                }
            }
        }

        $latest = null;

        foreach ($periods as $row) {
            if ($this->valuesOf($this->rowsFor($students, $row['id']), 'weighted_average') !== []) {
                $latest = $row;
            }
        }

        // A year that has not started anywhere opens on its first period, which
        // is the only honest place to be standing.
        return $latest ?? $periods[0];
    }

    /**
     * @param  list<array<string, mixed>>  $periods
     * @return array<string, mixed>|null
     */
    protected function previousPeriod(array $periods, int $selectedId): ?array
    {
        $previous = null;

        foreach ($periods as $row) {
            if ($row['id'] === $selectedId) {
                return $previous;
            }

            $previous = $row;
        }

        return null;
    }

    /**
     * One row per student: their own period entry, lifted out of the
     * progression exactly as it was written there.
     *
     * @param  list<array<string, mixed>>  $students
     * @return list<array<string, mixed>>
     */
    protected function rowsFor(array $students, int $periodId): array
    {
        $rows = [];

        foreach ($students as $student) {
            $entry = null;

            foreach ($student['periods'] as $candidate) {
                if ($candidate['period_id'] === $periodId) {
                    $entry = $candidate;

                    break;
                }
            }

            $rows[] = [
                'enrollment_id' => $student['enrollment_id'],
                'name' => $student['name'],
                'class_number' => $student['class_number'],
                'period' => $entry,
            ];
        }

        return $rows;
    }

    /**
     * The headline figures.
     *
     * `class_average` and `accumulated_average` answer two different questions
     * and are both given a name on screen, because «a média da turma» is
     * ambiguous the moment a year has more than one period (§7).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function summary(array $rows, ?Scale $scale, string $primaryKind = 'period', ?int $domainStudentsTotal = null): array
    {
        $rows = $this->countableRows($rows);
        $standalone = $this->valuesOf($rows, 'weighted_average');
        $accumulated = $this->valuesOf($rows, 'accumulated_average');

        // M2: um aluno incluído por resultado externo TEM um resultado — só
        // não é uma `weighted_average`, porque esta disciplina não a
        // calculou. Contá-lo em `students_without_result` (que antes desta
        // correção olhava só para `weighted_average`) dizia «ainda sem
        // qualquer elemento avaliado» de alguém precisamente incluído porque
        // tem um. Sem fabricar a média que falta (req 9): fica de fora dos
        // dois lados da conta, nem com resultado nem sem ele.
        $withoutExternal = array_values(array_filter(
            $rows,
            fn (array $row): bool => ($row['period']['external'] ?? null) === null,
        ));
        $withoutResult = count($withoutExternal) - count($this->valuesOf($withoutExternal, 'weighted_average'));

        $partial = 0;

        foreach ($rows as $row) {
            // A VALUE THAT EXISTS AND RESTS ON LESS THAN EVERYTHING EXPECTED.
            //
            // The engine raises the same flag for two different situations: a
            // result built on part of the evidence, and no result at all because
            // nothing could produce one. Counting both as «informação parcial»
            // would tell a teacher that every student in a class that has not
            // been assessed yet has a partial result, which is the opposite of
            // what happened. Without a value there is nothing for the coverage to
            // be partial OF — those students are counted as `without_result`.
            if (($row['period']['coverage_warning'] ?? false) === true
                && ($row['period']['weighted_average'] ?? null) !== null) {
                $partial++;
            }
        }

        return [
            'students_total' => count($rows),
            // M1: o denominador PRÓPRIO do bloco «por domínio», que não é o
            // `students_total` acima.
            //
            // JÁ NÃO É «quem frequenta à data de referência». Desde a decisão
            // do product owner, `domainStatistics()` recebe a progressão
            // COMPLETA e decide aluno a aluno PELOS DADOS que existem — uma
            // evidência produzida enquanto o aluno frequentava continua dele
            // (pergunta (A)), mesmo que a janela de não-frequência tenha
            // aberto depois e mesmo que, à data de referência, ele já não
            // entre no balanço final (pergunta (B)). Os dois factos são
            // verdadeiros ao mesmo tempo, de propósito.
            //
            // Este número conta, exatamente, os alunos que TÊM pelo menos uma
            // célula de domínio nesta leitura — não «os que poderiam ter
            // tido», que seria outro número e levaria a outra correção. É o
            // denominador que a linha do Vue usa, para nunca dividir por um
            // universo que os domínios não usaram.
            'domain_students_total' => $domainStudentsTotal ?? count($rows),
            'students_with_result' => count($standalone),
            'students_without_result' => $withoutResult,
            'class_average' => $this->mean($standalone),
            'accumulated_average' => $this->mean($accumulated),
            // THE ANSWER, and the other one. Both are already above under their
            // own names; these two say which is which at this moment, so no
            // screen has to re-derive the rule (§2).
            'primary_average' => $primaryKind === 'accumulated'
                ? $this->mean($accumulated)
                : $this->mean($standalone),
            'supplementary_average' => $primaryKind === 'accumulated'
                ? $this->mean($standalone)
                : null,
            'partial_coverage_count' => $partial,
            'most_common_band' => $this->mostCommonBand($rows, $scale),
            'success' => $this->success($rows, $scale),
        ];
    }

    /**
     * «Quantos alunos tiveram classificação positiva atribuída?»
     *
     * THE OFFICIAL RATE, AND THEREFORE THE TEACHER'S OWN DECISIONS. This is the
     * number a conselho de turma quotes, so it counts grades that were actually
     * given — not the mentions the averages happen to land on. A student whose
     * accumulated figure sits in «Bom» and whose teacher wrote «2» is a
     * negative, and a rate that said otherwise would be reporting something
     * nobody decided (§13, §25).
     *
     * The statistical reading has not gone anywhere: «Como se distribuem os
     * resultados» still bands the Média Ponderada Acumulada, and says so on
     * screen. The two are kept apart rather than averaged into one number that
     * means neither (§4).
     *
     * WHICH SIDE A DECISION IS ON IS THE SCALE'S STATEMENT, through
     * `is_negative`. Hard-coding «>= 50%» or «>= 10» would invent a pedagogical
     * rule and would be wrong the moment a school configures its own scale.
     *
     * THREE GROUPS, AND ONLY ONE DENOMINATOR:
     *
     *  - placed: a student with a decision whose side the scale can state.
     *    Those and only those are counted for or against the rate;
     *  - unplaced: decided, but on a scale that has no statement about that
     *    decision. Counting them either way would answer a question nobody
     *    asked the scale;
     *  - without_classification: nothing decided yet, or still only a proposal.
     *    Never a failure (§11): not having been graded is not a bad grade, and
     *    it stays out of the denominator entirely.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function success(array $rows, ?Scale $scale): array
    {
        $rows = $this->countableRows($rows);
        $succeeded = 0;
        $failed = 0;
        $unplaced = 0;
        $withoutClassification = 0;

        foreach ($rows as $row) {
            match ($this->assignedOutcomeOf($row, $scale)) {
                'positive' => $succeeded++,
                'negative' => $failed++,
                'unclassified' => $unplaced++,
                default => $withoutClassification++,
            };
        }

        $placed = $succeeded + $failed;

        return [
            'succeeded' => $succeeded,
            'failed' => $failed,
            // Decided, but on a scale that states nothing about that decision.
            'unplaced' => $unplaced,
            'without_classification' => $withoutClassification,
            // The denominator, stated so a screen never has to guess it.
            'placed' => $placed,
            'rate' => $this->percentage($succeeded, $placed),
            'failure_rate' => $this->percentage($failed, $placed),
        ];
    }

    /**
     * How many students moved, and by how much on average.
     *
     * Read from the evolution the progression already decided. «Sem comparação»
     * is its own answer and never folded into «manteve-se»: a student with no
     * previous period did not stand still, they have nothing to stand against
     * (§37).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $previousRows
     * @return array<string, mixed>
     */
    protected function evolution(array $rows, array $previousRows = [], ?Scale $scale = null): array
    {
        $rows = $this->countableRows($rows);
        $previousRows = $this->countableRows($previousRows);

        // M5: a mesma guarda que `transitionOf()`/`continuousMovementOf()` já
        // aplicam, aqui em falta antes desta correção. Um resultado externo é
        // uma classificação única, nunca uma trajetória — sem esta exclusão
        // essas linhas caíam sempre em `no_comparison` (o seu `evolution` é
        // sempre null) e inflacionavam esse balde e o denominador de todas as
        // percentagens, encolhendo progrediu/manteve-se/regrediu sem que
        // ninguém tivesse deixado de progredir, manter-se ou regredir.
        $rows = array_values(array_filter(
            $rows,
            fn (array $row): bool => ($row['period']['external'] ?? null) === null,
        ));
        $counts = ['progressed' => 0, 'stable' => 0, 'regressed' => 0, 'no_comparison' => 0];
        $changes = [];

        foreach ($rows as $row) {
            $evolution = $row['period']['evolution'] ?? null;

            if ($evolution === null) {
                $counts['no_comparison']++;

                continue;
            }

            $counts[match ($evolution['direction']) {
                'up' => 'progressed',
                'down' => 'regressed',
                default => 'stable',
            }]++;

            $changes[] = (string) $evolution['points'];
        }

        $comparable = count($changes);

        return [
            ...$counts,
            'comparable' => $comparable,
            // Percentage POINTS, and only over the students who had two periods
            // to compare. Averaging over the whole class would dilute the
            // movement with students who did not move because they could not.
            'average_change' => $this->mean($changes),
            'percentages' => [
                'progressed' => $this->percentage($counts['progressed'], count($rows)),
                'stable' => $this->percentage($counts['stable'], count($rows)),
                'regressed' => $this->percentage($counts['regressed'], count($rows)),
                'no_comparison' => $this->percentage($counts['no_comparison'], count($rows)),
            ],
            'transitions' => $this->transitions($rows, $previousRows, $scale),
        ];
    }

    /**
     * How the CONTINUOUS assessment moved — a second reading, never the same
     * field as the first.
     *
     * `evolution` above compares this period's own work against the last
     * period's own work, which is what BuildResultsProgression has always
     * meant by the word and what it still means. That answers «este período
     * correu melhor que o anterior?».
     *
     * This one compares the RESULT OF THE MOMENT at each end — the figure that
     * actually answers «como está o aluno» there. A student who scored 70 in
     * the first period and 50 in the second fell twenty points as a period and
     * five as a year, and both sentences are true. They are given different
     * names because they are different facts, and one may never be quietly
     * substituted for the other (§13, §14, §45).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $previousRows
     * @return array<string, mixed>
     */
    protected function continuousEvolution(
        array $rows,
        array $previousRows,
        string $kind,
        ?string $previousKind,
    ): array {
        $rows = $this->countableRows($rows);
        $previousRows = $this->countableRows($previousRows);

        $before = [];

        foreach ($previousRows as $row) {
            $before[(int) $row['enrollment_id']] = $row;
        }

        $counts = ['progressed' => 0, 'stable' => 0, 'regressed' => 0, 'no_comparison' => 0];
        $changes = [];

        foreach ($rows as $row) {
            $movement = $this->continuousMovementOf(
                $row,
                $before[(int) $row['enrollment_id']] ?? null,
                $kind,
                $previousKind,
            );

            if ($movement === null) {
                $counts['no_comparison']++;

                continue;
            }

            $counts[match ($movement['direction']) {
                'up' => 'progressed',
                'down' => 'regressed',
                default => 'stable',
            }]++;

            $changes[] = $movement['points'];
        }

        return [
            ...$counts,
            'comparable' => count($changes),
            'average_change' => $this->mean($changes),
            'percentages' => [
                'progressed' => $this->percentage($counts['progressed'], count($rows)),
                'stable' => $this->percentage($counts['stable'], count($rows)),
                'regressed' => $this->percentage($counts['regressed'], count($rows)),
                'no_comparison' => $this->percentage($counts['no_comparison'], count($rows)),
            ],
        ];
    }

    /**
     * One student's movement between the two moments' own results.
     *
     * Rounded and compared at the precision the page shows, so «igual» on
     * screen is «manteve-se» here — the same rule BuildResultsProgression uses
     * for its own subtraction, applied to a different pair of numbers.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $previousRow
     * @return array{direction: string, points: string}|null
     */
    protected function continuousMovementOf(
        array $row,
        ?array $previousRow,
        string $kind,
        ?string $previousKind,
    ): ?array {
        if ($previousRow === null || $previousKind === null) {
            return null;
        }

        // H2: um resultado externo é uma classificação única, nunca uma
        // trajetória — comparar contra si mesmo (ou contra o período
        // anterior) fabricaria um movimento que ninguém observou.
        if (($row['period']['external'] ?? null) !== null || ($previousRow['period']['external'] ?? null) !== null) {
            return null;
        }

        $from = $this->primaryValueOf($previousRow, $previousKind);
        $to = $this->primaryValueOf($row, $kind);

        // A missing end is «nothing to compare», never a fall to zero (§13.3).
        if ($from === null || $to === null) {
            return null;
        }

        $before = Bc::round(Bc::of($from), self::PRECISION, 'half_up');
        $after = Bc::round(Bc::of($to), self::PRECISION, 'half_up');
        $comparison = Bc::compare($after, $before);

        return [
            'direction' => match (true) {
                $comparison > 0 => 'up',
                $comparison < 0 => 'down',
                default => 'flat',
            },
            'points' => Bc::round(Bc::sub($after, $before), self::PRECISION, 'half_up'),
        ];
    }

    /**
     * Who changed SIDE of the scale, which is not the same as who moved.
     *
     * A student going from 62% to 68% progressed and stayed exactly where they
     * were pedagogically; one going from 48% to 53% crossed the line the school
     * actually cares about. The two readings answer different questions and the
     * section shows both rather than letting one stand for the other.
     *
     * THE SIDE IS THE TEACHER'S DECISION, read through the scale's own
     * `is_negative`. There is no second engine for positive/negative here and
     * no threshold written anywhere in this file (§2, §4).
     *
     * THE MOVEMENT ABOVE READS THE CALCULATED RESULT AND THIS READS THE
     * ASSIGNED CLASSIFICATION, deliberately and with different denominators:
     * «subiu nove pontos» is arithmetic about evidence, «passou a positivo» is
     * a decision somebody took. A student can do the first without the second
     * and the second without the first.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $previousRows
     * @return array<string, mixed>
     */
    protected function transitions(array $rows, array $previousRows, ?Scale $scale): array
    {
        $rows = $this->countableRows($rows);
        $previousRows = $this->countableRows($previousRows);

        $before = [];

        foreach ($previousRows as $row) {
            $before[(int) $row['enrollment_id']] = $row;
        }

        $counts = [
            'failure_to_success' => 0,
            'success_to_failure' => 0,
            'success_to_success' => 0,
            'failure_to_failure' => 0,
            'unclassified' => 0,
            'no_assigned_classification' => 0,
        ];

        foreach ($rows as $row) {
            $counts[$this->transitionOf($row, $before[(int) $row['enrollment_id']] ?? null, $scale)]++;
        }

        // THE DENOMINATOR IS STUDENTS WITH A DECISION AT BOTH ENDS whose side
        // the scale can state — not students with two averages, and not the
        // class. Somebody the teacher has not graded yet did not «stay»
        // anywhere, and counting them either way would invent a grade (§11).
        $comparable = $counts['failure_to_success'] + $counts['success_to_failure']
            + $counts['success_to_success'] + $counts['failure_to_failure'];

        return [
            ...$counts,
            'comparable' => $comparable,
            'percentages' => [
                'failure_to_success' => $this->percentage($counts['failure_to_success'], $comparable),
                'success_to_failure' => $this->percentage($counts['success_to_failure'], $comparable),
                'success_to_success' => $this->percentage($counts['success_to_success'], $comparable),
                'failure_to_failure' => $this->percentage($counts['failure_to_failure'], $comparable),
            ],
            // These two sit OUTSIDE that denominator, so their share is of the
            // class — said in its own key rather than mixed into the one above.
            'share_of_class' => [
                'unclassified' => $this->percentage($counts['unclassified'], count($rows)),
                'no_assigned_classification' => $this->percentage($counts['no_assigned_classification'], count($rows)),
            ],
        ];
    }

    /**
     * Which side of the scale the TEACHER put this student on, and which side
     * they are on now.
     *
     * THE DECISION, NOT THE ARITHMETIC. Passing and failing are things a
     * teacher decides, and Lapispro keeps four different statements about a
     * student deliberately apart (§14): the Média Ponderada is what was
     * calculated, the proposal is what the system suggested, the self
     * assessment is what the student said, and the classification is what the
     * teacher decided. Only the last one is a grade. A student whose average
     * lands in «Bom» and whose teacher wrote «2» has a negative classification,
     * and this must say so.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $previousRow
     */
    protected function transitionOf(array $row, ?array $previousRow, ?Scale $scale): string
    {
        if ($previousRow === null) {
            return 'no_assigned_classification';
        }

        // H2: sem trajetória para um resultado externo — nem no lado atual
        // nem no anterior.
        if (($row['period']['external'] ?? null) !== null || ($previousRow['period']['external'] ?? null) !== null) {
            return 'no_assigned_classification';
        }

        $before = $this->assignedOutcomeOf($previousRow, $scale);
        $after = $this->assignedOutcomeOf($row, $scale);

        // NOTHING WAS DECIDED AT ONE OF THE ENDS. There is no official crossing
        // to report, and filling the gap from the average or the proposal would
        // be putting a grade in the teacher's mouth (§7, §3.3).
        if ($before === 'none' || $after === 'none') {
            return 'no_assigned_classification';
        }

        // Decided, but on a scale that cannot say which side that decision is.
        if ($before === 'unclassified' || $after === 'unclassified') {
            return 'unclassified';
        }

        return match (true) {
            $before === 'negative' && $after === 'positive' => 'failure_to_success',
            $before === 'positive' && $after === 'negative' => 'success_to_failure',
            $before === 'negative' => 'failure_to_failure',
            default => 'success_to_success',
        };
    }

    /**
     * Whether the teacher actually decided this student's period.
     *
     * ONLY A DECISION COUNTS. `proposed` means they have not answered yet;
     * `superseded` is not the live one. The progression already forces a
     * post-cutoff confirmation back to `proposed`, so a page read at a date
     * cannot see a grade written after it.
     *
     * @param  array<string, mixed>  $row
     */
    protected function hasAssignedClassification(array $row): bool
    {
        $classification = $row['period']['classification'] ?? null;

        if ($classification === null) {
            return false;
        }

        if (! in_array($classification['status'] ?? null, ['confirmed', 'published'], true)) {
            return false;
        }

        return ($classification['final'] ?? null) !== null
            || ($classification['final_value'] ?? null) !== null;
    }

    /**
     * The band the teacher's own decision names — the grade, as a mention.
     *
     * On a levelled scale this IS the decision: the level they chose, carried
     * out of the canonical read model with its own identity. On a numeric one
     * the decision is a bare number, and only the scale's own bands may name
     * it; a scale without them gets null rather than an invented interval.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    protected function assignedLevelOf(array $row, ?Scale $scale): ?array
    {
        if (! $this->hasAssignedClassification($row)) {
            return null;
        }

        $classification = $row['period']['classification'];
        $final = $classification['final'] ?? null;

        if ($final !== null) {
            return $final;
        }

        return $this->bandPayload($this->bandForNumericDecision($classification['final_value'] ?? null, $scale));
    }

    /**
     * Which group of the assigned distribution this student is in.
     *
     * A levelled scale groups by the level, a numeric one by the number the
     * teacher wrote — the same rule the distribution itself uses, so a
     * selection can never point at a group the grid does not draw (§2.12).
     *
     * @param  array<string, mixed>  $row
     */
    protected function assignedKeyOf(array $row, ?Scale $scale): ?string
    {
        if (! $this->hasAssignedClassification($row)) {
            return null;
        }

        if ($scale === null || $scale->kind === 'level') {
            $level = $this->assignedLevelOf($row, $scale);

            return $level === null ? null : (string) $level['scale_level_id'];
        }

        $classification = $row['period']['classification'];
        $value = $classification['final_value'] ?? null;

        if ($value === null || trim((string) $value) === '') {
            $value = $classification['final']['code'] ?? null;
        }

        return $value === null ? null : $this->trimZeros((string) $value);
    }

    /**
     * The side of the scale the teacher's own decision falls on.
     *
     * «none» — nothing was decided for this period, or what exists is still a
     * proposal. «unclassified» — a decision exists, but the scale has no
     * statement about which side it is; saying nothing is the correct answer
     * (§10.4).
     *
     * @param  array<string, mixed>  $row
     * @return 'positive'|'negative'|'unclassified'|'none'
     */
    protected function assignedOutcomeOf(array $row, ?Scale $scale): string
    {
        if (! $this->hasAssignedClassification($row)) {
            return 'none';
        }

        $level = $this->assignedLevelOf($row, $scale);

        if ($level === null) {
            return 'unclassified';
        }

        return ($level['is_negative'] ?? false) ? 'negative' : 'positive';
    }

    /**
     * A decision written as a bare number, placed on the scale's own bands.
     *
     * ONLY THE SCALE'S OWN BANDS MAY ANSWER. A numeric scale that was never
     * given qualitative bands has no statement about where passing begins, and
     * a «>= 10» written here would be inventing a pedagogical rule (§1, §11).
     *
     * When it does have bands, they live in normalized space, so the teacher's
     * number is placed back onto that axis with the inverse of the one approved
     * placement rule — `min + (normalized/100) × (max − min)` — applied to the
     * DECISION and never to a computed result.
     */
    protected function bandForNumericDecision(?string $value, ?Scale $scale): ?ScaleLevel
    {
        if ($value === null || trim($value) === '' || $scale === null) {
            return null;
        }

        if ($scale->levels->isEmpty() || $scale->min_value === null || $scale->max_value === null) {
            return null;
        }

        $span = Bc::sub(Bc::of((string) $scale->max_value), Bc::of((string) $scale->min_value));

        if (Bc::compare($span, '0') === 0) {
            return null;
        }

        return $this->proposals->bandFor($scale, Bc::mul(
            Bc::div(Bc::sub(Bc::of($value), Bc::of((string) $scale->min_value)), $span),
            '100',
        ));
    }

    /**
     * How the class falls across the scale's own bands.
     *
     * The band of a student is read through ScaleProposalResolver — the one
     * service that turns a normalized value into a band, and the very same call
     * the Quadro Síntese makes for each domain's mention. Nothing here decides
     * a threshold.
     *
     * Every band of the scale appears, including the ones nobody is in: a level
     * with zero students is a fact about the class, and omitting it would draw
     * a different chart for every class (§49).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    /**
     * «Quantos alunos tiveram nível 2?» — the grades, counted.
     *
     * THE ANSWER A PAUTA GIVES. The distribution beside it bands the calculated
     * average and answers a different question; both are on the page and each
     * says which it is, because a class where one student was given a «2» and
     * whose averages all land in «Suficiente» is described truthfully by
     * neither on its own (§1).
     *
     * EVERY BAND OF THE SCALE GETS A ROW, in the scale's own sequence and with
     * the scale's own words, including the ones nobody is in — «Nível 2: 0» is
     * an answer and a missing row is not. Nothing here knows what a «3» is: a
     * school using «Não atingiu / Atingiu / Superou» gets exactly those.
     *
     * A band that no longer belongs to the scale but that somebody was graded
     * on still gets its row, at the end: their history is true even if the
     * scale has moved on.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function assignedDistribution(array $rows, ?Scale $scale): array
    {
        $rows = $this->countableRows($rows);

        // A NUMERIC SCALE IS DISTRIBUTED BY THE NUMBER THE TEACHER WROTE.
        //
        // «Insuficiente: 3» is not the answer to «quantos tiveram 8?» — on a
        // 0–20 the classification IS the number, and grouping it into the
        // scale's bands would be answering with the mention instead of with
        // the grade. A levelled scale needs no such translation: its levels ARE
        // the values a teacher assigns (§2.1, §2.4).
        if ($scale !== null && $scale->kind !== 'level') {
            return $this->assignedValueDistribution($rows, $scale);
        }

        $counts = [];
        $seen = [];
        $classified = 0;
        $unplaced = 0;
        $withoutClassification = 0;

        foreach ($rows as $row) {
            if (! $this->hasAssignedClassification($row)) {
                $withoutClassification++;

                continue;
            }

            $level = $this->assignedLevelOf($row, $scale);

            // Graded, on a scale that cannot name it. Counted apart rather than
            // dropped into a band it was never placed in.
            if ($level === null) {
                $unplaced++;

                continue;
            }

            $id = (int) $level['scale_level_id'];
            $counts[$id] = ($counts[$id] ?? 0) + 1;
            $seen[$id] = $level;
            $classified++;
        }

        $bands = [];
        $levels = $scale === null ? collect() : $scale->levels->sortBy('sequence');

        foreach ($levels as $level) {
            $id = (int) $level->id;
            unset($seen[$id]);

            $bands[] = [
                'scale_level_id' => $id,
                // The selection key: a level id here, the value itself on a
                // numeric scale — one string either way (§2.12).
                'key' => (string) $id,
                'code' => (string) $level->code,
                'label' => (string) $level->label,
                'sequence' => (int) $level->sequence,
                'is_negative' => (bool) $level->is_negative,
                'count' => $counts[$id] ?? 0,
                // Of the students actually graded. Dividing by the whole class
                // would let students nobody has classified yet silently shrink
                // every band without appearing anywhere.
                'percentage' => $this->percentage($counts[$id] ?? 0, $classified),
            ];
        }

        foreach ($seen as $id => $level) {
            $bands[] = [
                'scale_level_id' => (int) $id,
                'key' => (string) $id,
                'code' => (string) $level['code'],
                'label' => (string) $level['label'],
                'sequence' => (int) $level['sequence'],
                'is_negative' => (bool) $level['is_negative'],
                'count' => $counts[$id],
                'percentage' => $this->percentage($counts[$id], $classified),
                // The scale no longer has this band; the grade still happened.
                'outside_scale' => true,
            ];
        }

        return [
            'bands' => $bands,
            // The denominator, stated so a screen never has to guess it.
            'mode' => 'levels',
            'classified' => $classified,
            'unplaced' => $unplaced,
            'without_classification' => $withoutClassification,
        ];
    }

    /**
     * The numbers the teacher actually wrote, counted.
     *
     * ONE ROW PER VALUE ASSIGNED, and only for values somebody was given: a
     * 0–20 has twenty-one possible grades and a class of six uses five of them,
     * so listing all twenty-one would be twenty-one mostly-empty rows. Nothing
     * is grouped into ranges either — «10 a 13» is a band this scale may not
     * have, and inventing one would be inventing a pedagogical rule (§2.4).
     *
     * The band's own words ride along when the scale has one for that value,
     * as a mention beside the grade rather than in place of it.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function assignedValueDistribution(array $rows, Scale $scale): array
    {
        $counts = [];
        $mentions = [];
        $classified = 0;
        $withoutClassification = 0;

        foreach ($rows as $row) {
            if (! $this->hasAssignedClassification($row)) {
                $withoutClassification++;

                continue;
            }

            $value = $row['period']['classification']['final_value'] ?? null;

            // A decision recorded only as a level on a scale that is not
            // levelled: its own code is the closest thing to a number.
            if ($value === null || trim((string) $value) === '') {
                $value = $row['period']['classification']['final']['code'] ?? null;
            }

            if ($value === null) {
                $withoutClassification++;

                continue;
            }

            $key = $this->trimZeros((string) $value);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $mentions[$key] ??= $this->bandPayload($this->bandForNumericDecision((string) $value, $scale));
            $classified++;
        }

        // The scale's own order, which for numbers is the numbers' own —
        // never by how many students happen to be on each (§2.22).
        uksort($counts, fn (string $first, string $second): int => is_numeric($first) && is_numeric($second)
            ? $first <=> $second
            : strcmp($first, $second));

        $entries = [];
        $sequence = 0;

        foreach ($counts as $key => $count) {
            $entries[] = [
                // No level to point at: the value itself identifies the group.
                // Cast because PHP turns «8» into an integer array key.
                'scale_level_id' => $mentions[$key]['scale_level_id'] ?? null,
                'key' => (string) $key,
                'code' => (string) $key,
                'label' => $mentions[$key]['label'] ?? null,
                'sequence' => $sequence++,
                'is_negative' => $mentions[$key]['is_negative'] ?? null,
                'count' => $count,
                'percentage' => $this->percentage($count, $classified),
            ];
        }

        return [
            'bands' => $entries,
            'mode' => 'values',
            'classified' => $classified,
            // Every assigned value gets a row here, so nothing is «unplaced»:
            // not having a band is a missing mention, not a missing grade.
            'unplaced' => 0,
            'without_classification' => $withoutClassification,
        ];
    }

    /**
     * Where the CALCULATED averages land, across the scale's bands.
     *
     * The secondary reading, and named as such on screen: it answers «onde está
     * a turma» and not «que notas foram dadas». Kept because it is genuinely
     * useful before a class is graded, and because the gap between the two is
     * itself worth seeing (§7).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function distribution(array $rows, ?Scale $scale): array
    {
        $rows = $this->countableRows($rows);

        if ($scale === null || $scale->levels->isEmpty()) {
            return [];
        }

        $counts = [];
        $placed = 0;

        foreach ($rows as $row) {
            $level = $this->bandOf($row, $scale);

            if ($level === null) {
                continue;
            }

            $counts[$level->id] = ($counts[$level->id] ?? 0) + 1;
            $placed++;
        }

        $bands = [];

        foreach ($scale->levels->sortBy('sequence') as $level) {
            $count = $counts[$level->id] ?? 0;

            $bands[] = [
                'scale_level_id' => (int) $level->id,
                'code' => (string) $level->code,
                'label' => (string) $level->label,
                'sequence' => (int) $level->sequence,
                'is_negative' => (bool) $level->is_negative,
                'count' => $count,
                // Of the students actually placed on the scale. Dividing by the
                // whole class would let students with no result silently shrink
                // every bar without appearing anywhere.
                'percentage' => $this->percentage($count, $placed),
            ];
        }

        return $bands;
    }

    /**
     * Per domain, across the class.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{id: int, name: string}>  $domains
     * @return list<array<string, mixed>>
     */
    protected function domainStatistics(array $rows, array $domains, ?Scale $scale): array
    {
        $statistics = [];

        foreach ($domains as $domain) {
            $period = [];
            $accumulated = [];
            $changes = [];
            $partial = 0;
            $succeeded = 0;
            $placed = 0;
            // QUANTOS ALUNOS ESTE DOMÍNIO CHEGA A ABRANGER — e não quantos
            // alunos a turma tem.
            //
            // Desde a decisão do product owner, este método recebe a
            // progressão COMPLETA, para que uma evidência produzida enquanto o
            // aluno frequentava continue a contar mesmo que uma janela de
            // não-frequência tenha aberto depois (pergunta (A)). Contar o
            // «sem resultado» contra `count($rows)` passaria a dizer «sem
            // resultado» de quem NUNCA teve dado nenhum neste domínio — que é
            // exatamente a frase que a nota logo acima recusa dizer, e que a
            // regra proíbe: «não frequenta» não é «sem classificação».
            //
            // Contado por domínio, e não uma vez para todos: um domínio pode
            // abranger alunos que outro não abrange.
            $covered = 0;

            foreach ($rows as $row) {
                $cell = $this->domainCell($row, $domain['id']);

                if ($cell === null) {
                    continue;
                }

                $covered++;

                // The same rule as the class figure, applied to this domain's
                // own mention: the scale decides, and a domain the scale places
                // nothing in counts for neither side.
                $mention = $cell['mention'] ?? null;

                if ($mention !== null) {
                    $placed++;

                    if ($mention['is_negative'] === false) {
                        $succeeded++;
                    }
                }

                if ($cell['weighted_average'] !== null) {
                    $period[] = (string) $cell['weighted_average'];
                }

                if ($cell['accumulated_average'] !== null) {
                    $accumulated[] = (string) $cell['accumulated_average'];
                }

                if (($cell['evolution'] ?? null) !== null) {
                    $changes[] = (string) $cell['evolution']['points'];
                }

                // Same rule as the summary's: partial describes a value that
                // exists. A domain nobody has evidence in is not «6 resultados
                // parciais», it is no results at all.
                if (($cell['coverage_warning'] ?? false) === true && $cell['weighted_average'] !== null) {
                    $partial++;
                }
            }

            $accumulatedMean = $this->mean($accumulated);

            $statistics[] = [
                'domain_id' => $domain['id'],
                'label' => $domain['name'],
                'period_average' => $this->mean($period),
                'accumulated_average' => $accumulatedMean,
                'evolution_average' => $this->mean($changes),
                'students_with_result' => count($period),
                'students_without_result' => $covered - count($period),
                'partial_coverage_count' => $partial,
                // Success within this domain — «5 de 6» — so a teacher can see
                // which domain is carrying the class and which is holding it
                // back, without a second chart for each (§7).
                'succeeded' => $succeeded,
                'placed' => $placed,
                'success_rate' => $this->percentage($succeeded, $placed),
                // The band of the CLASS's accumulated mean in this domain. A
                // statistic about the group, never a mention belonging to any
                // student — placed by the same resolver all the same.
                'qualitative_band' => $this->bandPayload($this->proposals->bandFor($scale, $accumulatedMean)),
            ];
        }

        return $statistics;
    }

    /**
     * The class along its periods, for the trend lines.
     *
     * STANDALONE throughout. An accumulated figure already contains the periods
     * before it, so a line drawn from accumulated values slopes towards its own
     * history and reports movement that did not happen (§15).
     *
     * @param  list<array<string, mixed>>  $students
     * @param  list<array<string, mixed>>  $periods
     * @param  array<int, string>  $scopes
     * @param  list<array{id: int, name: string}>  $domains
     * @return list<array<string, mixed>>
     */
    protected function periodSeries(array $students, array $periods, array $domains, array $scopes = []): array
    {
        $series = [];

        foreach ($periods as $period) {
            $rows = $this->rowsFor($students, $period['id']);
            $overall = $this->valuesOf($rows, 'weighted_average');
            $accumulated = $this->valuesOf($rows, 'accumulated_average');
            $kind = $scopes[$period['id']] ?? 'period';

            $byDomain = [];

            foreach ($domains as $domain) {
                $values = [];
                $running = [];

                foreach ($rows as $row) {
                    $cell = $this->domainCell($row, $domain['id']);

                    if ($cell === null) {
                        continue;
                    }

                    if ($cell['weighted_average'] !== null) {
                        $values[] = (string) $cell['weighted_average'];
                    }

                    if (($cell['accumulated_average'] ?? null) !== null) {
                        $running[] = (string) $cell['accumulated_average'];
                    }
                }

                $byDomain[] = [
                    'domain_id' => $domain['id'],
                    'average' => $this->mean($values),
                    'accumulated_average' => $this->mean($running),
                    'students_with_result' => count($values),
                ];
            }

            $series[] = [
                'period_id' => $period['id'],
                'label' => $period['label'],
                'sequence' => $period['sequence'],
                'class_average' => $this->mean($overall),
                'accumulated_average' => $this->mean($accumulated),
                // The figure that answered for the class at THIS moment — the
                // line a «como foi o ano» sparkline should draw (§22).
                'primary_average' => $kind === 'accumulated' ? $this->mean($accumulated) : $this->mean($overall),
                'primary_kind' => $kind,
                'students_with_result' => count($overall),
                'domains' => $byDomain,
            ];
        }

        return $series;
    }

    /**
     * One row per student for the heatmap and the individual reading.
     *
     * Everything here is copied from the progression, not derived: the values,
     * the mentions, the movement, the self-assessment and the decision all keep
     * whatever the canonical read model said about them.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $progressionStudents
     * @param  list<array<string, mixed>>  $previousRows
     * @param  array<int, string>  $scopes
     * @return list<array<string, mixed>>
     */
    protected function students(
        array $rows,
        ?Scale $scale,
        array $progressionStudents = [],
        array $previousRows = [],
        string $primaryKind = 'period',
        array $scopes = [],
        ?string $previousPrimaryKind = null,
    ): array {
        // Each student's own line through the year, lifted whole from the
        // progression: their period figures, their movement and their domains,
        // exactly as the canonical model wrote them. Nothing is recomputed and
        // no query is added — the data was already in hand.
        $series = [];

        foreach ($progressionStudents as $student) {
            $series[(int) $student['enrollment_id']] = array_map(fn (array $entry): array => [
                'period_id' => $entry['period_id'],
                'period_label' => $entry['period_label'],
                'weighted_average' => $entry['weighted_average'],
                'accumulated_average' => $entry['accumulated_average'],
                // The figure that answered for this student AT THAT MOMENT, so
                // an individual timeline reads as one continuous line instead
                // of switching meaning halfway through (§29).
                'primary_average' => ($scopes[$entry['period_id']] ?? 'period') === 'accumulated'
                    ? $entry['accumulated_average']
                    : $entry['weighted_average'],
                'coverage_warning' => (bool) $entry['coverage_warning'],
                'evolution' => $entry['evolution'],
                'domains' => array_map(fn (array $cell): array => [
                    'domain_id' => $cell['domain_id'],
                    'weighted_average' => $cell['weighted_average'],
                    'mention' => $cell['mention'],
                ], $entry['domains']),
            ], $student['periods']);
        }

        $before = [];

        foreach ($previousRows as $row) {
            $before[(int) $row['enrollment_id']] = $row;
        }

        $students = [];

        foreach ($rows as $row) {
            $period = $row['period'];

            $students[] = [
                'enrollment_id' => $row['enrollment_id'],
                'name' => $row['name'],
                'class_number' => $row['class_number'],
                'weighted_average' => $period['weighted_average'] ?? null,
                'accumulated_average' => $period['accumulated_average'] ?? null,
                // The same two figures, named by which answers for them here.
                'primary_average' => $this->primaryValueOf($row, $primaryKind),
                'supplementary_average' => $this->supplementaryValueOf($row, $primaryKind),
                'coverage_warning' => $period['coverage_warning'] ?? false,
                'evolution' => $period['evolution'] ?? null,
                // Their own movement in the continuous assessment, beside the
                // period-against-period one. Different question, different key.
                'continuous_evolution' => $this->continuousMovementOf(
                    $row,
                    $before[(int) $row['enrollment_id']] ?? null,
                    $primaryKind,
                    $previousPrimaryKind,
                ),
                'band' => $this->bandPayload($this->bandOf($row, $scale)),
                // The grade, as a mention. Kept beside the calculated band and
                // never in place of it: a selection made on the assigned
                // distribution must highlight the students who were GRADED
                // there, not the ones whose average happens to land there (§14).
                'assigned' => $this->assignedLevelOf($row, $scale),
                // The group this student belongs to in that distribution: a level
                // id on a levelled scale, the number they were given on a
                // numeric one. One key, so one comparison highlights them.
                'assigned_key' => $this->assignedKeyOf($row, $scale),
                'domains' => $period['domains'] ?? [],
                'self_assessment' => $period['self_assessment'] ?? null,
                'classification' => $period['classification'] ?? null,
                // A PROVENIÊNCIA, quando esta linha é uma classificação
                // resolvida a partir de um resultado externo (req 6/7) — nunca
                // presente para uma evidência desta disciplina.
                'external' => $period['external'] ?? null,
                // Their whole year, for the individual panel (§3, §5).
                'series' => $series[$row['enrollment_id']] ?? [],
                // Which side of the scale they were on and are on now — the
                // same word the class counts are grouped by, so a card and a
                // student can never disagree about who crossed.
                'transition' => $this->transitionOf($row, $before[(int) $row['enrollment_id']] ?? null, $scale),
            ];
        }

        return $students;
    }

    /**
     * The band a student's accumulated average falls in.
     *
     * The ACCUMULATED figure, because that is what the Quadro Síntese already
     * places a mention on. Banding the standalone one instead would give the
     * same student two different mentions on two screens (§0).
     *
     * @param  array<string, mixed>  $row
     */
    protected function bandOf(array $row, ?Scale $scale): ?ScaleLevel
    {
        return $this->proposals->bandFor($scale, $row['period']['accumulated_average'] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    protected function mostCommonBand(array $rows, ?Scale $scale): ?array
    {
        $rows = $this->countableRows($rows);
        $counts = [];
        $levels = [];

        foreach ($rows as $row) {
            $level = $this->bandOf($row, $scale);

            if ($level === null) {
                continue;
            }

            $counts[$level->id] = ($counts[$level->id] ?? 0) + 1;
            $levels[$level->id] = $level;
        }

        if ($counts === []) {
            return null;
        }

        $highest = max($counts);
        $tied = array_keys($counts, $highest, true);

        // A tie has no single most common band, and naming one of them would be
        // picking by insertion order and calling it a finding.
        if (count($tied) > 1) {
            return null;
        }

        return [
            ...$this->bandPayload($levels[$tied[0]]),
            'count' => $highest,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function bandPayload(?ScaleLevel $level): ?array
    {
        return $level === null ? null : [
            'scale_level_id' => (int) $level->id,
            'code' => (string) $level->code,
            'label' => (string) $level->label,
            'sequence' => (int) $level->sequence,
            'is_negative' => (bool) $level->is_negative,
        ];
    }

    /**
     * The scale's own bands, so the browser can tone a chart without ever
     * deciding what a band means.
     *
     * @return array<string, mixed>|null
     */
    protected function scalePayload(?Scale $scale): ?array
    {
        if ($scale === null) {
            return null;
        }

        $bands = [];

        foreach ($scale->levels->sortBy('sequence') as $level) {
            $bands[] = [
                'label' => (string) $level->label,
                'sequence' => (int) $level->sequence,
                'is_negative' => (bool) $level->is_negative,
            ];
        }

        return [
            'name' => (string) $scale->name,
            'kind' => (string) $scale->kind,
            'bands' => $bands,
            'threshold' => $this->thresholdPayload($scale),
        ];
    }

    /**
     * WHERE THIS SCALE PUTS THE LINE, in the words a teacher would use.
     *
     * «Passaram a resultado positivo» is generic and says nothing a school can
     * check; «Passaram para nível igual ou superior a 3» is the same fact
     * stated against the scale's own configuration. So the phrasing is built
     * here, once, from the first level the scale does NOT call negative — never
     * from a 3, a 10 or a 50 written into the code.
     *
     * «IGUAL OU SUPERIOR», never «superior»: the boundary level is on the
     * passing side of its own line, and «superior a 3» would exclude the very
     * level that defines it.
     *
     * THREE SHAPES, because scales have three:
     *
     *  - a levelled scale states the value the teacher writes as the level's
     *    own code, so «3» is read straight off it;
     *  - a numeric scale states it as a band boundary in normalized space, so
     *    it is placed back on the scale's own interval by the same approved
     *    rule the proposals use;
     *  - a scale whose levels are words has no number to quote, and gets its
     *    own wording rather than an invented one (§1.9).
     *
     * @return array<string, mixed>
     */
    protected function thresholdPayload(Scale $scale): array
    {
        $noun = $scale->kind === 'level' ? 'nível' : 'classificação';

        $first = $scale->levels->sortBy('sequence')->first(fn (ScaleLevel $level): bool => ! $level->is_negative);

        $value = $first === null ? null : $this->thresholdValueOf($scale, $first);

        if ($value !== null) {
            return [
                'noun' => $noun,
                'value' => $value,
                'label' => null,
                'at_or_above' => "{$noun} igual ou superior a {$value}",
                'below' => "{$noun} inferior a {$value}",
            ];
        }

        // Words rather than a number: «Atingiu» and everything after it.
        if ($first !== null) {
            return [
                'noun' => $noun,
                'value' => null,
                'label' => (string) $first->label,
                'at_or_above' => "«{$first->label}» ou superior",
                'below' => "abaixo de «{$first->label}»",
            ];
        }

        // A scale that says nothing about a passing line. Nothing is invented;
        // the crossings will be «sem menção na escala» anyway.
        return [
            'noun' => $noun,
            'value' => null,
            'label' => null,
            'at_or_above' => "{$noun} não negativa",
            'below' => "{$noun} negativa",
        ];
    }

    /**
     * The number a teacher would recognise for the passing line, or null when
     * the scale gives none.
     */
    protected function thresholdValueOf(Scale $scale, ScaleLevel $level): ?string
    {
        // A levelled scale: the code IS what gets written on the pauta.
        if ($scale->kind === 'level') {
            return is_numeric($level->code) ? $this->trimZeros((string) $level->code) : null;
        }

        $boundary = $level->band_min_normalized;

        if ($boundary === null) {
            return null;
        }

        // A percentage scale is already expressed on that axis.
        if ($scale->kind === 'percentage') {
            return $this->trimZeros(Bc::round(Bc::of((string) $boundary), 0, 'half_up'));
        }

        if ($scale->min_value === null || $scale->max_value === null) {
            return null;
        }

        // The one approved placement rule, forwards: min + (normalized/100) × span.
        $span = Bc::sub(Bc::of((string) $scale->max_value), Bc::of((string) $scale->min_value));

        return $this->trimZeros(Bc::round(
            Bc::add(Bc::of((string) $scale->min_value), Bc::mul(Bc::div(Bc::of((string) $boundary), '100'), $span)),
            0,
            'half_up',
        ));
    }

    /** «10.000» reads as «10» on a card. */
    protected function trimZeros(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    protected function domainCell(array $row, int $domainId): ?array
    {
        foreach ($row['period']['domains'] ?? [] as $cell) {
            if ($cell['domain_id'] === $domainId) {
                return $cell;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    protected function valuesOf(array $rows, string $key): array
    {
        $values = [];

        foreach ($rows as $row) {
            $value = $row['period'][$key] ?? null;

            if ($value !== null) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * The mean, or null when there was nothing to average.
     *
     * Null rather than zero, all the way out to the screen: a class with no
     * results does not average zero, it has no average (§37).
     *
     * @param  list<string>  $values
     */
    protected function mean(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $total = '0';

        foreach ($values as $value) {
            $total = Bc::add($total, Bc::of($value));
        }

        return Bc::round(Bc::div($total, (string) count($values)), self::PRECISION, 'half_up');
    }

    protected function percentage(int $count, int $total): ?string
    {
        if ($total === 0) {
            return null;
        }

        return Bc::round(Bc::mul(Bc::div((string) $count, (string) $total), '100'), self::PRECISION, 'half_up');
    }

    /**
     * @param  list<array<string, mixed>>  $periods
     * @param  list<array{id: int, name: string}>  $domains
     * @return array<string, mixed>
     */
    protected function empty(array $periods, array $domains): array
    {
        return [
            'periods' => $periods,
            'selected_period' => null,
            'previous_period' => null,
            'domains' => $domains,
            'scale' => null,
            'universe' => [
                'value' => CohortUniverse::AttendingOnly->value,
                'label' => CohortUniverse::AttendingOnly->label(),
            ],
            'cohort' => [
                'not_attending_count' => 0,
                'not_attending_origins' => [],
                'external_included_count' => 0,
                'not_attending_without_result' => 0,
            ],
            'notes' => [
                'domain_exclusion' => null,
                'external_inclusion' => null,
                'partial_period_attendance' => null,
            ],
            'primary' => [
                'kind' => 'period', 'label' => 'Média Ponderada', 'short_label' => 'Média da turma',
                'caption' => null, 'supplementary_label' => null, 'has_supplementary' => false, 'scopes' => [],
            ],
            'summary' => [
                'students_total' => 0,
                'domain_students_total' => 0,
                'students_with_result' => 0,
                'students_without_result' => 0,
                'class_average' => null,
                'accumulated_average' => null,
                'primary_average' => null,
                'supplementary_average' => null,
                'partial_coverage_count' => 0,
                'most_common_band' => null,
                'success' => [
                    'succeeded' => 0, 'failed' => 0, 'unplaced' => 0, 'without_classification' => 0,
                    'placed' => 0, 'rate' => null, 'failure_rate' => null,
                ],
            ],
            'evolution' => [
                'progressed' => 0, 'stable' => 0, 'regressed' => 0, 'no_comparison' => 0,
                'comparable' => 0, 'average_change' => null,
                'percentages' => ['progressed' => null, 'stable' => null, 'regressed' => null, 'no_comparison' => null],
                'transitions' => [
                    'failure_to_success' => 0, 'success_to_failure' => 0,
                    'success_to_success' => 0, 'failure_to_failure' => 0,
                    'unclassified' => 0, 'no_assigned_classification' => 0, 'comparable' => 0,
                    'percentages' => [
                        'failure_to_success' => null, 'success_to_failure' => null,
                        'success_to_success' => null, 'failure_to_failure' => null,
                    ],
                    'share_of_class' => ['unclassified' => null, 'no_assigned_classification' => null],
                ],
            ],
            'continuous_evolution' => [
                'progressed' => 0, 'stable' => 0, 'regressed' => 0, 'no_comparison' => 0,
                'comparable' => 0, 'average_change' => null,
                'percentages' => ['progressed' => null, 'stable' => null, 'regressed' => null, 'no_comparison' => null],
            ],
            'assigned_distribution' => [
                'bands' => [], 'mode' => 'levels', 'classified' => 0, 'unplaced' => 0, 'without_classification' => 0,
            ],
            'distribution' => [],
            'domain_statistics' => [],
            'period_series' => [],
            'students' => [],
        ];
    }
}
