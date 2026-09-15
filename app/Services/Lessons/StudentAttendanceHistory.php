<?php

namespace App\Services\Lessons;

use App\Models\ClassGroupMembership;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonOutcome;
use Illuminate\Support\Collection;

/**
 * A assiduidade de UM ALUNO, através de uma inscrição — o histórico que
 * alimenta o cartão «Assiduidade» de Evolução do Aluno e a secção homónima dos
 * relatórios (§ do briefing de assiduidade).
 *
 * DUAS FONTES, NUNCA CONFUNDIDAS. As linhas consolidadas (`lesson_attendances`)
 * são o que o professor registou; as aulas lecionadas da turma sem assiduidade
 * registada, no mesmo intervalo, são contadas à parte como «não registada» —
 * NUNCA como presença. Confundir as duas inventaria assiduidade que ninguém
 * observou.
 *
 * DUAS CONSULTAS, NÃO N+1: uma para as linhas consolidadas do aluno, outra
 * para as aulas lecionadas sem registo da turma, mais uma terceira (leve) para
 * as pertenças a grupo desta inscrição — nunca uma consulta por aula.
 */
class StudentAttendanceHistory
{
    private const TIMEZONE = 'Europe/Lisbon';

    /**
     * @return array{
     *     rows: list<array{date: string, lesson_ulid: string, lesson_number: int|null, subject: string, context_label: string, status: string}>,
     *     totals: array{recorded: int, present: int, absent: int, not_recorded: int},
     * }
     */
    public function for(Enrollment $enrollment, ?string $from = null, ?string $to = null): array
    {
        $consolidated = LessonAttendance::query()
            ->where('enrollment_id', $enrollment->id)
            ->whereHas('lesson', function ($query) use ($from, $to): void {
                // SÓ CONSOLIDADAS (0.146.0): um rascunho de falta numa aula
                // ainda não fechada não é falta nenhuma.
                $query->whereNotNull('attendance_recorded_at')
                    ->where(fn ($outcome) => $outcome->whereNull('outcome')->orWhere('outcome', LessonOutcome::Taught->value));

                if ($from !== null) {
                    $query->whereDate('starts_at', '>=', $from);
                }
                if ($to !== null) {
                    $query->whereDate('starts_at', '<=', $to);
                }
            })
            ->with(['lesson.schoolClass.subject', 'lesson.classGroup'])
            ->get();

        $rows = [];
        $consolidatedLessonIds = [];
        $present = 0;
        $absent = 0;

        foreach ($consolidated as $attendance) {
            $lesson = $attendance->lesson;
            $consolidatedLessonIds[] = $lesson->id;

            $rows[] = [
                'date' => $this->dateOf($lesson),
                'lesson_ulid' => $lesson->ulid,
                'lesson_number' => $lesson->lesson_number,
                'subject' => $lesson->schoolClass->subject->name,
                'context_label' => $lesson->contextLabel(),
                'status' => $attendance->status->value,
            ];

            if ($attendance->status->value === 'present') {
                $present++;
            } else {
                $absent++;
            }
        }

        // Aulas lecionadas da turma, no mesmo período, sem assiduidade
        // consolidada — «Assiduidade não registada» quando o aluno estava
        // elegível nessa data. `attendance_recorded_at` é a única fonte de
        // «não consolidada» (ver Lesson::attendanceRecorded()), nunca a
        // ausência de linhas.
        $notRecordedLessons = Lesson::query()
            ->where('class_id', $enrollment->class_id)
            ->where(fn ($query) => Lesson::whereCountsAsTaughtWithAttendance($query))
            ->whereNull('attendance_recorded_at')
            ->when($consolidatedLessonIds !== [], fn ($query) => $query->whereNotIn('id', $consolidatedLessonIds))
            ->when($from !== null, fn ($query) => $query->whereDate('starts_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('starts_at', '<=', $to))
            ->with(['schoolClass.subject', 'classGroup'])
            ->get();

        $memberships = ClassGroupMembership::query()
            ->where('enrollment_id', $enrollment->id)
            ->get();

        $notRecorded = 0;

        foreach ($notRecordedLessons as $lesson) {
            $date = $this->dateOf($lesson);

            if (! $this->eligibleOn($enrollment, $lesson, $date, $memberships)) {
                continue;
            }

            $rows[] = [
                'date' => $date,
                'lesson_ulid' => $lesson->ulid,
                'lesson_number' => $lesson->lesson_number,
                'subject' => $lesson->schoolClass->subject->name,
                'context_label' => $lesson->contextLabel(),
                'status' => 'not_recorded',
            ];

            $notRecorded++;
        }

        usort($rows, fn (array $a, array $b): int => $a['date'] === $b['date']
            ? ($a['lesson_number'] ?? 0) <=> ($b['lesson_number'] ?? 0)
            : $a['date'] <=> $b['date']);

        return [
            'rows' => $rows,
            'totals' => [
                'recorded' => $present + $absent,
                'present' => $present,
                'absent' => $absent,
                'not_recorded' => $notRecorded,
            ],
        ];
    }

    private function dateOf(Lesson $lesson): string
    {
        return $lesson->starts_at->copy()->setTimezone(self::TIMEZONE)->toDateString();
    }

    /**
     * @param  Collection<int, ClassGroupMembership>  $memberships
     */
    private function eligibleOn(Enrollment $enrollment, Lesson $lesson, string $date, Collection $memberships): bool
    {
        // A mesma regra de LessonAttendanceRoster: uma inscrição terminada sem
        // data de saída é ambígua e não conta — nunca «sem registo» inventado.
        if ($enrollment->status !== EnrollmentStatus::Active && $enrollment->left_on === null) {
            return false;
        }

        if ($enrollment->enrolled_on->toDateString() > $date) {
            return false;
        }

        if ($enrollment->left_on !== null && $enrollment->left_on->toDateString() < $date) {
            return false;
        }

        if ($lesson->class_group_id === null) {
            return true;
        }

        return $memberships->contains(function (ClassGroupMembership $membership) use ($lesson, $date): bool {
            if ($membership->class_group_id !== $lesson->class_group_id) {
                return false;
            }

            if ($membership->effective_from->toDateString() > $date) {
                return false;
            }

            return $membership->effective_until === null || $membership->effective_until->toDateString() >= $date;
        });
    }
}
