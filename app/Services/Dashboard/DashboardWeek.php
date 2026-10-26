<?php

namespace App\Services\Dashboard;

use App\Models\Lesson;
use App\Services\Lessons\LessonRowBuilder;
use App\Services\Timetable\ActivitySessionWeek;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * As aulas já materializadas e as sessões das «outras atividades» da semana de
 * hoje, lidas UMA vez por contexto e partilhadas pela régua do dia, pela agenda
 * da semana e pelos cartões das turmas.
 *
 * Memoriza NO CONTEXTO (DashboardContext::remember), e não neste objeto: o
 * contentor entrega uma instância nova deste serviço a cada bloco que o pede,
 * e só o contexto é um por pedido.
 */
final class DashboardWeek
{
    public function __construct(
        private readonly LessonRowBuilder $rowBuilder,
        private readonly ActivitySessionWeek $activitySessions,
    ) {}

    /**
     * As aulas da semana das turmas do contexto, com o que LessonRowBuilder
     * já carrega (turma, grupo, sumário, contagens de faltas e planos).
     *
     * @return EloquentCollection<int, Lesson>
     */
    public function lessons(DashboardContext $context): EloquentCollection
    {
        /** @var EloquentCollection<int, Lesson> $lessons */
        $lessons = $context->remember('week.lessons', function () use ($context): EloquentCollection {
            if ($context->academicYear === null || ! $context->hasLessons || ! $context->hasClasses()) {
                return new EloquentCollection;
            }

            return $this->rowBuilder->query($context->teacher, $context->academicYear)
                ->whereIn('class_id', $context->classIds())
                ->whereBetween('starts_at', [$context->weekStart(), $context->weekEnd()])
                ->orderBy('starts_at')
                ->get();
        });

        return $lessons;
    }

    /**
     * As sessões (reais e virtuais) das outras atividades na semana, como
     * ActivitySessionWeek as apresenta ao Horário e às Aulas.
     *
     * @return list<array<string, mixed>>
     */
    public function sessions(DashboardContext $context): array
    {
        /** @var list<array<string, mixed>> $sessions */
        $sessions = $context->remember('week.sessions', fn (): array => $context->hasLessons
            ? $this->activitySessions->between($context->teacher, $context->weekStart(), $context->weekEnd())
            : []);

        return $sessions;
    }

    /**
     * A aula materializada DESTA ocorrência, escrita num sítio só para o dia e
     * a semana nunca divergirem. A identidade de uma ocorrência é (turma,
     * tempo do horário, início) — MaterializeLessonsForRange —, por isso o
     * tempo manda: dois tempos da mesma turma à mesma hora (T1 e T2) têm
     * cada um a sua aula. Sem tempo (aula órfã de um horário já alterado)
     * vale a turma, o início e o grupo. Cada aula só é entregue uma vez.
     *
     * @param  EloquentCollection<int, Lesson>  $lessons
     * @param  array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, slot_id: int, class_group_id: int|null}  $occurrence
     * @param  array<int, bool>  $matched  ids das aulas já entregues; é atualizado
     */
    public static function lessonForOccurrence(EloquentCollection $lessons, int $classId, array $occurrence, array &$matched): ?Lesson
    {
        $candidates = $lessons->filter(
            fn (Lesson $lesson): bool => ! isset($matched[(int) $lesson->getKey()])
                && (int) $lesson->class_id === $classId
                && $lesson->starts_at->getTimestamp() === $occurrence['starts_at']->getTimestamp(),
        );

        /** @var Lesson|null $lesson */
        $lesson = $candidates->first(fn (Lesson $lesson): bool => (int) $lesson->recurring_lesson_slot_id === $occurrence['slot_id'])
            ?? $candidates->first(fn (Lesson $lesson): bool => $lesson->recurring_lesson_slot_id === null
                && ($lesson->class_group_id === null ? null : (int) $lesson->class_group_id) === $occurrence['class_group_id']);

        if ($lesson !== null) {
            $matched[(int) $lesson->getKey()] = true;
        }

        return $lesson;
    }
}
