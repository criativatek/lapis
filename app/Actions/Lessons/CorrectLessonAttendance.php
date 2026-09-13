<?php

namespace App\Actions\Lessons;

use App\Models\AttendanceStatus;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrigir uma linha JÁ CONSOLIDADA — presente vira falta, falta vira
 * presente. NUNCA cria uma linha nova: o instantâneo fechado por
 * RecordLessonAttendance é o universo desta correção, e um aluno fora dele
 * (fora do snapshot) não tem linha para corrigir — a resposta é «não
 * encontrado», nunca uma linha nova a meio da história.
 */
class CorrectLessonAttendance
{
    public function __construct(protected AuditLog $audit) {}

    public function execute(Lesson $lesson, Student $student, AttendanceStatus $status, User $actor): LessonAttendance
    {
        return DB::transaction(function () use ($actor, $lesson, $status, $student): LessonAttendance {
            /** @var Lesson $lockedLesson */
            $lockedLesson = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

            if (! $lockedLesson->attendanceRecorded()) {
                throw ValidationException::withMessages([
                    'status' => __('A assiduidade desta aula ainda não foi registada.'),
                ]);
            }

            /** @var LessonAttendance $row */
            $row = LessonAttendance::query()
                ->where('lesson_id', $lockedLesson->getKey())
                ->where('student_id', $student->getKey())
                ->firstOrFail();

            $from = $row->status;

            if ($from === $status) {
                return $row;
            }

            $row->status = $status;
            $row->updated_by = $actor->getKey();
            $row->save();

            $this->audit->record(
                'lesson.attendance_corrected',
                $lockedLesson,
                $actor,
                'Assiduidade corrigida.',
                [
                    'student_ulid' => $student->ulid,
                    'from' => $from->value,
                    'to' => $status->value,
                ],
            );

            return $row;
        });
    }
}
