<?php

namespace App\Actions\Lessons;

use App\Models\AttendanceStatus;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\User;
use App\Services\Lessons\LessonAttendanceRoster;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * O RASCUNHO de faltas de uma aula ainda não lecionada — nunca uma linha
 * `present`, porque antes da consolidação a única coisa que existe para
 * marcar é uma falta (ver a migration). Chamado tanto pelo formulário
 * dedicado como, de passagem, por SaveLessonSummary quando o sumário chega
 * com uma lista de faltas.
 */
class SaveLessonAttendanceDraft
{
    public function __construct(protected LessonAttendanceRoster $roster) {}

    /**
     * @param  list<string>  $absentStudentUlids
     */
    public function execute(Lesson $lesson, array $absentStudentUlids, User $actor): void
    {
        DB::transaction(function () use ($absentStudentUlids, $actor, $lesson): void {
            /** @var Lesson $lockedLesson */
            $lockedLesson = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

            $this->apply($lockedLesson, $absentStudentUlids, $actor);
        });
    }

    /**
     * A mesma operação, mas assumindo que o chamador já tem a aula
     * bloqueada dentro da SUA PRÓPRIA transação — o caso de
     * MarkLessonAsTaught e de SaveLessonSummary, que não podem gravar o
     * rascunho numa transação à parte sem perder a atomicidade que pedem.
     *
     * @param  list<string>  $absentStudentUlids
     */
    public function apply(Lesson $lockedLesson, array $absentStudentUlids, User $actor): void
    {
        if ($lockedLesson->attendanceNotApplicable()) {
            throw ValidationException::withMessages([
                'absent' => __('A assiduidade não se aplica a esta aula (:outcome).', ['outcome' => (string) $lockedLesson->outcome?->label()]),
            ]);
        }

        if ($lockedLesson->attendanceRecorded()) {
            throw ValidationException::withMessages([
                'absent' => __('A assiduidade desta aula já foi registada — usa a correção em vez do rascunho.'),
            ]);
        }

        $roster = $this->roster->for($lockedLesson)['students'];
        $rosterByUlid = $roster->keyBy(fn ($enrollment) => $enrollment->student->ulid);

        foreach ($absentStudentUlids as $ulid) {
            if (! $rosterByUlid->has($ulid)) {
                throw ValidationException::withMessages([
                    'absent' => __('Um dos alunos indicados não pertence a esta aula.'),
                ]);
            }
        }

        $existing = $lockedLesson->attendances()->get()->keyBy('enrollment_id');
        $wanted = $rosterByUlid->only($absentStudentUlids)->keyBy(fn ($enrollment) => $enrollment->id);

        // Apaga o que foi desmarcado.
        foreach ($existing as $enrollmentId => $row) {
            if (! $wanted->has($enrollmentId)) {
                $row->delete();
            }
        }

        // Cria o que passou a ser falta.
        foreach ($wanted as $enrollmentId => $enrollment) {
            if ($existing->has($enrollmentId)) {
                continue;
            }

            LessonAttendance::create([
                'lesson_id' => $lockedLesson->getKey(),
                'enrollment_id' => $enrollmentId,
                'student_id' => $enrollment->student_id,
                'status' => AttendanceStatus::Absent,
                'updated_by' => $actor->getKey(),
            ]);
        }
    }
}
