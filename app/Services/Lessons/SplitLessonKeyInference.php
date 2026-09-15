<?php

namespace App\Services\Lessons;

use App\Models\RecurringLessonSlot;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Para os dados que já existiam antes do vínculo explícito (0.145.2): dizer, por
 * turma, se os tempos de grupo podem ser ligados SEM ADIVINHAR.
 *
 * INEQUÍVOCO só quando cada grupo com tempos tem exatamente UMA linhagem de
 * tempo (um tempo, ou versões sucessivas que não se sobrepõem — a mesma aula
 * semanal a mudar de hora) e há pelo menos dois grupos. Aí só há uma leitura
 * possível: são todos a mesma lição.
 *
 * AMBÍGUO em tudo o resto — um grupo com dois tempos semanais, tempos já
 * parcialmente ligados à mão, um único grupo. Tempos expirados, sem aulas e
 * sem vínculo não entram na conta (0.145.3). Nesses casos não se escreve
 * vínculo nenhum; reporta-se, e o professor liga os tempos no horário.
 */
final class SplitLessonKeyInference
{
    public const string NoGroups = 'sem_grupo';

    public const string AlreadyLinked = 'ja_ligado';

    public const string Unambiguous = 'inequivoco';

    public const string Ambiguous = 'ambiguo';

    /**
     * @return array{status: string, reason: string|null, keys: array<int, string>}
     */
    public function infer(int $classId): array
    {
        $today = now('Europe/Lisbon')->toDateString();

        /** @var Collection<int, RecurringLessonSlot> $slots */
        $slots = RecurringLessonSlot::query()
            ->where('class_id', $classId)
            ->whereNotNull('class_group_id')
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get()
            ->reject(fn (RecurringLessonSlot $slot): bool => $this->isStaleAndEmpty($slot, $today))
            ->values();

        if ($slots->isEmpty()) {
            return ['status' => self::NoGroups, 'reason' => null, 'keys' => []];
        }

        $unlinked = $slots->whereNull('split_lesson_key');

        if ($unlinked->isEmpty()) {
            return ['status' => self::AlreadyLinked, 'reason' => null, 'keys' => []];
        }

        if ($unlinked->count() !== $slots->count()) {
            return ['status' => self::Ambiguous, 'reason' => 'tempos parcialmente ligados', 'keys' => []];
        }

        $byGroup = $slots->groupBy('class_group_id');

        if ($byGroup->count() < 2) {
            return ['status' => self::Ambiguous, 'reason' => 'só um grupo tem tempos', 'keys' => []];
        }

        foreach ($byGroup as $groupSlots) {
            if (! $this->isSingleLineage($groupSlots->values())) {
                return ['status' => self::Ambiguous, 'reason' => 'um grupo tem mais de um tempo semanal', 'keys' => []];
            }
        }

        $key = (string) Str::ulid();

        return [
            'status' => self::Unambiguous,
            'reason' => null,
            'keys' => $slots->mapWithKeys(fn (RecurringLessonSlot $slot): array => [(int) $slot->getKey() => $key])->all(),
        ];
    }

    /**
     * 0.145.3 — um tempo que já acabou, nunca teve aulas e não tem vínculo é
     * só rasto de um horário substituído: não há nada para ligar nele, e
     * contá-lo tornava ambígua a turma (8.º F: o tempo de sexta de T1 terminou
     * a 08/09 e o de quarta que o substituiu foi criado sem `starts_on`).
     *
     * Um tempo expirado COM aulas continua a contar — essas aulas precisam da
     * linhagem — e um tempo com vínculo explícito também, para o preservar.
     */
    private function isStaleAndEmpty(RecurringLessonSlot $slot, string $today): bool
    {
        return $slot->split_lesson_key === null
            && $slot->ends_on !== null
            && $slot->ends_on->toDateString() < $today
            && ! $slot->lessons()->exists();
    }

    /**
     * Versões sucessivas: cada uma acaba antes de a seguinte começar.
     *
     * @param  Collection<int, RecurringLessonSlot>  $slots  ordenadas por starts_on
     */
    private function isSingleLineage(Collection $slots): bool
    {
        for ($index = 1; $index < $slots->count(); $index++) {
            $previous = $slots[$index - 1];
            $current = $slots[$index];

            if ($previous->ends_on === null || $current->starts_on === null
                || $previous->ends_on->toDateString() >= $current->starts_on->toDateString()) {
                return false;
            }
        }

        return true;
    }
}
