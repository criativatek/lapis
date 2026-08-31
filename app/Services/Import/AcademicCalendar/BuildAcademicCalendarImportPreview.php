<?php

namespace App\Services\Import\AcademicCalendar;

use App\Domain\AcademicCalendar\AcademicCalendarExceptionMatch;
use App\Domain\Import\AcademicCalendar\ParsedAcademicCalendar;
use App\Domain\Import\AcademicCalendar\ParsedCalendarMarker;
use App\Domain\Import\AcademicCalendar\ParsedCalendarMarkerKind;
use App\Domain\Import\AcademicCalendar\ParsedCalendarRange;
use App\Domain\Import\AcademicCalendar\ParsedSemester;
use App\Models\AcademicCalendarException;
use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodKind;
use App\Models\AcademicYear;
use App\Services\AcademicCalendar\MatchAcademicCalendarExceptions;
use Illuminate\Database\Eloquent\Collection;

/**
 * O ecrã em que o professor decide, linha a linha: o que o documento diz, para
 * onde iria, e o que já está lá.
 *
 * NÃO ESCREVE NADA. Construir uma pré-visualização é uma leitura do princípio ao
 * fim — nenhum período, nenhuma exceção e nenhum acontecimento nasce aqui nem no
 * caminho até aqui. Só a confirmação explícita escreve, e volta a correr estas
 * mesmas comparações contra a base de dados quando o faz, porque o que estava lá
 * quando esta página foi construída não é o que interessa: interessa o que está
 * lá quando se grava.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * OS SETE ESTADOS, E O QUE CADA UM AUTORIZA
 *
 *   novo          — nada de parecido existe. Vem pré-selecionado: não há nada para
 *                   destruir e a alternativa era o professor picar quinze caixas
 *                   para dizer «sim» quinze vezes.
 *   já existente  — está lá, igualzinho. Mostra-se e não se faz nada: nem se
 *                   recria, nem se pode selecionar. É isto que torna reimportar o
 *                   mesmo ficheiro uma operação inofensiva.
 *   designação
 *   diferente     — SÓ PARA EXCEÇÕES. A mesma espécie, nas mesmas datas, com outro
 *                   nome: o documento chama «1.º de Maio» ao que este calendário
 *                   tem como «Dia do Trabalhador». Não nasce uma segunda linha —
 *                   isso nunca esteve em causa —, mas as duas designações vão lado
 *                   a lado e o professor escolhe qual fica. Nunca pré-selecionado.
 *   alterado      — existe com o mesmo nome e datas diferentes. NUNCA
 *                   pré-selecionado: sobrescrever a estrutura de um ano é a coisa
 *                   com mais consequências nesta página, e as duas versões vão
 *                   lado a lado para que a escolha seja informada.
 *   conflito      — existe algo da mesma espécie a sobrepor-se, sem ser a mesma
 *                   coisa. Nunca pré-selecionado, e nunca resolvido por
 *                   aproximação: mostram-se os dois e decide quem sabe.
 *   requer escolha— o documento dá TRÊS datas de fim para um campo que tem uma.
 *                   Não há aqui empate a desfazer por regra nenhuma (§32), e por
 *                   isso a linha fica bloqueada até o professor escolher — sem
 *                   nenhuma das três pré-escolhida.
 *   fora do ano   — as datas do documento caem fora do ano letivo selecionado.
 *                   Nem se propõe: `AcademicYearRequest` recusaria a gravação, e
 *                   uma linha que só podia falhar é mais honesta explicada aqui do
 *                   que rejeitada no fim. Acontece de verdade — o calendário de
 *                   2026/2027 aberto com 2025/2026 selecionado é isto inteiro.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A REGRA QUE EMPARELHA EXCEÇÕES JÁ NÃO VIVE AQUI. Vive em
 * MatchAcademicCalendarExceptions, porque deixou de ser assunto da importação: a
 * sugestão de feriados nacionais propõe a mesma espécie de coisa contra o mesmo
 * calendário, e as duas têm de chegar à mesma conclusão sobre se o 1 de maio já lá
 * está. O que ficou aqui é a tradução dessa conclusão para uma LINHA DESTE ECRÃ —
 * o «fora do ano», o «vem pré-marcado», o que se mostra a quem lê.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * O QUE NUNCA VAI PARA DOIS SÍTIOS (§30). Cada facto tem UM destino canónico: as
 * datas dos semestres são AcademicPeriod, os feriados e as interrupções são
 * AcademicCalendarException, e os acontecimentos escolares são CalendarEvent. Um
 * feriado não é também um acontecimento, e um semestre não é também uma exceção —
 * copiar o mesmo facto para duas tabelas era garantir que um dia discordariam.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * «DATAS E EVENTOS ESCOLARES» É UMA LISTA SÓ, E TEM DE SER.
 *
 * Havia aqui duas: «feriados» e «outros acontecimentos». A separação era falsa,
 * porque a primeira recebia TUDO o que o documento marcasse — o professor lia
 * «Reunião de avaliação · Feriado» e não tinha como saber que a aplicação estava a
 * afirmar que naquele dia não havia aula.
 *
 * Hoje as duas espécies vêm classificadas e vêm MISTURADAS, por data, numa única
 * secção. É a ordem em que estão no documento e é a ordem por que se lê um
 * calendário; cada linha diz o que É — «Feriado», «Reunião», «Atividade», «Data
 * relevante» — e para onde vai. Duas caixas separadas obrigavam quem lê a saber de
 * antemão a diferença entre duas tabelas desta aplicação para encontrar uma data.
 * `destination` viaja em cada linha, e é ele que separa o que se grava onde.
 */
class BuildAcademicCalendarImportPreview
{
    public const STATE_NEW = 'new';

    public const STATE_EXISTS = AcademicCalendarExceptionMatch::STATE_EXISTS;

    /**
     * SÓ AS EXCEÇÕES CHEGAM AQUI. Um período com o mesmo nome e datas diferentes é
     * «alterado» e continua a sê-lo — a designação de um período é a sua chave de
     * emparelhamento, e não um detalhe que se possa trocar.
     */
    public const STATE_CORRESPONDENCE = AcademicCalendarExceptionMatch::STATE_CORRESPONDENCE;

    public const STATE_CHANGED = 'changed';

    public const STATE_CONFLICT = AcademicCalendarExceptionMatch::STATE_CONFLICT;

    public const STATE_NEEDS_CHOICE = 'needs_choice';

    public const STATE_OUT_OF_YEAR = 'out_of_year';

    public function __construct(
        private readonly MatchAcademicCalendarExceptions $matcher,
    ) {}

    /**
     * @return array{
     *     semesters: list<array<string, mixed>>,
     *     schoolBreaks: list<array<string, mixed>>,
     *     datedItems: list<array<string, mixed>>,
     *     counts: array<string, int>
     * }
     */
    public function build(ParsedAcademicCalendar $calendar, AcademicYear $academicYear): array
    {
        $periods = $academicYear->periods()->get();
        $exceptions = $academicYear->exceptions()->get();

        $semesters = [];
        foreach ($calendar->semesters as $index => $semester) {
            $semesters[] = $this->semesterProposal($semester, $index, $periods, $academicYear);
        }

        $schoolBreaks = [];
        foreach ($calendar->schoolBreaks as $index => $range) {
            $schoolBreaks[] = $this->exceptionProposal($range, "break-{$index}", $exceptions, $academicYear);
        }

        $datedItems = [];
        foreach ($calendar->dayExceptions as $index => $range) {
            $datedItems[] = $this->exceptionProposal($range, "exception-{$index}", $exceptions, $academicYear);
        }

        foreach ($calendar->datedEvents as $index => $marker) {
            $datedItems[] = $this->markerProposal($marker, $index, $academicYear);
        }

        // POR DATA, e não «primeiro as exceções e depois os acontecimentos». Quem
        // confere uma importação está a comparar esta lista com um calendário
        // impresso, e um calendário impresso está por data. A ordem interna das
        // tabelas desta aplicação não é assunto de quem lê.
        usort(
            $datedItems,
            fn (array $left, array $right): int => [$left['starts_on'], $left['title']]
                <=> [$right['starts_on'], $right['title']],
        );

        return [
            'semesters' => $semesters,
            'schoolBreaks' => $schoolBreaks,
            'datedItems' => $datedItems,
            'counts' => $this->counts($semesters, $schoolBreaks, $datedItems),
        ];
    }

    // ───────────────────────────────────────────────────────────────── períodos

    /**
     * @param  Collection<int, AcademicPeriod>  $periods
     * @return array<string, mixed>
     */
    private function semesterProposal(ParsedSemester $semester, int $index, $periods, AcademicYear $academicYear): array
    {
        $current = $periods->first(
            fn (AcademicPeriod $period): bool => $this->sameLabel($period->label, $semester->label),
        );

        $candidates = [];
        foreach ($semester->endCandidates as $candidate) {
            $candidates[] = [
                'value' => $candidate->endsOn,
                'cohort' => $candidate->cohort,
                'raw_text' => $candidate->rawText,
                'in_year' => $this->insideYear($academicYear, $candidate->endsOn, $candidate->endsOn),
                'keep_current' => false,
            ];
        }

        // A QUARTA OPÇÃO, e só quando ela existe de facto: manter a data que o
        // período já tem. Sem isto, um professor confrontado com três datas de que
        // não gosta não tinha maneira de dizer «nenhuma» sem deixar a linha por
        // fazer — e «deixar por fazer» e «decidi ficar como está» não são a mesma
        // resposta.
        if ($current !== null && $semester->isAmbiguous()) {
            $candidates[] = [
                'value' => $current->ends_on->toDateString(),
                'cohort' => null,
                'raw_text' => null,
                'in_year' => true,
                'keep_current' => true,
            ];
        }

        $unambiguous = $semester->unambiguousEnd();
        $startsOn = $semester->startsOn;

        $state = $this->semesterState($semester, $candidates, $current, $startsOn, $unambiguous, $academicYear);

        return [
            'key' => "semester-{$index}",
            'destination' => 'academic_period',
            'label' => $semester->label,
            'kind' => AcademicPeriodKind::Semester->value,
            'sequence' => $semester->sequence,
            'starts_on' => $startsOn,
            'raw_start' => $semester->rawStart,
            // `null` quando há escolha por fazer, e é isso mesmo que a página
            // recebe: não há aqui nenhuma das três datas «já lá posta» à espera de
            // ser confirmada por distração.
            'ends_on' => $unambiguous,
            'end_candidates' => $candidates,
            'state' => $state,
            'current' => $current === null ? null : [
                'ulid' => $current->ulid,
                'label' => $current->label,
                'kind_label' => $current->kind->label(),
                'starts_on' => $current->starts_on->toDateString(),
                'ends_on' => $current->ends_on->toDateString(),
            ],
            'include' => $state === self::STATE_NEW,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function semesterState(
        ParsedSemester $semester,
        array $candidates,
        ?AcademicPeriod $current,
        ?string $startsOn,
        ?string $unambiguous,
        AcademicYear $academicYear,
    ): string {
        // Sem data de início legível não há período nenhum a propor. Trata-se como
        // «fora do ano» — a linha mostra-se, explica-se e não se pode selecionar —
        // em vez de se inventar uma data para ela poder existir.
        if ($startsOn === null || $candidates === []) {
            return self::STATE_OUT_OF_YEAR;
        }

        if (! $this->insideYear($academicYear, $startsOn, $startsOn)) {
            return self::STATE_OUT_OF_YEAR;
        }

        if (! collect($candidates)->contains(fn (array $candidate): bool => (bool) $candidate['in_year'])) {
            return self::STATE_OUT_OF_YEAR;
        }

        if ($semester->isAmbiguous()) {
            return self::STATE_NEEDS_CHOICE;
        }

        if ($current === null) {
            return self::STATE_NEW;
        }

        return $current->starts_on->toDateString() === $startsOn && $current->ends_on->toDateString() === $unambiguous
            ? self::STATE_EXISTS
            : self::STATE_CHANGED;
    }

    // ─────────────────────────────────────────────────────────────── exceções

    /**
     * @param  Collection<int, AcademicCalendarException>  $existing
     * @return array<string, mixed>
     */
    private function exceptionProposal(ParsedCalendarRange $range, string $key, $existing, AcademicYear $academicYear): array
    {
        // A MESMA REGRA QUE A SUGESTÃO DE FERIADOS USA, e a mesma que a confirmação
        // volta a correr contra a base de dados: não há aqui uma segunda opinião
        // sobre o que conta como duplicado.
        $match = $this->matcher->match(
            $range->type,
            $range->startsOn,
            $range->endsOn,
            $range->title,
            $existing,
        );

        $inYear = $this->insideYear($academicYear, $range->startsOn, $range->endsOn);

        $state = match (true) {
            ! $inYear => self::STATE_OUT_OF_YEAR,
            $match->matched() => (string) $match->state,
            default => self::STATE_NEW,
        };

        return [
            'key' => $key,
            'destination' => 'academic_calendar_exception',
            'type' => $range->type->value,
            'type_label' => $range->type->label(),
            'type_short_label' => $range->type->shortLabel(),
            'title' => $range->title,
            'starts_on' => $range->startsOn,
            'ends_on' => $range->endsOn,
            'note' => $range->note,
            'raw_text' => $range->rawText,
            'state' => $state,
            'current' => $match->current,
            'include' => $state === self::STATE_NEW,
        ];
    }

    // ────────────────────────────────────────────────── acontecimentos escolares

    /**
     * @return array<string, mixed>
     */
    private function markerProposal(ParsedCalendarMarker $marker, int $index, AcademicYear $academicYear): array
    {
        $inYear = $this->insideYear($academicYear, $marker->date, $marker->date);

        return [
            'key' => "event-{$index}",
            'destination' => 'calendar_event',
            'type' => $marker->type->value,
            'type_label' => $marker->type->label(),
            'title' => $marker->title,
            'starts_on' => $marker->date,
            'ends_on' => $marker->date,
            'note' => null,
            'raw_text' => $marker->rawText,
            // A explicação viaja com a proposta e não vive só na página: é ela que
            // torna a linha uma escolha informada. E SÃO DUAS, porque as duas razões
            // são mesmo diferentes — dizer «não sei classificar isto» sobre uma
            // reunião seria falso, e dizer «é um acontecimento como os outros» sobre
            // um fim de coorte escondia a única coisa que ali é preciso explicar.
            'explanation' => $marker->kind === ParsedCalendarMarkerKind::CohortEnd
                ? __('Nomeia o fim do ano letivo de um grupo de anos de escolaridade. Esta aplicação não distingue anos de escolaridade dentro de um período, por isso não pode ser guardado como data de fim de período — fica como acontecimento, se quiser guardá-lo.')
                : __('O documento marca esta data sem lhe chamar feriado nem interrupção. Fica como acontecimento do seu calendário e não retira aulas a este dia.'),
            // SEMPRE POR CONFIRMAR (§29). Um acontecimento é PESSOAL — fica com o
            // nome de quem importa —, e nada disto é pré-selecionado por comodidade.
            'state' => $inYear ? self::STATE_NEW : self::STATE_OUT_OF_YEAR,
            'current' => null,
            'include' => false,
        ];
    }

    // ─────────────────────────────────────────────────────────────── utilitários

    /**
     * Uma linha só se propõe se couber INTEIRA no ano letivo selecionado — a mesma
     * condição que `AcademicYearRequest` já impõe às exceções e aos períodos
     * escritos à mão, feita aqui para que a página a possa explicar em vez de a
     * gravação a rejeitar sem contexto.
     */
    private function insideYear(AcademicYear $academicYear, string $startsOn, string $endsOn): bool
    {
        return $startsOn >= $academicYear->starts_on->toDateString()
            && $endsOn <= $academicYear->ends_on->toDateString();
    }

    /**
     * Dois rótulos são o mesmo período quando só diferem em maiúsculas e em
     * espaços. E MAIS NADA: «1.º Semestre» e «1º Semestre» ficam DIFERENTES de
     * propósito — aproximar textos aqui era arriscar sobrescrever a estrutura de um
     * ano com base num palpite, e a alternativa (uma linha «novo» que o professor
     * vê e desmarca) é visível e reversível.
     */
    private function sameLabel(string $left, string $right): bool
    {
        // A MESMA definição de «normalizado» que emparelha as designações das
        // exceções, e não uma segunda escrita aqui ao lado. Mudou de casa quando
        // ganhou um segundo cliente; a regra não mudou uma letra.
        return MatchAcademicCalendarExceptions::normalise($left)
            === MatchAcademicCalendarExceptions::normalise($right);
    }

    /**
     * @param  list<array<string, mixed>>  ...$groups
     * @return array<string, int>
     */
    private function counts(array ...$groups): array
    {
        $counts = [
            self::STATE_NEW => 0,
            self::STATE_EXISTS => 0,
            self::STATE_CORRESPONDENCE => 0,
            self::STATE_CHANGED => 0,
            self::STATE_CONFLICT => 0,
            self::STATE_NEEDS_CHOICE => 0,
            self::STATE_OUT_OF_YEAR => 0,
        ];

        foreach ($groups as $group) {
            foreach ($group as $row) {
                $state = (string) $row['state'];
                $counts[$state] = ($counts[$state] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
