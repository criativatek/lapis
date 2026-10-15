<?php

namespace App\Http\Requests\Lessons;

use App\Http\Requests\Lessons\Concerns\ValidatesLessonNumber;
use App\Models\Lesson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Editar SÓ o texto do sumário — o cartão da semana e a vista da turma.
 *
 * Aceita `content`, a versão que o ecrã viu e — opcionalmente — o número da
 * lição (0.169.0), e mais nada: notas privadas,
 * recursos, TPC e faltas têm o seu próprio ecrã, e um pedido que os trouxesse
 * (por engano ou à mão) seria ignorado em vez de os sobrescrever. A autorização
 * é a de LessonSummaryRequest.
 */
class LessonSummaryContentRequest extends FormRequest
{
    use ValidatesLessonNumber;

    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson instanceof Lesson && $this->user()?->can('update', $lesson) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->blankNumberFieldsToNull(['lesson_number', 'lesson_number_end']);

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
            ...$this->lessonNumberRules('lesson_number', 'lesson_number_end'),
            'lesson_number_automatic' => ['sometimes', 'boolean'],
            'lesson_number_confirmation' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->lessonNumberMessages('lesson_number', 'lesson_number_end');
    }
}
