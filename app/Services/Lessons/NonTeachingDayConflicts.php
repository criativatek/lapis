<?php

namespace App\Services\Lessons;

use App\Models\AcademicCalendarException;
use App\Models\AcademicYear;
use App\Models\CancelledLessonOccurrence;
use App\Models\Lesson;
use App\Models\LessonOrigin;
use App\Models\LessonPlan;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\SchoolClass;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * «Que aulas JÁ EXISTENTES caem hoje num dia não letivo, e o que se pode fazer a
 * cada uma?» — a classificação da Fatia 2 da reconciliação com o calendário.
 *
 * SÓ LEITURA. Não escreve, não bloqueia e não decide nada: devolve a
 * classificação, e quem a executa (ReconcileLessonsWithCalendarExceptions) é
 * que a aplica. Por isso serve igualmente à pré-visualização, à execução e à
 * sinalização «conflito com o calendário», que é DERIVADA EM LEITURA — não há
 * coluna nova: uma aula está em conflito enquanto o seu dia for de exceção.
 *
 * Três classes, por aula (`kind`):
 *
 *  - A, RETIRÁVEL (vazia): aula do horário, aberta, sem número indicado, sem
 *    assiduidade nem rascunhos, sem plano pendente e sem sumário com conteúdo.
 *    Nada nela se perde ao retirá-la.
 *  - B, COM PLANEAMENTO: como A, mas com um sumário de preparação (texto). O
 *    planeamento desce para a próxima ocorrência válida do mesmo público
 *    (ShiftLessonPlanning) antes de a aula ser retirada. Só é automática se não
 *    houver uma aula FECHADA do mesmo público depois (senão o planeamento
 *    saltaria por cima do histórico), se houver para onde o levar e se TODA a
 *    cadeia que o deslocamento percorre for feita de «destinos simples» (ver
 *    `isSimpleDestination`): uma aula aberta com faltas, número indicado,
 *    revisão, plano pendente, proveniência sem texto ou já no passado nunca é
 *    tocada em cascata — a B passa a C (`protected_lesson_after`).
 *  - C, REVISÃO INDIVIDUAL: tudo o resto, e NUNCA se toca. Fechada, lecionada,
 *    com assiduidade ou rascunhos, com número indicado, manual, com plano
 *    pendente, com um sumário que não se desloca sem perder algo (proveniência
 *    de sequência, revisão), de uma turma arquivada, ou de uma LIÇÃO
 *    PARTILHADA (T1/T2) que não sai inteira nesta operação: basta que uma
 *    aula da lição não vá ser retirada agora — uma C, ou uma irmã noutro dia,
 *    letivo ou não, protegida ou vazia — para nenhuma sair (`shared_unit`).
 *    Retirar só a T1 deixava a T2 com a lição e a T1 a dar o conteúdo dela
 *    numa aula com outro número, para sempre e sem aviso. Passar a chave da
 *    lição para a aula seguinte, como RecordLessonOutcome::handOverLessonUnit
 *    faz, fica para uma evolução futura.
 *
 * A CADEIA SALTA SÓ O QUE ESTA OPERAÇÃO RETIRA: as A e B da classificação
 * corrente. Uma aula aberta num dia não letivo que NÃO vai ser retirada (uma C
 * — ou uma aula fora do âmbito pedido) é um elo NÃO simples: saltá-la punha o
 * planeamento anterior depois do dela. A B que a atravessaria passa a C
 * (`protected_lesson_after`) — e deixa, por sua vez, de ser retirada, o que
 * pode tornar não simples a cadeia de outra B: por isso a classificação itera
 * até estabilizar.
 *
 * A REGRA DE «DIA NÃO LETIVO» é a de ScheduleOccurrences::isNonTeachingDay (a
 * mesma de `between()` e de `isNonTeachingDateIn()`); a cópia que vive em
 * MaterializeLessonsForRange continua à parte e o teste de paridade mantém as
 * duas a ler as mesmas datas. O dia da aula é o dia LOCAL (Europe/Lisbon).
 *
 * POR (TURMA, GRUPO), SEMPRE. Cada aula é avaliada na sua turma; os grupos só
 * se tocam pela lição partilhada (`lesson_unit_key`), que é a regra acima.
 *
 * A CLASSIFICAÇÃO É FINAL. `classify()` já devolve o resultado de TODOS os
 * passos que decidem o que se pode executar — a cadeia do planeamento, a lição
 * partilhada, o âmbito (o responsável não desloca planeamento) e o limite de
 * planeamentos por execução —, até estabilizar. O aviso do professor
 * (LessonCalendarConflictController::index), a página da aula e a
 * reconciliação (pré-visualização e execução) partem da MESMA classificação, e
 * a reconciliação decide tudo ANTES da primeira escrita.
 *
 * O QUE A SIMULAÇÃO NÃO VÊ. A cadeia de cada B é simulada só em leitura; a
 * execução desloca e materializa de verdade, e quando o destino real não é o
 * previsto a B passa a C (`resettleWithReview`) — reclassificação que SÓ a
 * pré-visualização e a execução fazem (o aviso e a página da aula não deslocam
 * nada). A fonte conhecida dessa divergência — uma ocorrência do horário que a
 * materialização não deixa nascer, porque um tempo de id menor faz nascer
 * primeiro uma aula que colide com ela — é simulada aqui (`births`), e por isso
 * o aviso, a página da aula e a pré-visualização dão a mesma resposta. O que
 * resta são estados que mudam entre a leitura e a escrita (a concorrência que o
 * `plan_token` recusa) e a materialização que apaga aulas vazias fora de
 * vigência (ReconcileLessonsWithSlotValidity), que mudam o DESTINO de um
 * planeamento mas não a sua classe. Se, mesmo assim, a execução reclassificar,
 * a pré-visualização mostra essa aula em «para rever», com a razão.
 *
 * @phpstan-type ConflictRow array{lesson: Lesson, kind: string, reasons: list<string>, exception_ids: list<int>, chain: list<int>}
 */
final class NonTeachingDayConflicts
{
    public const RETIRABLE = 'A';

    public const PLANNED = 'B';

    public const REVIEW = 'C';

    /**
     * O máximo de aulas com planeamento (B) que uma execução desloca. As que
     * passam do limite ficam em C (`batch_limit`) e saem na execução seguinte:
     * um deslocamento em cascata de centenas de aulas não cabe num pedido.
     * Ficam as MAIS ANTIGAS para depois: deslocar mais tarde uma B anterior
     * empurra para a frente o que já desceu, e a ordem do planeamento mantém-se
     * (a mesma razão por que a execução desce a última primeiro). `lapis.calendar_reconciliation_max_planned`
     * substitui-o (os testes usam-no para não terem de criar 151 aulas).
     */
    public const MAX_PLANNED_PER_RUN = 150;

    private const TIMEZONE = 'Europe/Lisbon';

    private const FIELDS = ['content', 'resources', 'homework', 'private_notes'];

    public function __construct(
        private readonly ScheduleOccurrences $occurrences,
        private readonly ShiftLessonPlanning $planning,
        private readonly LessonConflicts $lessonConflicts,
    ) {}

    /**
     * @param  iterable<SchoolClass>  $classes  as turmas a examinar; só contam as do ano
     * @param  CarbonImmutable|null  $from  limita a janela (dia inclusivo); NULL = sem limite
     * @param  bool  $shiftsPlanning  o âmbito do professor (desloca B); falso = o do responsável
     * @return list<ConflictRow> por ordem cronológica; `chain` = as aulas EXISTENTES que o deslocamento de uma B vai tocar
     */
    public function classify(iterable $classes, AcademicYear $year, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null, bool $shiftsPlanning = true): array
    {
        $byClass = [];

        foreach ($classes as $class) {
            if ((int) $class->academic_year_id === (int) $year->getKey()) {
                $byClass[(int) $class->getKey()] = $class;
            }
        }

        $exceptions = $year->exceptions()->get();

        if ($byClass === [] || $exceptions->isEmpty()) {
            return [];
        }

        $ranges = $this->ranges($exceptions, $from, $to);

        if ($ranges === []) {
            return [];
        }

        /** @var Collection<int, Lesson> $lessons */
        $lessons = Lesson::query()
            ->whereIn('class_id', array_keys($byClass))
            ->where(function (Builder $query) use ($ranges): void {
                foreach ($ranges as [$start, $end]) {
                    $query->orWhere(fn (Builder $range) => $range->where('starts_at', '>=', $start)->where('starts_at', '<', $end));
                }
            })
            ->with(['summary', 'plan'])
            ->withExists(['summary', 'plan', 'attendances'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Lesson $lesson): bool => $this->occurrences->isNonTeachingDay(
                CarbonImmutable::instance($lesson->starts_at)->setTimezone(self::TIMEZONE),
                $exceptions,
            ))
            ->values();

        $rows = [];

        foreach ($lessons as $lesson) {
            [$kind, $reasons] = $this->classifyOne($lesson, $byClass[(int) $lesson->class_id]);

            $rows[] = [
                'lesson' => $lesson,
                'kind' => $kind,
                'reasons' => $reasons,
                'exception_ids' => $this->coveringExceptions($lesson, $exceptions),
                'chain' => [],
            ];
        }

        return $this->settle($rows, $byClass, $year, $exceptions, $shiftsPlanning);
    }

    /**
     * As linhas indicadas passam a C pelo motivo dado e a classificação volta a
     * estabilizar — a lição partilhada e as cadeias das outras B, que podiam
     * contar com elas como retiradas. Para a execução, quando uma B que a
     * simulação dava como deslocável não encontra destino (ver
     * ReconcileLessonsWithCalendarExceptions): a mesma regra, aplicada de novo,
     * e não uma exceção à parte.
     *
     * @param  list<ConflictRow>  $rows  a classificação final de `classify()`
     * @param  array<int, string>  $reviews  índice da linha => motivo
     * @param  iterable<SchoolClass>  $classes
     * @return list<ConflictRow>
     */
    public function resettleWithReview(array $rows, array $reviews, iterable $classes, AcademicYear $year, bool $shiftsPlanning = true): array
    {
        foreach ($reviews as $index => $reason) {
            if (isset($rows[$index]) && $rows[$index]['kind'] !== self::REVIEW) {
                $rows[$index] = self::toReview($rows[$index], $reason);
            }
        }

        $byClass = [];

        foreach ($classes as $class) {
            $byClass[(int) $class->getKey()] = $class;
        }

        return $this->settle($rows, $byClass, $year, $year->exceptions()->get(), $shiftsPlanning, batchLimit: false);
    }

    /**
     * A mesma linha, agora em C pelo motivo dado. As linhas só descem para C,
     * nunca o contrário.
     *
     * @param  ConflictRow  $row
     * @return ConflictRow
     */
    public static function toReview(array $row, string $reason): array
    {
        return ['lesson' => $row['lesson'], 'kind' => self::REVIEW, 'reasons' => [$reason], 'exception_ids' => $row['exception_ids'], 'chain' => []];
    }

    public static function maxPlannedPerRun(): int
    {
        return max(1, (int) config('lapis.calendar_reconciliation_max_planned', self::MAX_PLANNED_PER_RUN));
    }

    /**
     * «Um destino simples»: uma aula onde o planeamento de outra pode aterrar —
     * e de onde o seu próprio planeamento pode sair — sem que nada do que lá
     * está se perca nem se altere em silêncio. Aberta, do horário, sem número
     * indicado, sem assiduidade (nem rascunho), sem plano pendente, sem um
     * sumário revisto nem uma linha só com proveniência de sequência (que
     * `writeInto` sobrescreveria), e AINDA POR ACONTECER: uma aula aberta já no
     * passado pode ter sido dada sem ter sido fechada, e o sumário dela é o que
     * se passou, não o que se planeou.
     *
     * Lê o atributo `attendances_exists` e as relações `summary`/`plan` se
     * vierem carregados; senão pergunta.
     */
    public function isSimpleDestination(Lesson $lesson): bool
    {
        if ($lesson->isClosed()
            || $lesson->origin !== LessonOrigin::Schedule
            || $lesson->lesson_number_manual
            || $lesson->lesson_number_end !== null
            || $lesson->attendance_recorded_at !== null
            || ! $lesson->starts_at->greaterThan(CarbonImmutable::now())) {
            return false;
        }

        $hasAttendances = array_key_exists('attendances_exists', $lesson->getAttributes())
            ? (bool) $lesson->getAttribute('attendances_exists')
            : $lesson->attendances()->exists();

        if ($hasAttendances) {
            return false;
        }

        /** @var LessonPlan|null $plan */
        $plan = $lesson->relationLoaded('plan') ? $lesson->plan : $lesson->plan()->first();

        if ($plan !== null && trim((string) $plan->planned_summary) !== '') {
            return false;
        }

        /** @var LessonSummary|null $summary */
        $summary = $lesson->relationLoaded('summary') ? $lesson->summary : $lesson->summary()->first();

        if ($summary === null) {
            return true;
        }

        if ($summary->reviewed_at !== null || $summary->reviewed_by !== null) {
            return false;
        }

        return $this->summaryHasText($summary) || ! $this->hasProvenance($summary);
    }

    /**
     * Os passos que dependem uns dos outros, até estabilizar: o limite por
     * execução, a cadeia de cada B, a lição partilhada e, no âmbito do
     * responsável, as A que partilham a lição com uma B. Cada passo só passa
     * aulas para C — nunca o contrário —, por isso o ciclo termina.
     *
     * @param  list<ConflictRow>  $rows
     * @param  array<int, SchoolClass>  $byClass
     * @param  Collection<int, AcademicCalendarException>  $exceptions
     * @return list<ConflictRow>
     */
    private function settle(array $rows, array $byClass, AcademicYear $year, Collection $exceptions, bool $shiftsPlanning, bool $batchLimit = true): array
    {
        if ($shiftsPlanning && $batchLimit) {
            $rows = $this->applyBatchLimit($rows);
        }

        $blockedSiblings = $this->unitsWithSiblingsOutsideRows($rows);

        do {
            $changed = $this->applySharedUnits($rows, $blockedSiblings, $shiftsPlanning);
            $changed = $this->applyChains($rows, $byClass, $year, $exceptions) || $changed;
        } while ($changed);

        return $rows;
    }

    /**
     * @param  list<ConflictRow>  $rows
     * @return list<ConflictRow>
     */
    private function applyBatchLimit(array $rows): array
    {
        $planned = 0;
        $limit = self::maxPlannedPerRun();

        // Da última para a primeira: as mais recentes são as que saem já.
        foreach (array_reverse($rows, true) as $index => $row) {
            if ($row['kind'] !== self::PLANNED) {
                continue;
            }

            if (++$planned > $limit) {
                $rows[$index] = self::toReview($row, 'batch_limit');
            }
        }

        return $rows;
    }

    /**
     * Os motivos pelos quais a aula é PROTEGIDA — nunca se toca, qualquer que
     * seja o sumário. Vazio = nada a proteger. Só factos da própria aula.
     *
     * @return list<string>
     */
    public function protectedReasons(Lesson $lesson, ?SchoolClass $class = null): array
    {
        $reasons = [];

        if ($class !== null && $class->isArchived()) {
            $reasons[] = 'archived_class';
        }

        if ($lesson->origin !== LessonOrigin::Schedule) {
            $reasons[] = 'manual_origin';
        }

        if ($lesson->status === LessonStatus::Taught) {
            $reasons[] = 'taught';
        }

        if ($lesson->outcome !== null) {
            $reasons[] = 'closed';
        }

        if ($lesson->lesson_number_manual) {
            $reasons[] = 'manual_number';
        }

        if ($lesson->lesson_number_end !== null) {
            $reasons[] = 'legacy_number_end';
        }

        if ($lesson->attendance_recorded_at !== null) {
            $reasons[] = 'attendance_recorded';
        }

        if ((bool) $lesson->getAttribute('attendances_exists')) {
            $reasons[] = 'attendance_draft';
        }

        /** @var LessonPlan|null $plan */
        $plan = $lesson->relationLoaded('plan') ? $lesson->plan : $lesson->plan()->first();

        if ($plan !== null && trim((string) $plan->planned_summary) !== '') {
            $reasons[] = 'pending_plan';
        }

        return $reasons;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function classifyOne(Lesson $lesson, SchoolClass $class): array
    {
        // O CAMINHO RÁPIDO é a definição partilhada de «leva conteúdo»
        // (Lesson::carriesContent, a de ReconcileLessonsWithSlotValidity): se a
        // aula não leva nada — nem linha de sumário, nem de plano, nem faltas —
        // só falta ser do horário, sem fim legado e de uma turma não arquivada.
        if (! $lesson->carriesContent()) {
            $reasons = $this->protectedReasons($lesson, $class);

            return $reasons === [] ? [self::RETIRABLE, []] : [self::REVIEW, $reasons];
        }

        $reasons = $this->protectedReasons($lesson, $class);

        if ($reasons !== []) {
            return [self::REVIEW, $reasons];
        }

        // Aberta, do horário, sem número indicado nem assiduidade: o que a
        // «faz carregar conteúdo» é uma linha de sumário e/ou de plano vazio.
        /** @var LessonSummary|null $summary */
        $summary = $lesson->summary;

        if ($summary === null) {
            return [self::RETIRABLE, []];
        }

        // Vestígios que o deslocamento NÃO leva consigo: a revisão do sumário.
        // Retirar a aula apagá-los-ia em silêncio, por isso a aula não é tocada.
        if ($summary->reviewed_at !== null || $summary->reviewed_by !== null) {
            return [self::REVIEW, ['reviewed_summary']];
        }

        if (! $this->summaryHasText($summary)) {
            // Linha vazia, sem texto: só vale a pena protegê-la se tiver
            // proveniência de sequência (a sequência aplicada reconhece-a).
            return $this->hasProvenance($summary) ? [self::REVIEW, ['sequence_provenance']] : [self::RETIRABLE, []];
        }

        if ($this->planning->closedLessonAfter($lesson) !== null) {
            return [self::REVIEW, ['closed_lesson_after']];
        }

        return [self::PLANNED, []];
    }

    private function summaryHasText(LessonSummary $summary): bool
    {
        foreach (self::FIELDS as $field) {
            $value = $summary->getAttribute($field);

            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function hasProvenance(LessonSummary $summary): bool
    {
        return $summary->lesson_sequence_id !== null
            || $summary->lesson_sequence_item_id !== null
            || $summary->sequence_content_hash !== null;
    }

    /**
     * As lições partilhadas (T1/T2) com uma aula FORA destas linhas — noutro
     * dia (letivo ou não), ou fora da janela pedida —, protegida ou não: essa
     * aula não vai ser retirada nesta operação, e retirar só a T1 desfazia a
     * lição (a T2 ficava com ela e a T1 passava a dar o mesmo conteúdo numa aula
     * com outro número, sem aviso). O caminho conservador: a lição não sai, e
     * as candidatas passam a C (`shared_unit`). As irmãs que estão nas linhas
     * contam pela sua classe (`applySharedUnits`).
     *
     * @param  list<ConflictRow>  $rows
     * @return array<string, true>
     */
    private function unitsWithSiblingsOutsideRows(array $rows): array
    {
        $keys = [];
        $inRows = [];

        foreach ($rows as $row) {
            $lesson = $row['lesson'];
            $inRows[(int) $lesson->getKey()] = true;

            if ($lesson->class_group_id !== null && $lesson->lesson_unit_key !== null) {
                $keys[$lesson->lesson_unit_key] = true;
            }
        }

        if ($keys === []) {
            return [];
        }

        $blocked = [];

        $siblings = Lesson::query()
            ->whereIn('lesson_unit_key', array_keys($keys))
            ->whereNotNull('class_group_id')
            ->get(['id', 'lesson_unit_key']);

        foreach ($siblings as $sibling) {
            if (! isset($inRows[(int) $sibling->getKey()])) {
                $blocked[(string) $sibling->lesson_unit_key] = true;
            }
        }

        return $blocked;
    }

    /**
     * Uma lição partilhada (T1/T2) é TUDO OU NADA: se uma aula da lição não
     * sai — uma C, ou qualquer aula fora destas linhas —, nenhuma sai, e as
     * candidatas da lição passam a C (`shared_unit`). No âmbito do responsável
     * uma B também não sai (só o professor a desloca), por isso as A que
     * partilham a lição com uma B passam a C; a B fica B (aguarda o professor).
     *
     * @param  list<ConflictRow>  $rows
     * @param  array<string, true>  $blockedSiblings
     *
     * @param-out list<ConflictRow>  $rows
     */
    private function applySharedUnits(array &$rows, array $blockedSiblings, bool $shiftsPlanning): bool
    {
        $blocked = $blockedSiblings;
        $planned = [];

        foreach ($rows as $row) {
            $lesson = $row['lesson'];

            if ($lesson->class_group_id === null || $lesson->lesson_unit_key === null) {
                continue;
            }

            // Uma C por `shared_unit` não bloqueia por si: ou a lição já está
            // bloqueada por outra C (que continua C), ou é a A do responsável
            // que espera pela sua B — e essa B não deixa de sair por isso.
            if ($row['kind'] === self::REVIEW && $row['reasons'] !== ['shared_unit']) {
                $blocked[$lesson->lesson_unit_key] = true;
            } elseif ($row['kind'] === self::PLANNED) {
                $planned[$lesson->lesson_unit_key] = true;
            }
        }

        $changed = false;

        foreach ($rows as $index => $row) {
            $lesson = $row['lesson'];
            $key = $lesson->lesson_unit_key;

            if ($row['kind'] === self::REVIEW || $key === null || $lesson->class_group_id === null) {
                continue;
            }

            if (isset($blocked[$key]) || (! $shiftsPlanning && $row['kind'] === self::RETIRABLE && isset($planned[$key]))) {
                $rows[$index] = self::toReview($row, 'shared_unit');
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * A CADEIA DE CADA B, simulada SÓ EM LEITURA, por público (turma, grupo):
     * as B do público descem da última para a primeira (a ordem da execução),
     * cada uma pelas aulas abertas seguintes em dias letivos até à primeira sem
     * planeamento — ou até às ocorrências do horário que a execução vai
     * materializar, que a partir daí contam como aulas com planeamento para as
     * B anteriores. Uma B cuja cadeia toque numa aula que não é «destino
     * simples» passa a C (`protected_lesson_after`); sem destino nenhum, a C
     * (`no_destination`). Repete-se sem elas até estabilizar: menos B, cadeias
     * mais curtas.
     *
     * @param  list<ConflictRow>  $rows
     * @param  array<int, SchoolClass>  $byClass
     * @param  Collection<int, AcademicCalendarException>  $exceptions
     *
     * @param-out list<ConflictRow>  $rows
     */
    private function applyChains(array &$rows, array $byClass, AcademicYear $year, Collection $exceptions): bool
    {
        $audiences = [];

        foreach ($rows as $index => $row) {
            if ($row['kind'] === self::PLANNED) {
                $lesson = $row['lesson'];
                $audiences[$lesson->class_id.'|'.($lesson->class_group_id ?? '-')][] = $index;
            }
        }

        $changed = false;

        foreach ($audiences as $origins) {
            while ($origins !== []) {
                $class = $byClass[(int) $rows[$origins[0]]['lesson']->class_id];
                $failed = false;

                foreach ($this->simulateAudience($rows, $origins, $class, $year, $exceptions) as $index => $result) {
                    if ($result['reason'] === null) {
                        $rows[$index] = ['chain' => $result['chain']] + $rows[$index];

                        continue;
                    }

                    $rows[$index] = self::toReview($rows[$index], $result['reason']);
                    $origins = array_values(array_diff($origins, [$index]));
                    $failed = true;
                    $changed = true;
                }

                if (! $failed) {
                    break;
                }
            }
        }

        return $changed;
    }

    /**
     * @param  list<ConflictRow>  $rows
     * @param  list<int>  $origins  os índices das B deste público, por ordem cronológica
     * @param  Collection<int, AcademicCalendarException>  $exceptions
     * @return array<int, array{reason: string|null, chain: list<int>}>
     */
    private function simulateAudience(array $rows, array $origins, SchoolClass $class, AcademicYear $year, Collection $exceptions): array
    {
        $earliest = $rows[$origins[0]]['lesson'];

        // Saltam-se SÓ as aulas que esta operação retira (A e B, agora): a
        // mesma regra que a execução passa a `fromRetiredLesson`.
        $following = $this->planning->openLessonsAfter($earliest, lock: false, skip: self::retiring($rows));
        $following->load(['summary', 'plan'])->loadExists('attendances');

        // Os nós da cadeia: as aulas abertas existentes e, a seguir, as
        // ocorrências do horário que a execução materializa (`id` NULL).
        $nodes = [];

        foreach ($following as $lesson) {
            /** @var LessonSummary|null $summary */
            $summary = $lesson->summary;

            $nodes[] = [
                'id' => (int) $lesson->getKey(),
                'starts_at' => CarbonImmutable::instance($lesson->starts_at),
                'filled' => $summary !== null && $this->summaryHasText($summary),
                // Uma aula num dia não letivo que fica (não é retirada agora)
                // nunca é destino: nem recebe planeamento num feriado, nem se
                // salta em silêncio.
                'simple' => $this->isSimpleDestination($lesson)
                    && ! $this->occurrences->isNonTeachingDay(CarbonImmutable::instance($lesson->starts_at)->setTimezone(self::TIMEZONE), $exceptions),
            ];
        }

        $candidates = null;
        $results = [];

        foreach (array_reverse($origins) as $index) {
            $origin = $rows[$index]['lesson'];
            $originAt = CarbonImmutable::instance($origin->starts_at);
            $position = 0;

            while ($position < count($nodes) && $this->notAfter($nodes[$position], $originAt, (int) $origin->getKey())) {
                $position++;
            }

            $chain = [];
            $reached = false;

            while ($position < count($nodes)) {
                $chain[] = $position;

                if (! $nodes[$position]['filled']) {
                    $nodes[$position]['filled'] = true;
                    $reached = true;

                    break;
                }

                $position++;
            }

            if (! $reached) {
                $after = $chain === [] ? $originAt : $nodes[$chain[count($chain) - 1]]['starts_at'];
                // Cada B consome no máximo UMA ocorrência, e por ordem: nunca
                // são precisas mais do que as B deste público.
                $candidates ??= $this->scheduleCandidates($class, $year, $origin->class_group_id, $after, count($origins));
                $next = null;

                foreach ($candidates as $candidate) {
                    if ($candidate->greaterThan($after)) {
                        $next = $candidate;

                        break;
                    }
                }

                if ($next !== null) {
                    $nodes[] = ['id' => null, 'starts_at' => $next, 'filled' => true, 'simple' => $next->greaterThan(CarbonImmutable::now())];
                    $chain[] = count($nodes) - 1;
                } elseif ($chain === []) {
                    $results[$index] = ['reason' => 'no_destination', 'chain' => []];

                    continue;
                }
                // Sem ocorrência no horário até ao fim do ano: o último
                // planeamento fica como pendência na última aula da cadeia —
                // que já está na cadeia, e por isso já foi verificada.
            }

            $ids = [];
            $simple = true;

            foreach ($chain as $position) {
                $simple = $simple && $nodes[$position]['simple'];

                if ($nodes[$position]['id'] !== null) {
                    $ids[] = $nodes[$position]['id'];
                }
            }

            $results[$index] = $simple
                ? ['reason' => null, 'chain' => $ids]
                : ['reason' => 'protected_lesson_after', 'chain' => []];
        }

        return $results;
    }

    /**
     * Os ids das aulas que a operação vai retirar, com a classificação de agora:
     * as A e as B. As C ficam — e por isso nunca se saltam.
     *
     * @param  list<ConflictRow>  $rows
     * @return array<int, true>
     */
    public static function retiring(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if ($row['kind'] === self::RETIRABLE || $row['kind'] === self::PLANNED) {
                $ids[(int) $row['lesson']->getKey()] = true;
            }
        }

        return $ids;
    }

    /**
     * O nó está antes da origem (ou é ela)? Pela ordem de `openLessonsAfter`:
     * início, e o id no mesmo instante.
     *
     * @param  array{id: int|null, starts_at: CarbonImmutable, filled: bool, simple: bool}  $node
     */
    private function notAfter(array $node, CarbonImmutable $originAt, int $originId): bool
    {
        if ($node['starts_at']->lessThan($originAt)) {
            return true;
        }

        return $node['starts_at']->equalTo($originAt) && $node['id'] !== null && $node['id'] <= $originId;
    }

    /**
     * As ocorrências do horário DESTE público que a execução pode materializar
     * depois de `$after` — a regra de ShiftLessonPlanning::materializeNextOccurrence,
     * só em leitura: até ao fim do ano e só as que a materialização FARIA NASCER
     * (`births`). Sem isto, a simulação contava com um destino que a execução
     * não consegue criar, e o aviso e a página da aula prometiam uma correção
     * que «Ver e corrigir» nunca fazia.
     *
     * Pára ao fim de `$limit` (as B do público): cada B consome no máximo uma.
     *
     * @return list<CarbonImmutable>
     */
    private function scheduleCandidates(SchoolClass $class, AcademicYear $year, ?int $groupId, CarbonImmutable $after, int $limit): array
    {
        $yearEndsOn = CarbonImmutable::parse($year->ends_on->toDateString(), self::TIMEZONE)->startOfDay();
        $from = $after->setTimezone(self::TIMEZONE)->startOfDay();

        if ($from->greaterThan($yearEndsOn) || $limit < 1) {
            return [];
        }

        $result = [];
        $births = [];

        foreach ($this->occurrences->between($class, $from, $yearEndsOn, $groupId) as $occurrence) {
            if (! $occurrence['starts_at']->greaterThan($after)) {
                continue;
            }

            $day = $occurrence['starts_at']->setTimezone(self::TIMEZONE)->toDateString();
            $births[$day] ??= $this->births($class, $day);

            if (isset($births[$day][$occurrence['slot_id']])) {
                $result[] = $occurrence['starts_at'];
            }

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Os tempos do horário cuja aula a materialização FARIA NASCER neste dia
     * (slot_id => true) — MaterializeLessonsForRange, só em leitura, para o dia
     * inteiro e pela MESMA ordem: os tempos por id, cada ocorrência saltada se
     * estiver eliminada de propósito (CancelledLessonOccurrence), se já houver
     * aula com essa identidade (turma, tempo, início) ou se colidir
     * (LessonConflicts: públicos que se intersetam, horas que se cruzam) com
     * uma aula que já existe — OU com uma que nasce antes dela no mesmo dia.
     * Era esta última que faltava: um tempo da turma inteira (id menor) por cima
     * do tempo da T2 faz nascer a aula da turma inteira, e a da T2 nunca nasce.
     *
     * Uma aula que já existe com a identidade de outro tempo, ou de outro
     * público, não é «nascida» aqui — a materialização também não a cria.
     *
     * @return array<int, true>
     */
    private function births(SchoolClass $class, string $day): array
    {
        $date = CarbonImmutable::parse($day, self::TIMEZONE)->startOfDay();
        $occurrences = $this->occurrences->between($class, $date, $date, anyAudience: true);

        usort($occurrences, fn (array $a, array $b): int => $a['slot_id'] <=> $b['slot_id']);

        $born = [];
        $bornOccurrences = [];

        foreach ($occurrences as $occurrence) {
            $cancelled = CancelledLessonOccurrence::query()
                ->where('class_id', $class->getKey())
                ->where('recurring_lesson_slot_id', $occurrence['slot_id'])
                ->where('occurs_at', $occurrence['starts_at'])
                ->exists();

            if ($cancelled) {
                continue;
            }

            $exists = Lesson::query()
                ->where('class_id', $class->getKey())
                ->where('recurring_lesson_slot_id', $occurrence['slot_id'])
                ->where('starts_at', $occurrence['starts_at'])
                ->exists();

            if ($exists || $this->lessonConflicts->conflictingLesson(
                (int) $class->getKey(),
                $occurrence['class_group_id'],
                $occurrence['starts_at'],
                $occurrence['ends_at'],
            ) !== null) {
                continue;
            }

            foreach ($bornOccurrences as $earlier) {
                if (self::audiencesIntersect($earlier['class_group_id'], $occurrence['class_group_id'])
                    && $earlier['starts_at']->lessThan($occurrence['ends_at'])
                    && $earlier['ends_at']->greaterThan($occurrence['starts_at'])) {
                    continue 2;
                }
            }

            $born[$occurrence['slot_id']] = true;
            $bornOccurrences[] = $occurrence;
        }

        return $born;
    }

    /**
     * A regra de participantes de LessonConflicts: a turma inteira cruza-se com
     * todos; um grupo, consigo próprio e com a turma inteira.
     */
    private static function audiencesIntersect(?int $left, ?int $right): bool
    {
        return $left === null || $right === null || $left === $right;
    }

    /**
     * Os intervalos [início, fim) de instantes que as exceções cobrem, recortados
     * pela janela pedida. Ao dia LOCAL: o início é a meia-noite de Lisboa do
     * primeiro dia e o fim a do dia seguinte ao último.
     *
     * @param  Collection<int, AcademicCalendarException>  $exceptions
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function ranges(Collection $exceptions, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $ranges = [];

        foreach ($exceptions as $exception) {
            $start = CarbonImmutable::parse($exception->starts_on->toDateString(), self::TIMEZONE)->startOfDay();
            $end = CarbonImmutable::parse($exception->ends_on->toDateString(), self::TIMEZONE)->startOfDay();

            if ($from !== null && $from->setTimezone(self::TIMEZONE)->startOfDay()->greaterThan($start)) {
                $start = $from->setTimezone(self::TIMEZONE)->startOfDay();
            }

            if ($to !== null && $to->setTimezone(self::TIMEZONE)->startOfDay()->lessThan($end)) {
                $end = $to->setTimezone(self::TIMEZONE)->startOfDay();
            }

            if ($start->greaterThan($end)) {
                continue;
            }

            $ranges[] = [$start, $end->addDay()->startOfDay()];
        }

        return $ranges;
    }

    /**
     * @param  Collection<int, AcademicCalendarException>  $exceptions
     * @return list<int>
     */
    private function coveringExceptions(Lesson $lesson, Collection $exceptions): array
    {
        $day = CarbonImmutable::instance($lesson->starts_at)->setTimezone(self::TIMEZONE)->toDateString();

        return array_values($exceptions
            ->filter(fn (AcademicCalendarException $exception): bool => $exception->starts_on->toDateString() <= $day
                && $exception->ends_on->toDateString() >= $day)
            ->map(fn (AcademicCalendarException $exception): int => (int) $exception->getKey())
            ->sort()
            ->all());
    }
}
