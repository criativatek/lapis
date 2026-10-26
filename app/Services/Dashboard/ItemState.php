<?php

namespace App\Services\Dashboard;

use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\TimetableActivitySessionStatus;
use Carbon\CarbonImmutable;

/**
 * O estado de uma linha do dia, escrito num sítio só para a régua, a lista do
 * telemóvel e a agenda da semana dizerem o mesmo:
 *
 *   - `done`      — a ocorrência já tem o seu registo (sumário, ou um desfecho
 *                   que dispensa sumário; uma sessão realizada ou não realizada);
 *   - `pending`   — terminou e continua sem registo: é trabalho por fazer;
 *   - `current`   — está a decorrer agora;
 *   - `upcoming`  — ainda não começou;
 *   - `unopened`  — uma ocorrência do horário já terminada que ninguém abriu:
 *                   não há aula na base, logo não há sumário «em falta» no
 *                   sentido da fila; abrir a semana das aulas cria-a.
 */
final class ItemState
{
    public const DONE = 'done';

    public const PENDING = 'pending';

    public const CURRENT = 'current';

    public const UPCOMING = 'upcoming';

    public const UNOPENED = 'unopened';

    public static function forLesson(Lesson $lesson, bool $hasSummary, CarbonImmutable $now): string
    {
        // Um desfecho que não é «lecionada» (professor ausente, turma em
        // atividade externa) fecha a ocorrência sem sumário — a mesma regra
        // de PendingWork e de ClearLessonSummary; «lecionada» só está
        // arrumada com o sumário escrito.
        if ($lesson->outcome !== null && $lesson->outcome !== LessonOutcome::Taught) {
            return self::DONE;
        }

        if ($hasSummary) {
            return self::DONE;
        }

        return self::forWindow($lesson->starts_at->toImmutable(), $lesson->ends_at?->toImmutable(), $now);
    }

    public static function forSession(string $status, CarbonImmutable $startsAt, CarbonImmutable $endsAt, CarbonImmutable $now): string
    {
        if ($status === TimetableActivitySessionStatus::Done->value || $status === TimetableActivitySessionStatus::NotHeld->value) {
            return self::DONE;
        }

        return self::forWindow($startsAt, $endsAt, $now);
    }

    public static function forWindow(CarbonImmutable $startsAt, ?CarbonImmutable $endsAt, CarbonImmutable $now): string
    {
        if ($startsAt->greaterThan($now)) {
            return self::UPCOMING;
        }

        // Sem fim registado (coluna anulável, só em dados importados) a aula
        // conta como terminada mal comece: nunca fica «a decorrer» para sempre.
        if ($endsAt !== null && $endsAt->greaterThan($now)) {
            return self::CURRENT;
        }

        return self::PENDING;
    }
}
