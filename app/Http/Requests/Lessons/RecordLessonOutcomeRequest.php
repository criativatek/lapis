<?php

namespace App\Http\Requests\Lessons;

use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\TeacherAbsenceReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Professor ausente» ou «Turma em outras atividades letivas» (0.146.0).
 * «Lecionada» tem rota própria (mark-taught) e não passa por aqui.
 *
 * O motivo da ausência é SÓ uma categoria; não existe campo de texto livre
 * para ele. A nota curta existe só na atividade da turma.
 */
class RecordLessonOutcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson instanceof Lesson && $this->user()?->can('update', $lesson) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', Rule::in([LessonOutcome::TeacherAbsent->value, LessonOutcome::ClassExternalActivity->value])],
            'reason' => [
                Rule::requiredIf(fn (): bool => $this->input('outcome') === LessonOutcome::TeacherAbsent->value),
                Rule::prohibitedIf(fn (): bool => $this->input('outcome') !== LessonOutcome::TeacherAbsent->value),
                'nullable', 'string', Rule::enum(TeacherAbsenceReason::class),
            ],
            'note' => [
                Rule::prohibitedIf(fn (): bool => $this->input('outcome') !== LessonOutcome::ClassExternalActivity->value && filled($this->input('note'))),
                'nullable', 'string', 'max:160',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('Indica o motivo da ausência.'),
            'reason.prohibited' => __('O motivo só se indica numa ausência do professor.'),
            'note.prohibited' => __('A descrição só se indica numa atividade da turma.'),
            'note.max' => __('A descrição tem no máximo 160 caracteres.'),
        ];
    }

    public function outcome(): LessonOutcome
    {
        return LessonOutcome::from($this->string('outcome')->toString());
    }

    public function reason(): ?TeacherAbsenceReason
    {
        return $this->filled('reason') ? TeacherAbsenceReason::from($this->string('reason')->toString()) : null;
    }

    public function note(): ?string
    {
        return $this->filled('note') ? $this->string('note')->toString() : null;
    }
}
