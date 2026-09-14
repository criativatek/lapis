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
    public function __construct(
        protected AuditLog $audit,
        protected SaveLessonAttendanceDraft $saveAttendanceDraft,
    ) {}

    /**
     * `content` is optional here rather than required: on the create path
     * below (no existing summary) it must be present — `content` is NOT NULL
     * at the database level — but on the update path, fill() below leaves
     * any key the caller omits completely untouched, which is exactly what
     * ApplyLessonSequence relies on to leave an existing lesson's unselected
     * fields alone.
     *
     * @param  array{content?: string, private_notes?: string|null, resources?: string|null, homework?: string|null}  $details
     * @param  list<string>|null  $absentStudentUlids  o rascunho de faltas, gravado na MESMA transação do sumário quando vem — nunca quando a assiduidade já está consolidada, ver SaveLessonAttendanceDraft::apply()
     */
    public function execute(Lesson $lesson, array $details, User $actor, ?array $absentStudentUlids = null): LessonSummary
    {
        return DB::transaction(function () use ($absentStudentUlids, $actor, $details, $lesson): LessonSummary {
            /** @var Lesson $lockedLesson */
            $lockedLesson = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

            // Consolidada, a lista vem só como eco do ecrã: as correções têm rota
            // própria, e gravar notas ou recursos não pode falhar por causa dela.
            if ($absentStudentUlids !== null && ! $lockedLesson->attendanceRecorded()) {
                $this->saveAttendanceDraft->apply($lockedLesson, $absentStudentUlids, $actor);
            }

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
            } elseif ($lockedLesson->status === LessonStatus::Prepared) {
                // Já preparada, sem transição de estado — o que
                // `lesson.prepared` acima não cobre: um segundo (ou terceiro)
                // guardar do mesmo sumário, sem que nada mais mude.
                $this->audit->record(
                    'lesson.summary_saved',
                    $lockedLesson,
                    $actor,
                    'Sumário de aula guardado.',
                    ['summary_id' => $summary->getKey()],
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
