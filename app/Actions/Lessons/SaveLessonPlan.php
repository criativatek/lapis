<?php

namespace App\Actions\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveLessonPlan
{
    public function __construct(protected AuditLog $audit) {}

    public function execute(
        Lesson $lesson,
        string $plannedSummary,
        LessonStatus $targetStatus,
        User $actor,
    ): Lesson {
        return DB::transaction(function () use ($actor, $lesson, $plannedSummary, $targetStatus): Lesson {
            /** @var Lesson $lockedLesson */
            $lockedLesson = Lesson::query()
                ->with('summary')
                ->lockForUpdate()
                ->findOrFail($lesson->getKey());
            $currentStatus = $lockedLesson->status;

            $this->validateTransition($lockedLesson, $plannedSummary, $targetStatus);

            $plan = $lockedLesson->plan()->first();
            $isPostTaughtEdit = $currentStatus === LessonStatus::Taught
                && ($plan === null
                    ? $plannedSummary !== ''
                    : $plan->planned_summary !== $plannedSummary);

            if ($plan === null) {
                $plan = $lockedLesson->plan()->create([
                    'planned_summary' => $plannedSummary,
                    'created_by' => $actor->getKey(),
                ]);
            } else {
                $plan->planned_summary = $plannedSummary;
                $plan->save();
            }

            if ($currentStatus !== $targetStatus) {
                $lockedLesson->status = $targetStatus;
                $lockedLesson->save();

                $this->auditStatusTransition($lockedLesson, $actor, $currentStatus, $targetStatus);
            }

            if ($isPostTaughtEdit) {
                $this->audit->record(
                    'lesson.plan_revised',
                    $lockedLesson,
                    $actor,
                    'Planeamento de aula lecionada revisto.',
                    [
                        'lesson_status' => $currentStatus->value,
                        'plan_id' => $plan->getKey(),
                    ],
                );
            }

            return $lockedLesson->refresh()->load(['plan', 'summary']);
        });
    }

    protected function validateTransition(
        Lesson $lesson,
        string $plannedSummary,
        LessonStatus $targetStatus,
    ): void {
        if ($lesson->status === LessonStatus::Taught && $targetStatus !== LessonStatus::Taught) {
            throw ValidationException::withMessages([
                'target_status' => 'Uma aula lecionada não pode voltar a um estado anterior.',
            ]);
        }

        $hasOfficialSummary = $lesson->summary !== null
            && trim($lesson->summary->content) !== '';

        if ($targetStatus === LessonStatus::Prepared
            && trim($plannedSummary) === ''
            && ! $hasOfficialSummary) {
            throw ValidationException::withMessages([
                'planned_summary' => 'Para marcar a aula como preparada, preenche o planeamento ou o sumário.',
            ]);
        }
    }

    protected function auditStatusTransition(
        Lesson $lesson,
        User $actor,
        LessonStatus $from,
        LessonStatus $to,
    ): void {
        $event = match ($to) {
            LessonStatus::Preparation => 'lesson.preparation_reopened',
            LessonStatus::Prepared => 'lesson.prepared',
            LessonStatus::Taught => 'lesson.taught',
        };

        $this->audit->record(
            $event,
            $lesson,
            $actor,
            'Estado da aula alterado.',
            [
                'from_status' => $from->value,
                'to_status' => $to->value,
            ],
        );
    }
}
