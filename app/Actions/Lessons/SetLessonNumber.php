<?php

namespace App\Actions\Lessons;

use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonSummary;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Lessons\LessonNumbering;
use App\Support\Lessons\LessonNumberingFreeze;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Esta aula é a Lição 11» — o professor indica o número (ou o intervalo
 * «11–12») de uma aula, ou repõe a sugestão automática (0.169.0).
 *
 * A REGRA VIVE EM LessonNumbering; isto é só a alteração explícita. A unidade da
 * aula passa a MANUAL (fixa, nunca recalculada) e as outras unidades LIVRES da
 * turma são re-derivadas à volta dela: depois da Lição 11, as seguintes por
 * preparar passam a 12, 13…, e as anteriores sugerem 10, 9… A história nunca é
 * renumerada: uma unidade lecionada, ou manual, é fixa por construção.
 *
 * O MESMO CÁLCULO PARA A PRÉ-VISUALIZAÇÃO E A EXECUÇÃO. `plan()` devolve o que
 * aconteceria, sem escrever; `execute()` calcula exatamente o mesmo plano, com a
 * turma bloqueada, e só então escreve. Uma pré-visualização que não seja a
 * operação é pior do que nenhuma.
 *
 * OS TRÊS RESULTADOS DO PLANO:
 *  - CONFLITOS bloqueiam: o intervalo sobrepõe-se ao de OUTRA aula fixa da turma
 *    (nunca há duas aulas com a mesma lição);
 *  - EFEITOS são as outras aulas cujo número muda. Se alguma já está registada
 *    (fechada, ou com sumário), a gravação exige a confirmação da lista — um
 *    resumo (hash) dessa lista, para que o professor só confirme o que viu;
 *  - AVISOS não bloqueiam: o número escolhido deixa a ordem incoerente com uma
 *    vizinha, ou uma aula fica sem número.
 *
 * LOCK: a turma primeiro, a aula depois — a ordem de DeleteLesson e da
 * materialização.
 *
 * @phpstan-type Effect array{ulid: string, starts_at: string, context_label: string, from: int|null, from_end: int|null, to: int|null, to_end: int|null, recorded: bool}
 * @phpstan-type Plan array{changed: bool, result: array{start: int|null, end: int|null, manual: bool}, conflicts: list<string>, effects: list<Effect>, warnings: list<string>, requires_confirmation: bool, confirmation: string|null, following_from: int|null}
 * @phpstan-type Records list<array{lessons: list<Lesson>, kind: string, start: int|null, end: int|null}>
 * @phpstan-type Projection array{records: Records, changes: array<int, array{lesson: Lesson, from: int|null, from_end: int|null, from_manual: bool, to: int|null, to_end: int|null, manual: bool}>, links: array<int, string>, target: int|null}
 */
class SetLessonNumber
{
    private const TIMEZONE = 'Europe/Lisbon';

    public function __construct(
        protected AuditLog $audit,
        protected LessonNumbering $numbering,
    ) {}

    /**
     * O que a alteração FARIA, sem escrever nada.
     *
     * @return Plan
     *
     * @throws ValidationException numa aula em que o professor esteve ausente
     */
    public function plan(Lesson $lesson, NumberChange $change): array
    {
        return $this->build($lesson, $change)['plan'];
    }

    /**
     * @return Plan
     *
     * @throws ValidationException
     */
    public function execute(Lesson $lesson, NumberChange $change, ?string $confirmation, User $actor): array
    {
        return DB::transaction(function () use ($actor, $change, $confirmation, $lesson): array {
            // A turma primeiro, a aula depois (a ordem de DeleteLesson).
            SchoolClass::query()->whereKey($lesson->class_id)->lockForUpdate()->first();

            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

            ['plan' => $plan, 'projection' => $projection] = $this->build($locked, $change);

            if (! $plan['changed']) {
                return $plan;
            }

            if ($plan['conflicts'] !== []) {
                throw ValidationException::withMessages(['lesson_number' => $plan['conflicts'][0]]);
            }

            if ($plan['requires_confirmation'] && ($confirmation === null || ! hash_equals((string) $plan['confirmation'], $confirmation))) {
                throw ValidationException::withMessages([
                    'lesson_number' => __('Esta alteração muda o número de outras aulas já registadas. Revê a lista e confirma.'),
                ]);
            }

            $fromStart = $locked->lesson_number;
            $fromEnd = $locked->lesson_number_end;

            $this->numbering->commit($projection);

            // SÓ NÚMEROS E CONTAGENS: nunca texto de sumário nem dados de alunos.
            $this->audit->record(
                'lesson.number_set',
                $locked,
                $actor,
                'Número da aula alterado.',
                [
                    'from' => $fromStart,
                    'from_end' => $fromEnd,
                    'to' => $plan['result']['start'],
                    'to_end' => $plan['result']['end'],
                    'manual' => $plan['result']['manual'],
                    'mode' => $change->mode(),
                    'affected_lessons' => count($plan['effects']),
                    'affected_recorded_lessons' => count(array_filter($plan['effects'], fn (array $effect): bool => $effect['recorded'])),
                ],
            );

            return $plan;
        });
    }

    /**
     * @return array{plan: Plan, projection: Projection}
     *
     * @throws ValidationException
     */
    private function build(Lesson $lesson, NumberChange $change): array
    {
        // A CONTENÇÃO DA RECUPERAÇÃO (docs/lesson-numbering-recovery.md): com a
        // numeração congelada nada se indica nem se repõe — nem na
        // pré-visualização, que devolve o mesmo 422. O sumário grava-se na mesma.
        if (LessonNumberingFreeze::frozen()) {
            throw ValidationException::withMessages(['lesson_number' => LessonNumberingFreeze::refusal()]);
        }

        if ($lesson->outcome === LessonOutcome::TeacherAbsent) {
            throw ValidationException::withMessages([
                'lesson_number' => __('Numa aula em que o professor esteve ausente não há lição: esta aula não tem número.'),
            ]);
        }

        $stored = [
            'start' => $lesson->lesson_number,
            'end' => $lesson->lesson_number_end,
            'manual' => (bool) $lesson->lesson_number_manual,
        ];

        // SEM MUDANÇA: o número pedido é o que a aula já tem — e, se a aula não
        // é manual, o professor deixou a sugestão: continua automática.
        $unchanged = $change->manual
            ? $stored['start'] === $change->start && $stored['end'] === $change->end && ($stored['manual'] || $change->end === null)
            : ! $stored['manual'];

        if ($unchanged) {
            return [
                'plan' => $this->shape(false, $stored, [], [], [], null),
                'projection' => ['records' => [], 'changes' => [], 'links' => [], 'target' => null],
            ];
        }

        $projection = $this->numbering->projectClass((int) $lesson->class_id, (int) $lesson->getKey(), [
            'mode' => $change->mode(),
            'start' => $change->start,
            'end' => $change->end,
        ]);

        $records = $projection['records'];
        $target = $projection['target'];

        if ($target === null) {
            throw ValidationException::withMessages([
                'lesson_number' => __('Não foi possível encontrar esta aula na sequência da turma.'),
            ]);
        }

        $result = [
            'start' => $records[$target]['start'],
            // Modo automático: a derivação só dá um início; nunca um fim.
            'end' => $change->manual ? $records[$target]['end'] : null,
            'manual' => $change->manual,
        ];

        $conflicts = $this->conflicts($records, $target, $result, $change);
        $effects = $this->effects($projection['changes'], (int) $lesson->getKey());
        $recorded = array_values(array_filter($effects, fn (array $effect): bool => $effect['recorded']));

        $plan = $this->shape(
            true,
            $result,
            $conflicts,
            $effects,
            $conflicts === [] ? $this->warnings($records, $target, $result, $effects) : [],
            $this->followingFrom($records, $target),
        );
        $plan['requires_confirmation'] = $recorded !== [];
        $plan['confirmation'] = $recorded === [] ? null : $this->confirmationFor($recorded);

        return ['plan' => $plan, 'projection' => $projection];
    }

    /**
     * @param  array{start: int|null, end: int|null, manual: bool}  $result
     * @param  list<string>  $conflicts
     * @param  list<Effect>  $effects
     * @param  list<string>  $warnings
     * @return Plan
     */
    private function shape(bool $changed, array $result, array $conflicts, array $effects, array $warnings, ?int $followingFrom): array
    {
        return [
            'changed' => $changed,
            'result' => $result,
            'conflicts' => $conflicts,
            'effects' => $effects,
            'warnings' => $warnings,
            'requires_confirmation' => false,
            'confirmation' => null,
            'following_from' => $followingFrom,
        ];
    }

    /**
     * O intervalo da unidade da aula sobrepõe-se ao de OUTRA unidade fixa?
     * (Manual ou histórica: ambas guardam o número que têm.)
     *
     * @param  Records  $records
     * @param  array{start: int|null, end: int|null, manual: bool}  $result
     * @return list<string>
     */
    private function conflicts(array $records, int $target, array $result, NumberChange $change): array
    {
        if ($result['start'] === null) {
            return [__('Não há número automático livre para esta aula: indique o número.')];
        }

        if (! $change->manual) {
            return [];
        }

        $from = $result['start'];
        $to = $result['end'] ?? $from;
        $conflicts = [];

        foreach ($records as $index => $record) {
            if ($index === $target || $record['kind'] === 'free' || $record['start'] === null) {
                continue;
            }

            $otherFrom = $record['start'];
            $otherTo = $record['end'] ?? $otherFrom;

            if ($otherFrom > $to || $otherTo < $from) {
                continue;
            }

            $other = $record['lessons'][0];
            $other->loadMissing(['schoolClass', 'classGroup']);
            $when = $other->starts_at->setTimezone(self::TIMEZONE)->format('d/m/Y');

            $conflicts[] = $result['end'] === null
                ? __('A Lição :number já está atribuída à aula de :date (:context).', [
                    'number' => (string) $from,
                    'date' => $when,
                    'context' => $other->contextLabel(),
                ])
                : __('As lições :range sobrepõem-se à Lição :other da aula de :date (:context).', [
                    'range' => (string) Lesson::formatLessonNumber($from, $result['end']),
                    'other' => (string) Lesson::formatLessonNumber(max($from, $otherFrom), min($to, $otherTo)),
                    'date' => $when,
                    'context' => $other->contextLabel(),
                ]);
        }

        return $conflicts;
    }

    /**
     * As OUTRAS aulas cujo número (início ou fim) muda — os parceiros T1/T2 da
     * mesma unidade incluídos, e as que ficam sem número.
     *
     * @param  array<int, array{lesson: Lesson, from: int|null, from_end: int|null, from_manual: bool, to: int|null, to_end: int|null, manual: bool}>  $changes
     * @return list<Effect>
     */
    private function effects(array $changes, int $lessonId): array
    {
        $others = array_filter(
            $changes,
            fn (array $change, int $id): bool => $id !== $lessonId && ($change['from'] !== $change['to'] || $change['from_end'] !== $change['to_end']),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($others === []) {
            return [];
        }

        // Um sumário com texto em qualquer um dos quatro campos conta como registo.
        $withText = LessonSummary::query()
            ->whereIn('lesson_id', array_keys($others))
            ->get()
            ->filter(fn (LessonSummary $summary): bool => collect([$summary->content, $summary->private_notes, $summary->resources, $summary->homework])
                ->contains(fn (?string $value): bool => $value !== null && trim($value) !== ''))
            ->pluck('lesson_id')
            ->flip();

        $effects = [];

        foreach ($others as $id => $change) {
            $lesson = $change['lesson'];
            $lesson->loadMissing(['schoolClass', 'classGroup']);

            $effects[] = [
                'ulid' => $lesson->ulid,
                'starts_at' => $lesson->starts_at->toIso8601String(),
                'context_label' => $lesson->contextLabel(),
                'from' => $change['from'],
                'from_end' => $change['from_end'],
                'to' => $change['to'],
                'to_end' => $change['to_end'],
                'recorded' => $lesson->isClosed() || $withText->has($id),
            ];
        }

        usort($effects, fn (array $left, array $right): int => [$left['starts_at'], $left['ulid']] <=> [$right['starts_at'], $right['ulid']]);

        return $effects;
    }

    /**
     * O resumo da lista de efeitos registados: o que o professor viu é o que
     * confirma. Ordenado por aula, para não depender da ordem das consultas.
     *
     * @param  list<Effect>  $recorded
     */
    private function confirmationFor(array $recorded): string
    {
        $rows = array_map(
            fn (array $effect): array => [$effect['ulid'], $effect['from'], $effect['from_end'], $effect['to'], $effect['to_end']],
            $recorded,
        );

        usort($rows, fn (array $left, array $right): int => strcmp($left[0], $right[0]));

        return hash('sha256', (string) json_encode($rows));
    }

    /**
     * Avisos (não bloqueiam): a vizinha numerada mais próxima, depois da
     * derivação, tem um número incoerente com a ordem das aulas; ou uma aula
     * fica sem número.
     *
     * @param  Records  $records
     * @param  array{start: int|null, end: int|null, manual: bool}  $result
     * @param  list<Effect>  $effects
     * @return list<string>
     */
    private function warnings(array $records, int $target, array $result, array $effects): array
    {
        $warnings = [];
        $start = (int) $result['start'];
        $end = $result['end'] ?? $start;

        for ($index = $target - 1; $index >= 0; $index--) {
            if ($records[$index]['start'] === null) {
                continue;
            }

            if (($records[$index]['end'] ?? $records[$index]['start']) >= $start) {
                $warnings[] = __('A aula de :date (Lição :number) vem antes desta e tem um número superior. Os números das outras aulas não foram alterados.', [
                    'date' => $records[$index]['lessons'][0]->starts_at->setTimezone(self::TIMEZONE)->format('d/m/Y'),
                    'number' => (string) Lesson::formatLessonNumber($records[$index]['start'], $records[$index]['end']),
                ]);
            }

            break;
        }

        for ($index = $target + 1; $index < count($records); $index++) {
            if ($records[$index]['start'] === null) {
                continue;
            }

            if ($records[$index]['start'] <= $end) {
                $warnings[] = __('A aula de :date (Lição :number) vem depois desta e tem um número inferior. Os números das outras aulas não foram alterados.', [
                    'date' => $records[$index]['lessons'][0]->starts_at->setTimezone(self::TIMEZONE)->format('d/m/Y'),
                    'number' => (string) Lesson::formatLessonNumber($records[$index]['start'], $records[$index]['end']),
                ]);
            }

            break;
        }

        foreach ($effects as $effect) {
            if ($effect['to'] === null) {
                $warnings[] = __('A aula de :date (:context) fica sem número: não há um número livre entre as aulas vizinhas.', [
                    'date' => Carbon::parse($effect['starts_at'])->setTimezone(self::TIMEZONE)->format('d/m/Y'),
                    'context' => $effect['context_label'],
                ]);
            }
        }

        return $warnings;
    }

    /**
     * O número a partir do qual a próxima unidade LIVRE passa a ser numerada —
     * «as aulas seguintes passam a ser numeradas a partir da Lição 12».
     *
     * @param  Records  $records
     */
    private function followingFrom(array $records, int $target): ?int
    {
        for ($index = $target + 1; $index < count($records); $index++) {
            if ($records[$index]['kind'] === 'free' && $records[$index]['start'] !== null) {
                return $records[$index]['start'];
            }
        }

        return null;
    }
}
