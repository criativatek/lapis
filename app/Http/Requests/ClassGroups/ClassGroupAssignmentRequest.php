<?php

namespace App\Http\Requests\ClassGroups;

use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Rules\BelongsToCurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A distribuição inicial: pares (aluno, grupo), sem datas.
 *
 * `new BelongsToCurrentOrganization(...)` E NUNCA `exists:`. Um `exists:` corre
 * no query builder e não vê o global scope da organização — aceitaria o id de
 * um grupo de outra escola sem pestanejar. Que o grupo e o aluno sejam da MESMA
 * TURMA é verificado a seguir, dentro da ação e da transação
 * (ClassGroupMembershipRules::assertSameClass()), porque é lá que a resposta
 * ainda é verdadeira quando se escreve.
 */
class ClassGroupAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $schoolClass = $this->route('class');

        return $user !== null
            && $schoolClass instanceof SchoolClass
            && $user->can('create', [ClassGroup::class, $schoolClass]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assignments' => ['present', 'array', 'max:500'],
            'assignments.*.enrollment_id' => [
                'required', 'integer', new BelongsToCurrentOrganization(Enrollment::class),
            ],
            'assignments.*.class_group_id' => [
                'required', 'integer', new BelongsToCurrentOrganization(ClassGroup::class),
            ],
        ];
    }

    /**
     * Inscrição => grupo, na forma que a ação espera.
     *
     * Se o mesmo aluno aparecer duas vezes no formulário — o que só um cliente
     * estragado faria —, a última linha ganha, e é a ação que depois recusa
     * qualquer coisa que não bata certo. Aqui não se adivinha nada.
     *
     * @return array<int, int>
     */
    public function assignmentMap(): array
    {
        $map = [];

        /** @var array<int, array{enrollment_id: int, class_group_id: int}> $assignments */
        $assignments = $this->validated('assignments') ?? [];

        foreach ($assignments as $assignment) {
            $map[(int) $assignment['enrollment_id']] = (int) $assignment['class_group_id'];
        }

        return $map;
    }
}
