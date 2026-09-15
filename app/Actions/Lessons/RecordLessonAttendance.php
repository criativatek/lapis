<?php

namespace App\Actions\Lessons;

use App\Models\AttendanceStatus;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Lessons\LessonAttendanceRoster;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A CONSOLIDAÇÃO — o rascunho de faltas vira um instantâneo fechado. Chamada
 * por dois caminhos: MarkLessonAsTaught (a aula acabou de passar a
 * lecionada) e o botão «Registar assiduidade» de uma aula já lecionada sem
 * registo (aulas antigas, ou aulas do lote §29 que ficaram por registar de
 * propósito). O MESMO SERVIÇO nos dois casos — não há uma segunda leitura do
 * roster escondida num controlador.
 */
class RecordLessonAttendance
{
    public function __construct(
        protected LessonAttendanceRoster $roster,
        protected SaveLessonAttendanceDraft $saveDraft,
        protected AuditLog $audit,
    ) {}

    /**
     * @param  list<string>|null  $absentStudentUlids  quando vem, é gravado como rascunho primeiro, no mesmo lock
     */
    public function execute(Lesson $lesson, User $actor, ?array $absentStudentUlids = null): Lesson
    {
        return DB::transaction(function () use ($absentStudentUlids, $actor, $lesson): Lesson {
            /** @var Lesson $lockedLesson */
            $lockedLesson = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

            return $this->consolidate($lockedLesson, $actor, $absentStudentUlids);
        });
    }

    /**
     * A mesma operação assumindo que o chamador já bloqueou a aula na sua
     * própria transação (o caso de MarkLessonAsTaught).
     *
     * @param  list<string>|null  $absentStudentUlids
     */
    public function consolidate(Lesson $lockedLesson, User $actor, ?array $absentStudentUlids = null): Lesson
    {
        if ($lockedLesson->attendanceNotApplicable()) {
            throw ValidationException::withMessages([
                'absent' => __('A assiduidade não se aplica a esta aula (:outcome).', ['outcome' => (string) $lockedLesson->outcome?->label()]),
            ]);
        }

        if ($lockedLesson->attendanceRecorded()) {
            return $lockedLesson;
        }

        if ($absentStudentUlids !== null) {
            $this->saveDraft->apply($lockedLesson, $absentStudentUlids, $actor);
        }

        $roster = $this->roster->for($lockedLesson);
        $eligible = $roster['students'];
        $eligibleEnrollmentIds = $eligible->pluck('id')->all();
        $draftRows = $lockedLesson->attendances()->with('student.identity')->get()->keyBy('enrollment_id');

        // Um rascunho de falta de um aluno que já não é elegível recusa a
        // consolidação inteira — não se apaga o rascunho em silêncio, e nada
        // muda (§ do briefing). VERIFICADO ANTES do atalho do roster vazio
        // logo a seguir: um roster vazio não é motivo para ignorar um
        // rascunho órfão, é só mais um caso em que nenhuma linha é elegível.
        foreach ($draftRows as $enrollmentId => $row) {
            if (! in_array($enrollmentId, $eligibleEnrollmentIds, true)) {
                $studentName = optional($row->student->identity)->display_name ?? __('Aluno desconhecido');

                throw ValidationException::withMessages([
                    'absent' => __(
                        'Não é possível registar a assiduidade: :name já não pertence a esta aula.',
                        ['name' => $studentName],
                    ),
                ]);
            }
        }

        if ($eligible->isEmpty()) {
            // Roster vazio: a assiduidade fica NÃO registada. Não se inventa
            // nada — nem uma consolidação vazia.
            return $lockedLesson;
        }

        $presentCount = 0;
        $absentCount = 0;

        foreach ($eligible as $enrollment) {
            $existing = $draftRows->get($enrollment->id);

            if ($existing !== null) {
                $existing->update(['status' => AttendanceStatus::Absent, 'updated_by' => $actor->getKey()]);
                $absentCount++;

                continue;
            }

            LessonAttendance::create([
                'lesson_id' => $lockedLesson->getKey(),
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'status' => AttendanceStatus::Present,
                'updated_by' => $actor->getKey(),
            ]);
            $presentCount++;
        }

        $lockedLesson->attendance_recorded_at = Carbon::now();
        $lockedLesson->attendance_recorded_by = $actor->getKey();
        $lockedLesson->save();

        $this->audit->record(
            'lesson.attendance_recorded',
            $lockedLesson,
            $actor,
            'Assiduidade registada.',
            ['present' => $presentCount, 'absent' => $absentCount],
        );

        return $lockedLesson;
    }
}
