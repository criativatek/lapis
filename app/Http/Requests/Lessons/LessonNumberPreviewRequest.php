<?php

namespace App\Http\Requests\Lessons;

use App\Actions\Lessons\NumberChange;
use App\Http\Requests\Lessons\Concerns\ValidatesLessonNumber;
use App\Models\Lesson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A pré-visualização do número de uma aula (0.169.0): `mode` = `manual` com o
 * início (e, se a aula corresponde a várias lições, o fim) ou `automatic` para
 * repor a sugestão. Só lê; a autorização é a de editar a aula.
 */
class LessonNumberPreviewRequest extends FormRequest
{
    use ValidatesLessonNumber;

    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson instanceof Lesson && $this->user()?->can('update', $lesson) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->blankNumberFieldsToNull(['start', 'end']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->lessonNumberRules('start', 'end');
        $rules['start'] = ['required_if:mode,manual', 'nullable', 'integer', 'min:1', 'max:9999'];

        return ['mode' => ['required', Rule::in(['manual', 'automatic'])]] + $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->lessonNumberMessages('start', 'end') + [
            'start.required_if' => __('Indique o número da lição.'),
        ];
    }

    public function numberChange(): NumberChange
    {
        if ($this->validated('mode') === 'automatic') {
            return NumberChange::automatic();
        }

        $end = $this->validated('end');

        return NumberChange::manual((int) $this->validated('start'), is_numeric($end) ? (int) $end : null);
    }
}
