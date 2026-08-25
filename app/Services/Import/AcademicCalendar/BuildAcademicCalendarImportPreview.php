<?php

namespace App\Services\Import\AcademicCalendar;

use App\Domain\Import\AcademicCalendar\ParsedAcademicCalendar;
use App\Domain\Import\AcademicCalendar\ParsedCalendarMarker;
use App\Domain\Import\AcademicCalendar\ParsedCalendarRange;
use App\Domain\Import\AcademicCalendar\ParsedSemester;
use App\Models\AcademicCalendarException;
use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodKind;
use App\Models\AcademicYear;
use App\Models\CalendarEventType;
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
 * OS SEIS ESTADOS, E O QUE CADA UM AUTORIZA
 *
 *   novo          — nada de parecido existe. Vem pré-selecionado: não há nada para
 *                   destruir e a alternativa era o professor picar quinze caixas
 *                   para dizer «sim» quinze vezes.
 *   já existente  — está lá, igualzinho. Mostra-se e não se faz nada: nem se
 *                   recria, nem se pode selecionar. É isto que torna reimportar o
 *                   mesmo ficheiro uma operação inofensiva.
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
 * O QUE NUNCA VAI PARA DOIS SÍTIOS (§30). Cada facto tem UM destino canónico: as
 * datas dos semestres são AcademicPeriod, os feriados e as interrupções são
 * AcademicCalendarException, e os fins de ano por coorte são CalendarEvent do tipo
 * «outro». Um feriado não é também um acontecimento, e um semestre não é também
 * uma exceção — copiar o mesmo facto para duas tabelas era garantir que um dia
 * discordariam.
 */
class BuildAcademicCalendarImportPreview
{
    public const STATE_NEW = 'new';

    public const STATE_EXISTS = 'exists';

    public const STATE_CHANGED = 'changed';

    public const STATE_CONFLICT = 'conflict';

    public const STATE_NEEDS_CHOICE = 'needs_choice';

    public const STATE_OUT_OF_YEAR = 'out_of_year';

    /**
     * @return array{
     *     semesters: list<array<string, mixed>>,
     *     schoolBreaks: list<array<string, mixed>>,
     *     holidays: list<array<string, mixed>>,
     *     otherItems: list<array<string, mixed>>,
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

        $holidays = [];
        foreach ($calendar->holidays as $index => $range) {
            $holidays[] = $this->exceptionProposal($range, "holiday-{$index}", $exceptions, $academicYear);
        }

        $otherItems = [];
        foreach ($calendar->otherDatedItems as $index => $marker) {
            $otherItems[] = $this->markerProposal($marker, $index, $academicYear);
        }

        return [
            'semesters' => $semesters,
            'schoolBreaks' => $schoolBreaks,
            'holidays' => $holidays,
            'otherItems' => $otherItems,
            'counts' => $this->counts($semesters, $schoolBreaks, $holidays, $otherItems),
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
        $match = $this->matchException($range, $existing);
        $inYear = $this->insideYear($academicYear, $range->startsOn, $range->endsOn);

        $state = match (true) {
            ! $inYear => self::STATE_OUT_OF_YEAR,
            $match['state'] !== null => $match['state'],
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
            'current' => $match['current'],
            'include' => $state === self::STATE_NEW,
        ];
    }

    /**
     * A REGRA DE DEDUPLICAÇÃO, escrita uma vez e usada aqui e na confirmação.
     *
     * A chave natural de uma exceção é `type` + `starts_on` + `ends_on` dentro
     * deste ano letivo — e não o título, que é texto livre e que a escola muda de
     * ano para ano («Natal» e «Interrupção letiva do Natal» são o mesmo intervalo).
     * É por isso que a igualdade de datas manda e o título só aparece para o
     * professor ler.
     *
     * MESMA ESPÉCIE E DATAS EXATAS é a mesma coisa: já existe.
     * MESMA ESPÉCIE E DATAS QUE SE TOCAM SEM SEREM IGUAIS é um conflito: pode ser
     * a interrupção do ano passado que ficou a mais um dia, pode ser a versão nova
     * do calendário — não há regra que saiba qual, e por isso não há regra
     * nenhuma a decidir (§32).
     * ESPÉCIES DIFERENTES NÃO CONFLITUAM, de propósito: um feriado no meio de uma
     * interrupção letiva é exatamente o que o documento real tem no dia 25 de
     * dezembro, e as duas linhas são ambas verdadeiras.
     *
     * NENHUMA APROXIMAÇÃO DE TEXTO, em ponto nenhum: sem distância de Levenshtein,
     * sem «títulos parecidos», sem datas «quase iguais».
     *
     * @param  Collection<int, AcademicCalendarException>  $existing
     * @return array{state: string|null, current: array<string, mixed>|null}
     */
    private function matchException(ParsedCalendarRange $range, $existing): array
    {
        $sameType = $existing->filter(
            fn (AcademicCalendarException $exception): bool => $exception->type === $range->type,
        );

        $exact = $sameType->first(
            fn (AcademicCalendarException $exception): bool => $exception->starts_on->toDateString() === $range->startsOn
                && $exception->ends_on->toDateString() === $range->endsOn,
        );

        if ($exact !== null) {
            return ['state' => self::STATE_EXISTS, 'current' => $this->exceptionPayload($exact)];
        }

        $overlapping = $sameType->first(
            fn (AcademicCalendarException $exception): bool => $exception->starts_on->toDateString() <= $range->endsOn
                && $exception->ends_on->toDateString() >= $range->startsOn,
        );

        if ($overlapping !== null) {
            return ['state' => self::STATE_CONFLICT, 'current' => $this->exceptionPayload($overlapping)];
        }

        return ['state' => null, 'current' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function exceptionPayload(AcademicCalendarException $exception): array
    {
        return [
            'ulid' => $exception->ulid,
            'type_label' => $exception->type->label(),
            'title' => $exception->title,
            'starts_on' => $exception->starts_on->toDateString(),
            'ends_on' => $exception->ends_on->toDateString(),
            'source_label' => $exception->source->label(),
        ];
    }

    // ───────────────────────────────────────────────────── outros acontecimentos

    /**
     * @return array<string, mixed>
     */
    private function markerProposal(ParsedCalendarMarker $marker, int $index, AcademicYear $academicYear): array
    {
        $inYear = $this->insideYear($academicYear, $marker->date, $marker->date);

        return [
            'key' => "other-{$index}",
            'destination' => 'calendar_event',
            'type' => CalendarEventType::Other->value,
            'type_label' => CalendarEventType::Other->label(),
            'title' => $marker->title,
            'starts_on' => $marker->date,
            'ends_on' => $marker->date,
            'raw_text' => $marker->rawText,
            // A explicação viaja com a proposta e não vive só na página: é ela que
            // torna «outro acontecimento» uma escolha informada em vez de uma
            // gaveta onde se despejou o que não se soube arrumar.
            'explanation' => __('Nomeia o fim do ano letivo de um grupo de anos de escolaridade. Esta aplicação não distingue anos de escolaridade dentro de um período, por isso não pode ser guardado como data de fim de período — fica como acontecimento, se quiser guardá-lo.'),
            // SEMPRE POR CONFIRMAR (§29). É a proposta menos segura de toda a
            // importação e é a única que não vem pré-selecionada mesmo sendo nova.
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
        return $this->normalise($left) === $this->normalise($right);
    }

    private function normalise(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
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
