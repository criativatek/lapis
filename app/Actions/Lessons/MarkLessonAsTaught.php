<?php

namespace App\Actions\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

class MarkLessonAsTaught
{
    public function __construct(
        protected AuditLog $audit,
        protected RecordLessonAttendance $recordAttendance,
    ) {}

    /**
     * @param  list<string>|null  $absentStudentUlids  vindo do botão individual (nunca do lote — o lote não tem UI de faltas)
     * @param  bool  $consolidateAttendance  false no lote quando a aula nunca teve um rascunho — ver MarkLessonsAsTaughtInBatch
     */
    public function execute(
        Lesson $lesson,
        User $actor,
        ?array $absentStudentUlids = null,
        bool $consolidateAttendance = true,
    ): Lesson {
        return DB::transaction(function () use ($absentStudentUlids, $consolidateAttendance, $actor, $lesson): Lesson {
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

            // A CONSOLIDAÇÃO VIVE NA MESMA TRANSAÇÃO: se o rascunho referir um
            // aluno que já não é elegível, RecordLessonAttendance lança e a
            // transação inteira reverte — a aula NÃO fica lecionada, porque a
            // assiduidade que a acompanharia falhou (§ do briefing).
            if ($consolidateAttendance) {
                $this->recordAttendance->consolidate($lockedLesson, $actor, $absentStudentUlids);
            }

            return $lockedLesson;
        });
    }
}
