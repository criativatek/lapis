<?php

namespace App\Http\Requests\ClassGroups;

use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Rules\BelongsToCurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A permuta: dois alunos, UMA data.
 *
 * Uma só data porque uma permuta é um acontecimento e não dois — e um só
 * pedido porque, partido em dois, existiria um instante em que os dois alunos
 * estariam no mesmo grupo (§ SwapClassGroupMembership).
 */
class ClassGroupSwapRequest extends FormRequest
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
            'first_enrollment_id' => ['required', 'integer', new BelongsToCurrentOrganization(Enrollment::class)],
            'second_enrollment_id' => [
                'required', 'integer', 'different:first_enrollment_id',
                new BelongsToCurrentOrganization(Enrollment::class),
            ],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'second_enrollment_id.different' => __('Escolha dois alunos diferentes.'),
            'effective_from.required' => __('Indique a partir de quando a permuta passa a vigorar.'),
            'effective_from.date_format' => __('Indique uma data de entrada em vigor válida.'),
        ];
    }
}
