<?php

namespace App\Http\Requests\Lessons;

use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\TeacherStrikeSituation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Corrigir: a aula não se realizou» (0.194.0). Só a causa, os seus detalhes e o
 * motivo da correção: o resultado é sempre «Aula não realizada» (AnnulTaughtLesson).
 */
class AnnulTaughtLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson instanceof Lesson && $this->user()?->can('annulTaught', $lesson) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'cause' => ['required', 'string', Rule::enum(LessonNotHeldCause::class)],
            'situation' => ['nullable', 'string', Rule::in(array_column(TeacherStrikeSituation::available(), 'value'))],
            'cause_note' => [Rule::requiredIf(fn (): bool => $this->input('cause') === LessonNotHeldCause::Other->value), 'nullable', 'string', 'max:160'],
            'rectification_note' => ['required', 'string', 'min:3', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cause.required' => __('Indica a causa da aula não realizada.'),
            'cause_note.required' => __('Descreve a outra causa.'),
            'rectification_note.required' => __('Indica o motivo da correção.'),
            'rectification_note.min' => __('Indica o motivo da correção (pelo menos 3 carateres).'),
            'rectification_note.max' => __('O motivo da correção tem no máximo 200 carateres.'),
        ];
    }

    public function cause(): LessonNotHeldCause
    {
        return LessonNotHeldCause::from($this->string('cause')->toString());
    }

    public function situation(): ?TeacherStrikeSituation
    {
        return $this->filled('situation') ? TeacherStrikeSituation::from($this->string('situation')->toString()) : null;
    }

    public function causeNote(): ?string
    {
        return $this->filled('cause_note') ? $this->string('cause_note')->toString() : null;
    }

    public function motive(): string
    {
        return $this->string('rectification_note')->toString();
    }
}
