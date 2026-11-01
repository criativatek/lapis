<?php

namespace App\Http\Requests\Lessons;

use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\LessonOutcome;
use App\Models\TeacherAbsenceReason;
use App\Models\TeacherStrikeSituation;
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
        $rules = [
            'outcome' => ['required', 'string', Rule::in($this->acceptedOutcomes())],
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

        // «Aula não realizada»: os campos só existem no pedido com a funcionalidade
        // ligada (`lapis.lesson_not_held_enabled`) — e o texto legal fixa o que o
        // pedido aceita hoje (LessonOutcomeLegalTest). A coerência (a situação só
        // com greve, a descrição só com «outra causa») é a de RecordLessonOutcome.
        if ($this->notHeldEnabled()) {
            $rules += [
                'cause' => [
                    Rule::requiredIf(fn (): bool => $this->input('outcome') === LessonOutcome::NotHeld->value),
                    Rule::prohibitedIf(fn (): bool => $this->input('outcome') !== LessonOutcome::NotHeld->value),
                    'nullable', 'string', Rule::enum(LessonNotHeldCause::class),
                ],
                'situation' => ['nullable', 'string', Rule::enum(TeacherStrikeSituation::class)],
                'cause_note' => ['nullable', 'string', 'max:160'],
                // Registo retroativo: só pedido, nunca presumido. A aula tem de ser vazia (RecordLessonOutcome).
                'retroactive' => ['sometimes', 'boolean', Rule::prohibitedIf(fn (): bool => $this->input('outcome') !== LessonOutcome::NotHeld->value)],
            ];
        }

        return $rules;
    }

    private function notHeldEnabled(): bool
    {
        return (bool) config('lapis.lesson_not_held_enabled', false);
    }

    /**
     * @return list<string>
     */
    private function acceptedOutcomes(): array
    {
        $accepted = [LessonOutcome::TeacherAbsent->value, LessonOutcome::ClassExternalActivity->value];

        // Desligado por defeito: ver `lapis.lesson_not_held_enabled`.
        if ($this->notHeldEnabled()) {
            $accepted[] = LessonOutcome::NotHeld->value;
        }

        return $accepted;
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
            'cause.required' => __('Indica a causa da aula não realizada.'),
            'cause.prohibited' => __('A causa só se indica numa aula não realizada.'),
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

    public function cause(): ?LessonNotHeldCause
    {
        return $this->filled('cause') ? LessonNotHeldCause::from($this->string('cause')->toString()) : null;
    }

    public function situation(): ?TeacherStrikeSituation
    {
        return $this->filled('situation') ? TeacherStrikeSituation::from($this->string('situation')->toString()) : null;
    }

    public function retroactive(): bool
    {
        return $this->boolean('retroactive');
    }

    public function causeNote(): ?string
    {
        return $this->filled('cause_note') ? $this->string('cause_note')->toString() : null;
    }

    public function note(): ?string
    {
        return $this->filled('note') ? $this->string('note')->toString() : null;
    }
}
