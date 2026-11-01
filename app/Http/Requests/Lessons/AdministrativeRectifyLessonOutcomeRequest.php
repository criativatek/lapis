<?php

namespace App\Http\Requests\Lessons;

use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A correção ADMINISTRATIVA da causa de uma aula não realizada (RectifyLessonOutcome::executeAsAdministrator).
 * Só a causa, a descrição de «outra causa» e o motivo: nenhum campo pessoal (nem resultado, nem motivo de
 * ausência, nem especificação perante a greve) existe neste pedido.
 */
class AdministrativeRectifyLessonOutcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson instanceof Lesson && $this->user()?->can('rectifyOutcomeAsAdministrator', $lesson) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cause' => ['required', 'string', Rule::enum(LessonNotHeldCause::class)],
            'cause_note' => ['nullable', 'string', 'max:160'],
            'rectification_note' => ['required', 'string', 'min:3', 'max:200'],
        ];
    }

    public function cause(): LessonNotHeldCause
    {
        return LessonNotHeldCause::from($this->string('cause')->toString());
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
