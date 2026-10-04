<?php

namespace App\Http\Requests\Lessons;

use App\Models\Lesson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Editar SÓ o texto do sumário — o cartão da semana e a vista da turma.
 *
 * Aceita `content` e a versão que o ecrã viu, e mais nada: notas privadas,
 * recursos, TPC e faltas têm o seu próprio ecrã, e um pedido que os trouxesse
 * (por engano ou à mão) seria ignorado em vez de os sobrescrever. A autorização
 * é a de LessonSummaryRequest.
 */
class LessonSummaryContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson instanceof Lesson && $this->user()?->can('update', $lesson) === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('content'))) {
            $this->merge(['content' => trim($this->string('content')->toString())]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:16000'],
            'summary_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
