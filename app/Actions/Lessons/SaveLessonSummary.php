<?php

namespace App\Actions\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

class SaveLessonSummary
{
    public function __construct(protected AuditLog $audit) {}

    public function execute(Lesson $lesson, string $content, User $actor): LessonSummary
    {
        return DB::transaction(function () use ($actor, $content, $lesson): LessonSummary {
            /** @var Lesson $lockedLesson */
            $lockedLesson = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());
            $summary = $lockedLesson->summary()->first();
            $isPostTaughtEdit = $summary !== null && $lockedLesson->status === LessonStatus::Taught;

            if ($summary === null) {
                $summary = $lockedLesson->summary()->create(['content' => $content]);
            } else {
                $summary->content = $content;

                if ($isPostTaughtEdit) {
                    $summary->reviewed_at = now();
                    $summary->reviewed_by = $actor->getKey();
                }

                $summary->save();
            }

            if ($isPostTaughtEdit) {
                $this->audit->record(
                    'lesson.summary_reviewed',
                    $lockedLesson,
                    $actor,
                    'Sumário de aula revisto.',
                    [
                        'lesson_status' => $lockedLesson->status->value,
                        'summary_id' => $summary->getKey(),
                    ],
                );
            }

            return $summary;
        });
    }
}
