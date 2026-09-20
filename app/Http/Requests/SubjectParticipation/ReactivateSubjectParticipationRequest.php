<?php

namespace App\Http\Requests\SubjectParticipation;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\SubjectParticipation;
use App\Rules\BelongsToCurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «O João volta a frequentar Português a partir de 2 de janeiro.»
 *
 * Ver o docblock de `MarkNotAttendingSubjectRequest`: as regras de negócio
 * ficam em `SubjectParticipationRules`/`ReactivateSubjectParticipation`, não
 * aqui.
 */
class ReactivateSubjectParticipationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $schoolClass = $this->route('class');

        return $user !== null
            && $schoolClass instanceof SchoolClass
            && $user->can('manage', [SubjectParticipation::class, $schoolClass]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enrollment_id' => ['required', 'integer', new BelongsToCurrentOrganization(Enrollment::class)],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'effective_from.required' => __('Indique a partir de quando o aluno volta a frequentar a disciplina.'),
            'effective_from.date_format' => __('Indique uma data válida.'),
        ];
    }
}
