<?php

namespace App\Http\Requests\Lessons;

use App\Models\Lesson;
use App\Models\TeacherStrikeSituation;
use Illuminate\Validation\Rule;

/**
 * Corrigir o resultado já registado de uma aula (RectifyLessonOutcome). Os campos do resultado são os de
 * RecordLessonOutcomeRequest — a mesma validação —, sem o registo retroativo, e com o MOTIVO da correção,
 * obrigatório. A autorização é a da correção, mais estrita do que a do registo.
 */
class RectifyLessonOutcomeRequest extends RecordLessonOutcomeRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson instanceof Lesson && $this->user()?->can('rectifyOutcome', $lesson) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['retroactive']);

        $rules['rectification_note'] = ['required', 'string', 'min:3', 'max:200'];

        // A especificação que a aula JÁ TEM continua aceite (RectifyLessonOutcome diz porquê); uma nova
        // continua a seguir o que a chave da adesão oferece.
        $lesson = $this->route('lesson');
        $stored = $lesson instanceof Lesson ? $lesson->outcome_teacher_situation?->value : null;

        if ($stored !== null && isset($rules['situation'])) {
            $rules['situation'] = ['nullable', 'string', Rule::in([...array_column(TeacherStrikeSituation::available(), 'value'), $stored])];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return parent::messages() + [
            'rectification_note.required' => __('Indica o motivo da correção.'),
            'rectification_note.min' => __('Indica o motivo da correção (pelo menos 3 carateres).'),
            'rectification_note.max' => __('O motivo da correção tem no máximo 200 carateres.'),
        ];
    }

    public function motive(): string
    {
        return $this->string('rectification_note')->toString();
    }
}
