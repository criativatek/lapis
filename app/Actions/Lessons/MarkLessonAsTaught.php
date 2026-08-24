<?php

namespace App\Actions\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

class MarkLessonAsTaught
{
    public function __construct(protected AuditLog $audit) {}

    public function execute(Lesson $lesson, User $actor): Lesson
    {
        return DB::transaction(function () use ($actor, $lesson): Lesson {
            /** @var Lesson $lockedLesson */
            $lockedLesson = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

            if ($lockedLesson->status === LessonStatus::Taught) {
                return $lockedLesson;
            }

            $fromStatus = $lockedLesson->status;
            $lockedLesson->status = LessonStatus::Taught;
            $lockedLesson->save();

            $this->audit->record(
                'lesson.taught',
                $lockedLesson,
                $actor,
                'Estado da aula alterado.',
                [
                    'from_status' => $fromStatus->value,
                    'to_status' => LessonStatus::Taught->value,
                ],
            );

            return $lockedLesson;
        });
    }
}
