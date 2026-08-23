<?php

namespace App\Actions\Lessons;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\CarbonImmutable;

final class MaterializeLessonsForWeek
{
    public function __construct(private readonly MaterializeLessonsForRange $materializeLessonsForRange) {}

    public function execute(User $teacher, AcademicYear $academicYear, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $academicYearStart = CarbonImmutable::parse($academicYear->starts_on, 'Europe/Lisbon')->startOfDay();
        $academicYearEnd = CarbonImmutable::parse($academicYear->ends_on, 'Europe/Lisbon')->startOfDay();
        $rangeStart = $from->greaterThan($academicYearStart) ? $from : $academicYearStart;
        $rangeEnd = $to->lessThan($academicYearEnd) ? $to : $academicYearEnd;

        if ($rangeStart->greaterThan($rangeEnd)) {
            return;
        }

        SchoolClass::query()
            ->where('academic_year_id', $academicYear->getKey())
            ->whereHas('teachers', fn ($query) => $query->whereKey($teacher->getKey()))
            ->whereHas('recurringLessonSlots')
            ->orderBy('id')
            ->each(fn (SchoolClass $class) => $this->materializeLessonsForRange->execute(
                $class,
                $rangeStart,
                $rangeEnd,
                $teacher,
            ));
    }
}
