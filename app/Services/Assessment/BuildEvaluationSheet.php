<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Domain\Assessment\CalculationOutcome;
use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\DomainAppreciationDecision;
use App\Models\Enrollment;
use App\Models\ProfileVersionDomain;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use Illuminate\Support\Collection;

/**
 * Builds the export-neutral evaluation-sheet read model from canonical results.
 *
 * EVERY LEVEL TRAVELS AS BOTH `code` AND `label`, AND THAT IS NOT REDUNDANCY.
 * A level on «Escala 1 a 5» is the pair `3` / «Suficiente», and a pauta names
 * the decision by its CODE — the number is what a pauta of 2.º/3.º ciclo
 * carries, and a qualitative mention in that column names something the
 * document does not say (SUP-2C774B). The label follows so the screen can put
 * it in the cell's title, the same way Classificações already does.
 *
 * THE LABEL IS ALSO WHAT KEEPS OLD HISTORY READABLE. A snapshot is opened by
 * the very same table component as the live sheet, and the snapshots already
 * captured carry `scale_level_label` and no code. Dropping the label here
 * would blank a column in every pauta kept before this change — history has to
 * go on saying what was on screen the day it was kept, so the reader falls
 * back to the label whenever a payload has no code.
 *
 * A AUTOAVALIAÇÃO VIAJA COM O RESTO, E POR ISSO FICA NO QUE FOR GUARDADO. O que
 * o aluno disse de si próprio faz parte do estado avaliativo daquele momento —
 * é ao lado dele que a decisão do professor se lê — e uma fotografia que o
 * deixasse de fora não deixaria reconstruir o que o professor tinha à frente. É
 * informação de apoio e NUNCA entra no cálculo (§15): não pesa, não soma, não
 * arredonda coisa alguma. Uma pauta guardada antes disto não a traz, e continua
 * a abrir — o leitor trata a ausência como ausência, nunca como um zero.
 *
 * POR DOMÍNIO HÁ DUAS COISAS, E ELAS TÊM CHAVES DIFERENTES. `scale_level_*` é a
 * PROPOSTA do Lapispro — a banda em que o quantitativo calculado cai — e
 * continua a significar exatamente o que significava antes de existir decisão
 * por domínio; é isso que mantém legível cada fotografia guardada até aqui.
 * `decided_scale_level_*` é a DECISÃO DO PROFESSOR, e é null enquanto ninguém se
 * pronunciar. As duas viajam sempre juntas: quem lê escolhe qual mostra, mas
 * nenhuma apaga a outra, e o quantitativo não muda por causa de nenhuma delas.
 */
class BuildEvaluationSheet
{
    public function __construct(
        protected ClassResultsCalculator $calculator,
        protected CoverageExplanation $coverage,
        protected ScaleProposalResolver $proposals,
        protected SelfAssessmentReading $selfAssessments,
        protected DomainAppreciationDecisions $domainDecisions,
        protected FormalProposalBasis $basis,
    ) {}

    /**
     * @param  bool  $withProposalBasis  se a leitura traz a proposta de HOJE ao lado da guardada e o aviso de desatualizada. Na unidade que fecha o ano isso obriga a apurar os resultados das outras unidades, e quem não vai ler esses campos não tem por que os pagar — é o caso do Quadro Síntese, que abre a pauta de cada unidade e faz a sua própria leitura do ano.
     * @return array{class_id: int, academic_period_id: int, scope: string, domains: list<array<string, mixed>>, students: list<array<string, mixed>>}
     */
    public function for(
        SchoolClass $schoolClass,
        AcademicPeriod $academicPeriod,
        ClassificationScope|string $scope = ClassificationScope::Period,
        bool $withProposalBasis = true,
    ): array {
        $resolvedScope = is_string($scope) ? ClassificationScope::from($scope) : $scope;
        $results = $this->calculator->forScope($schoolClass, $academicPeriod, $resolvedScope);
        $coverage = $this->coverage->forResults($results);
        $profileDomains = $this->profileDomains($schoolClass);
        $profileVersion = $schoolClass->profileVersion;
        $scale = $profileVersion?->scale()->with('levels')->first();
        $roundingMode = $profileVersion === null ? 'half_up' : $profileVersion->rounding_mode;
        $roundingScale = $profileVersion === null ? 0 : $profileVersion->rounding_scale;
        $classifications = $this->classifications($results, $academicPeriod, $resolvedScope);

        // Uma consulta para a turma inteira, não uma por aluno (§24). A
        // autoavaliação é sempre a DESTE período — mesmo num âmbito acumulado,
        // porque o que o aluno disse de si num período não é o que disse
        // noutro, e juntá-los seria inventar uma frase que ninguém escreveu.
        $selfAssessments = $this->selfAssessments->forClassPeriod($schoolClass, $academicPeriod);

        // As decisões do professor por domínio, também numa única consulta para
        // a turma inteira. Vazio é o estado normal: a esmagadora maioria dos
        // domínios fica na proposta, e é isso que a ausência de linha diz.
        $decisions = $this->domainDecisions->for(
            array_map(fn (array $result): int => (int) $result['enrollment']->getKey(), $results),
            (int) $academicPeriod->getKey(),
            $resolvedScope,
        );

        // DE QUE NÚMERO NASCE A PROPOSTA DESTA UNIDADE — o resultado dela a meio
        // do ano, a avaliação contínua final na unidade que o fecha. A pauta
        // precisa de o saber por duas razões: para o poder mostrar ao professor
        // quando ele abre a decisão (uma proposta cuja origem não está no ecrã
        // não se pode conferir) e para dizer quando a proposta GUARDADA já não
        // corresponde ao que os dados dizem hoje.
        //
        // As linhas já calculadas seguem para lá: numa unidade intermédia isto
        // não custa consulta nenhuma, e na última paga-se o cálculo das outras
        // unidades uma vez para a turma inteira.
        $basisByEnrollment = [];

        if ($withProposalBasis) {
            foreach ($this->basis->forRows($schoolClass, $academicPeriod, $resolvedScope, $results) as $row) {
                $basisByEnrollment[(int) $row['enrollment']->getKey()] = $row['outcome'];
            }
        }

        $students = [];
        foreach ($results as $result) {
            $enrollment = $result['enrollment'];
            $outcome = $result['outcome'];
            $enrollmentId = (int) $enrollment->getKey();
            $selfAssessment = $selfAssessments->get($enrollmentId);

            $students[] = [
                'enrollment_id' => $enrollmentId,
                'class_number' => $enrollment->class_number,
                'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                'overall' => $this->overall($outcome, $scale, $roundingMode, $roundingScale),
                'domains' => $this->domains(
                    $outcome,
                    $profileDomains,
                    $scale,
                    $coverage[$enrollmentId]['domains'] ?? [],
                    $selfAssessment,
                    $decisions[$enrollmentId] ?? [],
                ),
                'classification' => $this->classification(
                    $classifications[$enrollmentId] ?? null,
                    $basisByEnrollment[$enrollmentId] ?? null,
                    $scale,
                ),
                'coverage' => $coverage[$enrollmentId]['overall'] ?? CoverageExplanation::none(),
                // O juízo global do próprio aluno. Null quando não respondeu à
                // pergunta global — nunca a média do que disse por domínio.
                'self_assessment' => $this->selfAssessments->global($selfAssessment),
            ];
        }

        return [
            'class_id' => (int) $schoolClass->getKey(),
            'academic_period_id' => (int) $academicPeriod->getKey(),
            'scope' => $resolvedScope->value,
            'domains' => array_values($profileDomains->map(fn (ProfileVersionDomain $profileDomain): array => [
                'domain_id' => (int) $profileDomain->domain_id,
                'name' => (string) $profileDomain->domain->name,
                'sequence' => (int) $profileDomain->sequence,
                'weight_percent' => (string) $profileDomain->weight_percent,
            ])->all()),
            'students' => $students,
        ];
    }

    /**
     * @return Collection<int, ProfileVersionDomain>
     */
    protected function profileDomains(SchoolClass $schoolClass): Collection
    {
        if ($schoolClass->assessment_profile_version_id === null) {
            return collect();
        }

        // `profile_version_domains` has no organization_id of its own — it is
        // reached only through `assessment_profile_version_id`, never queried
        // directly by tenant. If that id ever survives a tenant switch (a
        // stale model reused under a different Organization::runFor, as in
        // the cross-tenant test), the eager-loaded `domain` silently comes
        // back null instead of the row disappearing, because `Domain` (unlike
        // this table) *is* organization-scoped. Drop those rows rather than
        // let a null relation reach the caller.
        return ProfileVersionDomain::query()
            ->where('assessment_profile_version_id', $schoolClass->assessment_profile_version_id)
            ->with('domain')
            ->orderBy('sequence')
            ->get()
            ->filter(fn (ProfileVersionDomain $profileDomain): bool => $profileDomain->domain !== null)
            ->values();
    }

    /**
     * @param  list<array{enrollment: Enrollment, outcome: CalculationOutcome}>  $results
     * @return array<int, Classification>
     */
    protected function classifications(array $results, AcademicPeriod $academicPeriod, ClassificationScope $scope): array
    {
        $enrollmentIds = array_map(fn (array $result): int => (int) $result['enrollment']->getKey(), $results);

        if ($enrollmentIds === []) {
            return [];
        }

        $classifications = Classification::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->where('academic_period_id', $academicPeriod->getKey())
            ->where('scope', $scope)
            ->whereNull('superseded_by_id')
            ->with(['proposedScaleLevel', 'finalScaleLevel'])
            ->get();

        $byEnrollment = [];
        foreach ($classifications as $classification) {
            $byEnrollment[(int) $classification->enrollment_id] = $classification;
        }

        return $byEnrollment;
    }

    /**
     * @return array<string, mixed>
     */
    protected function overall(
        CalculationOutcome $outcome,
        ?Scale $scale,
        string $roundingMode,
        int $roundingScale,
    ): array {
        $level = $outcome->scaleLevelId === null ? null : $scale?->levels->firstWhere('id', $outcome->scaleLevelId);
        $proposal = $this->proposals->resolve(
            $scale,
            $outcome->scaleLevelId,
            $outcome->normalizedValue,
            $outcome->proposedValue,
            $roundingMode,
            $roundingScale,
        );
        $scaleValue = $level !== null
            ? ($level->numeric_value === null ? null : (string) $level->numeric_value)
            : $proposal->value;

        return [
            'normalized_value' => $outcome->normalizedValue,
            'scale_value' => $scaleValue,
            'scale_level_id' => $outcome->scaleLevelId,
            'scale_level_code' => $level?->code,
            'scale_level_label' => $level?->label,
            'result_state' => $outcome->resultState,
            'has_coverage_warning' => $outcome->coverageWarning,
        ];
    }

    /**
     * @param  Collection<int, ProfileVersionDomain>  $profileDomains
     * @param  array<int, array<string, mixed>>  $coverage
     * @param  array<int, DomainAppreciationDecision>  $decisions
     * @return list<array<string, mixed>>
     */
    protected function domains(
        CalculationOutcome $outcome,
        Collection $profileDomains,
        ?Scale $scale,
        array $coverage,
        ?SelfAssessment $selfAssessment = null,
        array $decisions = [],
    ): array {
        $outcomes = collect($outcome->domains)->keyBy('domainId');

        return array_values($profileDomains->map(function (ProfileVersionDomain $profileDomain) use ($outcomes, $scale, $coverage, $selfAssessment, $decisions): array {
            $domainOutcome = $outcomes->get($profileDomain->domain_id);
            $said = $this->selfAssessments->forDomain($selfAssessment, (int) $profileDomain->domain_id);
            $decided = $decisions[(int) $profileDomain->domain_id] ?? null;

            if ($domainOutcome === null) {
                // The engine never produced an outcome for this domain (e.g. no
                // item allocates to it this period) — every element is missing,
                // not zero (§9 rule 5), so a coverage warning is implied here.
                return [
                    'domain_id' => (int) $profileDomain->domain_id,
                    'name' => (string) $profileDomain->domain->name,
                    'sequence' => (int) $profileDomain->sequence,
                    'normalized_value' => null,
                    'weight_percent_applied' => (string) $profileDomain->weight_percent,
                    'scale_level_id' => null,
                    'scale_level_code' => null,
                    'scale_level_label' => null,
                    // O professor pode ter-se pronunciado sobre um domínio que
                    // o motor ainda não conseguiu calcular — é uma leitura
                    // pedagógica, não a conclusão de uma conta, e um domínio sem
                    // elementos é precisamente onde ela mais se justifica.
                    ...$this->decision($decided),
                    'has_coverage_warning' => true,
                    'coverage' => $coverage[$profileDomain->domain_id] ?? CoverageExplanation::none(),
                    // O aluno pode ter-se pronunciado sobre um domínio que ainda
                    // não tem elementos. Continua a ser o que ele disse.
                    'self_assessment' => $said,
                ];
            }

            $level = $this->proposals->bandFor($scale, $domainOutcome->normalizedValue);

            return [
                'domain_id' => (int) $profileDomain->domain_id,
                'name' => (string) $profileDomain->domain->name,
                'sequence' => (int) $profileDomain->sequence,
                'normalized_value' => $domainOutcome->normalizedValue,
                'weight_percent_applied' => $domainOutcome->weightPercent,
                'scale_level_id' => $level?->id,
                'scale_level_code' => $level?->code,
                'scale_level_label' => $level?->label,
                ...$this->decision($decided),
                'has_coverage_warning' => $domainOutcome->coverageWarning,
                'coverage' => $coverage[$profileDomain->domain_id] ?? CoverageExplanation::none(),
                'self_assessment' => $said,
            ];
        })->values()->all());
    }

    /**
     * A decisão do professor sobre este domínio, ou a sua ausência.
     *
     * AS TRÊS CHAVES EXISTEM SEMPRE, mesmo a null. Uma célula sem decisão e uma
     * célula que a perdeu por acidente têm de ser indistinguíveis para quem lê,
     * e a forma do modelo não pode depender do que aconteceu a este aluno.
     *
     * O NÍVEL VIAJA COM CÓDIGO E RÓTULO pela mesma razão que a proposta: uma
     * pauta de 2.º/3.º ciclo escreve «4», e o ecrã que esconde os quantitativos
     * escreve «Bom». Guardar só um dos dois obrigaria a fotografia a ir buscar o
     * outro a uma escala que pode ter mudado desde então (§15).
     *
     * @return array{decided_scale_level_id: int|null, decided_scale_level_code: string|null, decided_scale_level_label: string|null}
     */
    protected function decision(?DomainAppreciationDecision $decision): array
    {
        $level = $decision?->scaleLevel;

        return [
            'decided_scale_level_id' => $level?->id,
            'decided_scale_level_code' => $level?->code,
            'decided_scale_level_label' => $level?->label,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * A classificação guardada, e — a seu lado — a proposta que os dados de hoje
     * produzem.
     *
     * PORQUE SÃO DUAS COISAS. `proposed_*` é uma linha escrita no momento em que
     * alguém correu «propor»; o resultado ao lado dela é calculado agora. Entre
     * um e outro pode ter sido corrigida uma cotação, excluído um elemento,
     * lançado um teste — e a linha não sabe disso. Até aqui o ecrã servia as
     * duas metades lado a lado sem nada que dissesse qual era a de hoje: uma
     * percentagem de 50,3 % ao lado de «Proposta do Lapispro: 2», e a banda dos
     * 50,3 % é 3.
     *
     * `current_*` é a recomendação de hoje e `is_stale` diz se ela deixou de
     * coincidir com a guardada. A comparação é a MESMA que
     * `ConfirmClassification` já faz antes de deixar confirmar — uma proposta
     * desatualizada é recusada lá —, e é por isso que ela tem de ser feita aqui
     * também: o professor tem de o saber antes de tentar, e não pela mensagem de
     * erro depois de clicar.
     *
     * @return array<string, mixed>|null
     */
    protected function classification(
        ?Classification $classification,
        ?CalculationOutcome $basis,
        ?Scale $scale,
    ): ?array {
        if ($classification === null) {
            return null;
        }

        $currentLevel = $basis === null ? null : $scale?->levels->firstWhere('id', $basis->scaleLevelId);
        $stored = $classification->proposed_value;
        $current = $basis?->proposedValue;

        return [
            'status' => $classification->status->value,
            'proposed_value' => $classification->proposed_value,
            'proposed_scale_level_id' => $classification->proposed_scale_level_id,
            'proposed_scale_level_code' => $classification->proposedScaleLevel?->code,
            'proposed_scale_level_label' => $classification->proposedScaleLevel?->label,
            // A proposta que os dados de HOJE produzem, na mesma escala.
            'current_normalized_value' => $basis?->normalizedValue,
            'current_value' => $current,
            'current_scale_level_id' => $basis?->scaleLevelId,
            'current_scale_level_code' => $currentLevel?->code,
            'current_scale_level_label' => $currentLevel?->label,
            // Sem uma das duas metades não há desacordo a afirmar: uma linha
            // aberta sem proposta não está desatualizada, está por propor.
            'proposal_is_stale' => $stored !== null
                && $current !== null
                && Bc::compare(Bc::of((string) $stored), Bc::of((string) $current)) !== 0,
            'final_value' => $classification->final_value,
            'final_scale_level_id' => $classification->final_scale_level_id,
            'final_scale_level_code' => $classification->finalScaleLevel?->code,
            'final_scale_level_label' => $classification->finalScaleLevel?->label,
            'override_reason' => $classification->override_reason,
        ];
    }
}
