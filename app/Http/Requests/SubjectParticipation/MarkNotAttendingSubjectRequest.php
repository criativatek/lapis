<?php

namespace App\Http\Requests\SubjectParticipation;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\SubjectParticipation;
use App\Models\SubjectParticipationReason;
use App\Rules\BelongsToCurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «O João não frequenta Português a partir de 15 de novembro — vai a PLNM.»
 *
 * Só a forma do pedido é validada aqui. As regras de negócio — a data cair
 * dentro do ano letivo, não haver já uma janela mais recente — pertencem a
 * `SubjectParticipationRules`, chamada pela ação com a turma já resolvida;
 * duplicá-las aqui divergiria delas mais tarde a menos que se lembrasse de as
 * corrigir nos dois sítios.
 */
class MarkNotAttendingSubjectRequest extends FormRequest
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
            'reason' => ['required', Rule::enum(SubjectParticipationReason::class)],
            'reason_detail' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'effective_from.required' => __('Indique a partir de quando o aluno deixa de frequentar a disciplina.'),
            'effective_from.date_format' => __('Indique uma data válida.'),
            'reason.required' => __('Indique o motivo.'),
        ];
    }
}
