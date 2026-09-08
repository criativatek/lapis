<?php

namespace App\Actions\ClassGroups;

use App\Models\ClassGroupMembership;
use App\Models\Enrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A PERMUTA: o João passa para T2 e a Marta para T1, na mesma data, ou nenhum
 * dos dois se mexe.
 *
 * UMA AÇÃO, E NÃO DUAS CHAMADAS DE MoveClassGroupMembership. A diferença
 * importa: duas chamadas independentes são dois pedidos, com duas transações e
 * dois momentos, e entre eles existe um instante em que o João já está em T2 e
 * a Marta ainda está lá também. Se o segundo falhar — porque outro separador
 * mexeu na Marta, porque a rede caiu —, a turma fica com um desequilíbrio que
 * ninguém pediu e que nada assinala. Aqui, ou os quatro registos são escritos,
 * ou nenhum é (§5, §33 do briefing).
 *
 * UMA SÓ DATA, também de propósito: uma permuta é um acontecimento, não dois.
 *
 * LOCK, RE-CHECK, ACT. As duas pertenças são relidas com `lockForUpdate()`
 * dentro da transação (ClassGroupMembershipRules::membershipInVigor()) e só
 * depois disso é que se decide o que escrever.
 */
class SwapClassGroupMembership
{
    public function __construct(protected ClassGroupMembershipRules $rules) {}

    public function execute(Enrollment $first, Enrollment $second, string $effectiveFrom): void
    {
        if ($first->getKey() === $second->getKey()) {
            throw ValidationException::withMessages([
                'second_enrollment_id' => __('Escolha dois alunos diferentes.'),
            ]);
        }

        if ($first->class_id !== $second->class_id) {
            throw ValidationException::withMessages([
                'second_enrollment_id' => __('Os dois alunos têm de pertencer à mesma turma.'),
            ]);
        }

        DB::transaction(function () use ($first, $second, $effectiveFrom): void {
            $class = $first->schoolClass()->firstOrFail();
            $academicYear = $class->academicYear()->firstOrFail();

            $this->rules->assertWithinAcademicYear(
                $effectiveFrom,
                $academicYear->starts_on->toDateString(),
                $academicYear->ends_on->toDateString(),
            );

            // Sempre pela mesma ordem (o id mais baixo primeiro) para que duas
            // permutas simultâneas dos mesmos dois alunos, feitas em sentidos
            // opostos, não peguem os locks em ordens contrárias e fiquem uma à
            // espera da outra para sempre.
            [$lower, $higher] = $first->getKey() < $second->getKey()
                ? [$first, $second]
                : [$second, $first];

            $lowerMembership = $this->rules->membershipInVigor($lower, $effectiveFrom);
            $higherMembership = $this->rules->membershipInVigor($higher, $effectiveFrom);

            if ($lowerMembership === null || $higherMembership === null) {
                throw ValidationException::withMessages([
                    'second_enrollment_id' => __('Os dois alunos têm de pertencer a um grupo nessa data para poderem permutar.'),
                ]);
            }

            if ($lowerMembership->class_group_id === $higherMembership->class_group_id) {
                throw ValidationException::withMessages([
                    'second_enrollment_id' => __('Os dois alunos já estão no mesmo grupo.'),
                ]);
            }

            // Um grupo arquivado não recebe ninguém, e uma permuta é uma
            // entrada como qualquer outra. Verificado antes de escrever seja o
            // que for: recusar a meio deixaria o primeiro aluno fora do seu
            // grupo e o segundo no dele.
            foreach ([$lowerMembership, $higherMembership] as $membership) {
                $this->rules->assertAcceptsMembers($membership->classGroup()->firstOrFail());
            }

            foreach ([[$lower, $lowerMembership], [$higher, $higherMembership]] as [$enrollment, $membership]) {
                /** @var Enrollment $enrollment */
                /** @var ClassGroupMembership $membership */
                $this->rules->assertClosesAfterItStarted($membership, $effectiveFrom);
                $membership->update(['effective_until' => $this->rules->dayBefore($effectiveFrom)]);
                $this->rules->assertNothingStartsOnOrAfter($enrollment, $effectiveFrom);
            }

            // Trocados: cada um herda o grupo que o outro tinha.
            ClassGroupMembership::create([
                'class_group_id' => $higherMembership->class_group_id,
                'enrollment_id' => $lower->getKey(),
                'effective_from' => $effectiveFrom,
                'effective_until' => null,
            ]);

            ClassGroupMembership::create([
                'class_group_id' => $lowerMembership->class_group_id,
                'enrollment_id' => $higher->getKey(),
                'effective_from' => $effectiveFrom,
                'effective_until' => null,
            ]);
        });
    }
}
