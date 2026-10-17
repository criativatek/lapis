<?php

namespace App\Http\Requests\Lessons;

use App\Models\Lesson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Registar e adiar a preparação» (0.171.0) — a pré-visualização e a execução
 * partilham o pedido; só a execução leva a impressão digital do plano e a
 * confirmação da cascata.
 *
 * O ULID do acontecimento é só um identificador: quem decide se ele é do
 * professor, da turma e coincide com a aula é LessonDayEvents, no controlador.
 */
class PostponeLessonForActivityRequest extends FormRequest
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
        $isPreview = $this->routeIs('lessons.activity-postponement.preview');

        return [
            'calendar_event' => ['required', 'string', 'max:40'],
            'plan_token' => [$isPreview ? 'nullable' : 'required', 'string', 'size:64'],
            'confirm_cascade' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'calendar_event.required' => __('Escolhe o acontecimento do calendário.'),
            'plan_token.required' => __('Pede primeiro a pré-visualização e confirma o que ela mostra.'),
        ];
    }
}
