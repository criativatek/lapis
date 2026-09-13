<?php

namespace App\Services\Lessons;

use App\Models\ClassGroupMembership;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Lesson;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Quem estava PRESENTE NA TURMA no dia de uma aula concreta — a pergunta que
 * `ClassRoster::on()` não responde, porque esse filtra `status = active`
 * HOJE e não na data da aula (§ do briefing de assiduidade).
 *
 * A DATA É A DE LISBOA, sempre. `starts_at` viaja em UTC como qualquer
 * `datetime` desta aplicação; o dia que interessa para «quem estava
 * inscrito» é o dia local da aula, não o dia UTC — as aulas das 23h de
 * outubro em diante já mudariam de dia se comparadas em UTC.
 *
 * TURMAS DE APOIO SEGUEM A MESMA REGRA: usam `enrollments` como qualquer
 * outra turma, e nada aqui distingue uma turma de apoio de uma turma normal.
 */
class LessonAttendanceRoster
{
    private const TIMEZONE = 'Europe/Lisbon';

    /**
     * @return array{
     *     students: Collection<int, Enrollment>,
     *     excluded_without_left_on: int,
     * }
     */
    public function for(Lesson $lesson): array
    {
        $date = $lesson->starts_at->copy()->setTimezone(self::TIMEZONE)->toDateString();

        $candidates = Enrollment::query()
            ->where('class_id', $lesson->class_id)
            ->enrolledOn($date)
            ->with(['student.identity'])
            ->get();

        $excludedWithoutLeftOn = 0;
        $eligible = new Collection;

        foreach ($candidates as $enrollment) {
            if ($enrollment->status === EnrollmentStatus::Active) {
                $eligible->push($enrollment);

                continue;
            }

            if ($enrollment->left_on !== null) {
                // Não ativa mas com data de saída registada: nesse dia ainda
                // fazia parte da turma — é a distinção entre «estado hoje» e
                // «estava lá naquele dia» que enrolledOn() já traz, e o
                // status não-ativo por si só não a desfaz.
                $eligible->push($enrollment);

                continue;
            }

            // Não ativa E sem data de saída: uma inscrição ambígua — não se
            // adivinha se ela estava ou não na turma nesse dia. Fica de fora
            // e contada, para o ecrã poder dizer que ficou de fora.
            $excludedWithoutLeftOn++;
        }

        if ($lesson->class_group_id !== null) {
            $eligible = $this->restrictToGroup($lesson, $eligible, $date);
        }

        $sorted = $eligible->values()->all();
        usort($sorted, function (Enrollment $a, Enrollment $b): int {
            $byClassNumber = ($a->class_number ?? PHP_INT_MAX) <=> ($b->class_number ?? PHP_INT_MAX);

            return $byClassNumber !== 0 ? $byClassNumber : $this->nameOf($a) <=> $this->nameOf($b);
        });
        $eligible = new Collection($sorted);

        return [
            'students' => $eligible,
            'excluded_without_left_on' => $excludedWithoutLeftOn,
        ];
    }

    /**
     * @return list<string>
     */
    public function eligibleStudentUlids(Lesson $lesson): array
    {
        return array_values($this->for($lesson)['students']
            ->map(fn (Enrollment $enrollment): string => $enrollment->student->ulid)
            ->all());
    }

    /**
     * @param  Collection<int, Enrollment>  $eligible
     * @return Collection<int, Enrollment>
     */
    private function restrictToGroup(Lesson $lesson, Collection $eligible, string $date): Collection
    {
        $groupBelongsToClass = $lesson->classGroup !== null
            && $lesson->classGroup->class_id === $lesson->class_id;

        if (! $groupBelongsToClass) {
            throw ValidationException::withMessages([
                'roster' => __('Não foi possível determinar os alunos desta aula — o grupo do horário já não pertence a esta turma.'),
            ]);
        }

        $memberEnrollmentIds = ClassGroupMembership::query()
            ->where('class_group_id', $lesson->class_group_id)
            ->inVigorOn($date)
            ->pluck('enrollment_id')
            ->all();

        return $eligible->filter(
            fn (Enrollment $enrollment): bool => in_array($enrollment->id, $memberEnrollmentIds, true),
        )->values();
    }

    private function nameOf(Enrollment $enrollment): string
    {
        return optional($enrollment->student->identity)->display_name ?? '';
    }
}
