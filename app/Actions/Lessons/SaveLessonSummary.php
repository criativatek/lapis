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

    /**
     * @param  array{content: string, private_notes?: string|null, resources?: string|null, homework?: string|null}  $details
     */
    public function execute(Lesson $lesson, array $details, User $actor): LessonSummary
    {
        return DB::transaction(function () use ($actor, $details, $lesson): LessonSummary {
            /** @var Lesson $lockedLesson */
            $lockedLesson = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());
            $summary = $lockedLesson->summary()->first();
            $isPostTaughtEdit = $lockedLesson->status === LessonStatus::Taught;

            if ($summary === null) {
                if ($isPostTaughtEdit) {
                    $details['reviewed_at'] = now();
                    $details['reviewed_by'] = $actor->getKey();
                }

                $summary = $lockedLesson->summary()->create($details);
            } else {
                $summary->fill($details);

                if ($isPostTaughtEdit) {
                    $summary->reviewed_at = now();
                    $summary->reviewed_by = $actor->getKey();
                }

                $summary->save();
            }

            if ($lockedLesson->status === LessonStatus::Preparation) {
                $lockedLesson->status = LessonStatus::Prepared;
                $lockedLesson->save();

                $this->audit->record(
                    'lesson.prepared',
                    $lockedLesson,
                    $actor,
                    'Estado da aula alterado.',
                    [
                        'from_status' => LessonStatus::Preparation->value,
                        'to_status' => LessonStatus::Prepared->value,
                    ],
                );
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
