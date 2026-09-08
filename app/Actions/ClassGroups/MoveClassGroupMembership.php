<?php

namespace App\Actions\ClassGroups;

use App\Models\ClassGroup;
use App\Models\ClassGroupMembership;
use App\Models\Enrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «O João passa de T1 para T2 a partir de 15 de novembro.»
 *
 * FECHA E ABRE, NUNCA REESCREVE. A pertença atual é fechada a 14 e nasce uma
 * nova a 15 — exatamente o que ReviseRecurringLessonSlot faz a um tempo do
 * horário, e pela mesma razão: a aula de 12 de novembro tem de continuar a
 * responder «o João estava em T1», porque estava.
 *
 * O DESTINO PODE SER «SEM GRUPO» (`$targetGroup === null`). Tirar um aluno de
 * um grupo é uma alteração como qualquer outra, com uma data e com história —
 * não é apagar a linha que dizia que ele lá esteve.
 *
 * LOCK, RE-CHECK, ACT, dentro de uma transação, como MaterializeLessonsForRange
 * e ReviseRecurringLessonSlot: duas mudanças simultâneas do mesmo aluno não
 * podem ambas fechar a mesma pertença e abrir duas.
 */
class MoveClassGroupMembership
{
    public function __construct(protected ClassGroupMembershipRules $rules) {}

    public function execute(Enrollment $enrollment, ?ClassGroup $targetGroup, string $effectiveFrom): void
    {
        DB::transaction(function () use ($enrollment, $targetGroup, $effectiveFrom): void {
            $class = $enrollment->schoolClass()->firstOrFail();
            $academicYear = $class->academicYear()->firstOrFail();

            $this->rules->assertWithinAcademicYear(
                $effectiveFrom,
                $academicYear->starts_on->toDateString(),
                $academicYear->ends_on->toDateString(),
            );

            if ($targetGroup !== null) {
                $this->rules->assertSameClass($targetGroup, $enrollment);
                $this->rules->assertAcceptsMembers($targetGroup);
            }

            $current = $this->rules->membershipInVigor($enrollment, $effectiveFrom);

            if ($current === null && $targetGroup === null) {
                throw ValidationException::withMessages([
                    'class_group_id' => __('Este aluno não pertence a nenhum grupo nessa data.'),
                ]);
            }

            if ($current !== null) {
                if ($targetGroup !== null && $current->class_group_id === $targetGroup->getKey()) {
                    throw ValidationException::withMessages([
                        'class_group_id' => __('Este aluno já pertence a :label nessa data.', [
                            'label' => $targetGroup->label,
                        ]),
                    ]);
                }

                $this->rules->assertClosesAfterItStarted($current, $effectiveFrom);
                $current->update(['effective_until' => $this->rules->dayBefore($effectiveFrom)]);
            }

            // Feita DEPOIS de fechar a atual — a atual passou a acabar em
            // `data - 1`, logo já não conta como «começa em ou depois de
            // `data`», e o que sobra a recusar são as janelas verdadeiramente
            // posteriores, aquelas que esta ação não sabe reescrever.
            $this->rules->assertNothingStartsOnOrAfter($enrollment, $effectiveFrom);

            if ($targetGroup === null) {
                return;
            }

            ClassGroupMembership::create([
                'class_group_id' => $targetGroup->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'effective_from' => $effectiveFrom,
                'effective_until' => null,
            ]);
        });
    }
}
