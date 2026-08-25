<?php

namespace App\Services\Calendar;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Instrument;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * «Calendário do Ano Letivo» — what is relevant in the teacher's year, over an
 * arbitrary range of dates.
 *
 * A PURE READ MODEL OVER DATA THAT ALREADY EXISTS. It owns no table and adds
 * no persistence: the year's structure is read from AcademicPeriod, and the
 * avaliações from Instrument.applied_on, both exactly where they already live.
 * Reading the calendar therefore cannot create, alter or delete anything.
 *
 * AULAS ARE DELIBERATELY ABSENT, and neither Lesson nor RecurringLessonSlot is
 * reachable from here. «Que aulas tenho, quando e onde» is «Horário do
 * Professor»'s question and it already answers it; this calendar answers a
 * different one — «o que é relevante no meu ano» — and repeating the horário
 * inside it would make the two the same page. This is a product decision, not
 * an omission, which is why WeeklyLessonsQuery is not reused, adapted or
 * imported: it reads Lesson rows, and no Lesson row belongs on this page.
 *
 * The range is a plain [from, to] pair rather than an ISO week, because the two
 * views need very different spans of it: a month grid (which reaches into the
 * neighbouring months to fill its first and last row) and the whole year at
 * once. Both call this one method; neither has a query of its own.
 */
final class AcademicYearCalendarQuery
{
    private const TIMEZONE = 'Europe/Lisbon';

    /**
     * @return array{
     *     periods: list<array{ulid: string, label: string, kind: string, kind_label: string, sequence: int, starts_on: string, ends_on: string}>,
     *     assessments: list<array{ulid: string, title: string, applied_on: string, class_ulid: string, class_label: string, subject: string, type: string, status: string, status_label: string, href: string}>
     * }
     */
    public function for(User $teacher, AcademicYear $academicYear, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $fromDate = $from->setTimezone(self::TIMEZONE)->toDateString();
        $toDate = $to->setTimezone(self::TIMEZONE)->toDateString();

        return [
            'periods' => $this->periods($academicYear, $fromDate, $toDate),
            'assessments' => $this->assessments($teacher, $academicYear, $fromDate, $toDate),
        ];
    }

    /**
     * The year's own períodos that OVERLAP the range — not only those wholly
     * inside it. A semester running from September to January is the structure
     * of every month it crosses, so October must be told about it.
     *
     * A year is not required to be covered end to end: nothing forbids a gap
     * between two períodos, and a date in one is honestly left without any
     * period rather than attached to the nearest.
     *
     * @return list<array{ulid: string, label: string, kind: string, kind_label: string, sequence: int, starts_on: string, ends_on: string}>
     */
    private function periods(AcademicYear $academicYear, string $from, string $to): array
    {
        // whereDate on both bounds, and not a plain where: these are date
        // columns, but Eloquent stores them as «Y-m-d 00:00:00», so comparing
        // the raw column against a «Y-m-d» string is a string comparison in
        // which «2026-10-31 00:00:00» is NOT <= «2026-10-31» — a período
        // beginning on the last visible day would silently vanish. The same
        // trap EnrollmentController already documents for enrolled_on.
        return array_values($academicYear->periods()
            ->whereDate('starts_on', '<=', $to)
            ->whereDate('ends_on', '>=', $from)
            ->get()
            ->map(fn (AcademicPeriod $period): array => [
                'ulid' => $period->ulid,
                'label' => $period->label,
                'kind' => $period->kind->value,
                'kind_label' => $period->kind->label(),
                'sequence' => $period->sequence,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
            ])
            ->all());
    }

    /**
     * Every avaliação applied in the range, of the turmas this teacher teaches
     * IN THIS YEAR, and of no others.
     *
     * «Whose turma» is SchoolClass::scopeTaughtBy — the same source of truth
     * ClassController::teacherClasses() and «Horário do Professor» already use,
     * called here rather than reproduced — and the narrowing is the whereIn over
     * the keys it returns, the idiom TeacherTimetableController established for
     * exactly this problem. That scoping is AUTHORIZATION and stays explicit;
     * the organization boundary is not, because each model's own global scope
     * already draws it and a second, redundant filter would only invite the two
     * to disagree.
     *
     * @return list<array{ulid: string, title: string, applied_on: string, class_ulid: string, class_label: string, subject: string, type: string, status: string, status_label: string, href: string}>
     */
    private function assessments(User $teacher, AcademicYear $academicYear, string $from, string $to): array
    {
        $classIds = SchoolClass::query()
            ->taughtBy($teacher)
            ->where('academic_year_id', $academicYear->getKey())
            ->pluck('id')
            ->all();

        if ($classIds === []) {
            return [];
        }

        return array_values(Instrument::query()
            ->whereIn('class_id', $classIds)
            // Inclusive on both ends, and whereDate for the same reason the
            // períodos above use it: applied_on is a date column stored as
            // «Y-m-d 00:00:00», so a plain whereBetween against «Y-m-d» bounds
            // drops precisely the avaliações on the LAST visible day.
            ->whereDate('applied_on', '>=', $from)
            ->whereDate('applied_on', '<=', $to)
            ->with(['schoolClass.subject', 'type'])
            ->orderBy('applied_on')
            ->orderBy('title')
            ->get()
            ->map(fn (Instrument $instrument): array => [
                'ulid' => $instrument->ulid,
                'title' => $instrument->title,
                'applied_on' => $instrument->applied_on->toDateString(),
                'class_ulid' => $instrument->schoolClass->ulid,
                'class_label' => $instrument->schoolClass->label,
                'subject' => $instrument->schoolClass->subject->name,
                'type' => $instrument->type->name,
                'status' => $instrument->status->value,
                'status_label' => $instrument->status->label(),
                // The real page of the real element, by its real route: an
                // entry in the calendar is a way in, never a dead end.
                'href' => route('instruments.show', $instrument, false),
            ])
            ->all());
    }
}
