<?php

namespace App\Services\Lessons;

use App\Models\AcademicYear;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * As aulas da semana de um professor, como linhas de LessonRowBuilder — as
 * mesmas que a vista da turma mostra.
 */
final class WeeklyLessonsQuery
{
    private const TIMEZONE = 'Europe/Lisbon';

    public function __construct(private readonly LessonRowBuilder $rowBuilder) {}

    /** @return list<array<string, mixed>> */
    public function for(User $teacher, AcademicYear $academicYear, CarbonImmutable $weekStart): array
    {
        $from = $weekStart->setTimezone(self::TIMEZONE)->startOfWeek()->startOfDay();
        $to = $from->endOfWeek()->endOfDay();

        $lessons = $this->rowBuilder->query($teacher, $academicYear)
            ->whereBetween('starts_at', [$from, $to])
            ->orderBy('starts_at')
            ->get();

        return $this->rowBuilder->rows($lessons, $teacher);
    }
}
