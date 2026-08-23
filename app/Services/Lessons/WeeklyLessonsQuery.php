<?php

namespace App\Services\Lessons;

use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

final class WeeklyLessonsQuery
{
    private const TIMEZONE = 'Europe/Lisbon';

    /** @return list<array<string, mixed>> */
    public function for(User $teacher, AcademicYear $academicYear, CarbonImmutable $weekStart): array
    {
        $from = $weekStart->setTimezone(self::TIMEZONE)->startOfWeek()->startOfDay();
        $to = $from->endOfWeek()->endOfDay();

        return array_values(Lesson::query()
            ->whereBetween('starts_at', [$from, $to])
            ->whereHas('schoolClass', fn ($query) => $query
                ->where('academic_year_id', $academicYear->getKey())
                ->whereHas('teachers', fn ($teachers) => $teachers->whereKey($teacher->getKey())))
            ->with('schoolClass.subject')
            ->with([
                'summary' => fn (Relation $query) => $query->select([
                    'id', 'lesson_id', DB::raw('SUBSTR(content, 1, 180) as content_excerpt'),
                ]),
            ])
            ->orderBy('starts_at')
            ->get()
            ->map(function (Lesson $lesson): array {
                $excerpt = $lesson->summary?->getAttribute('content_excerpt');

                return [
                    'ulid' => $lesson->ulid,
                    'starts_at' => $lesson->starts_at->toIso8601String(),
                    'ends_at' => $lesson->ends_at?->toIso8601String(),
                    'school_class' => ['ulid' => $lesson->schoolClass->ulid, 'label' => $lesson->schoolClass->label],
                    'subject' => $lesson->schoolClass->subject->name,
                    'status' => $lesson->status->value,
                    'status_label' => $this->statusLabel($lesson->status),
                    'has_summary' => $lesson->summary !== null && trim((string) $excerpt) !== '',
                    'summary_excerpt' => $excerpt === null ? null : trim((string) $excerpt),
                ];
            })->all());
    }

    private function statusLabel(LessonStatus $status): string
    {
        return match ($status) {
            LessonStatus::Preparation => __('Por preparar'),
            LessonStatus::Prepared => __('Preparado'),
            LessonStatus::Taught => __('Lecionado'),
        };
    }
}
