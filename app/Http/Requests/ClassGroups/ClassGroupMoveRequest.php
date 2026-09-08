<?php

namespace App\Http\Requests\ClassGroups;

use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Rules\BelongsToCurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Mover o João de T1 para T2 a partir de 15 de novembro.»
 *
 * `class_group_id` NULO É VÁLIDO e quer dizer «para fora de todos os grupos» —
 * uma alteração como qualquer outra, com data e com história, e não o
 * apagamento da linha que dizia que ele lá esteve.
 *
 * A data não é validada contra «hoje» aqui, ao contrário do `effective_from` de
 * um tempo do horário: pode legitimamente ser no passado. A razão por extenso
 * está em ClassGroupMembershipRules::assertWithinAcademicYear() — em resumo,
 * mover um aluno não toca em nenhuma linha de `lessons`, e um professor que só
 * em dezembro regista o que aconteceu em novembro está a corrigir o registo
 * para a realidade. Os limites do ano letivo são verificados na ação, onde a
 * turma já está resolvida.
 */
class ClassGroupMoveRequest extends FormRequest
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
            'enrollment_id' => ['required', 'integer', new BelongsToCurrentOrganization(Enrollment::class)],
            'class_group_id' => ['nullable', 'integer', new BelongsToCurrentOrganization(ClassGroup::class)],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'effective_from.required' => __('Indique a partir de quando a alteração passa a vigorar.'),
            'effective_from.date_format' => __('Indique uma data de entrada em vigor válida.'),
        ];
    }
}
