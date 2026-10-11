<?php

namespace App\Http\Requests\Lessons;

use App\Services\Lessons\SummarySearchText;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET lessons/search`. O termo é validado DEPOIS de normalizado: «  ç » ou
 * só aspas não são uma pesquisa, e o mínimo de dois caracteres conta o que
 * realmente se procura.
 */
class LessonSummarySearchRequest extends FormRequest
{
    public const STATES = ['taught', 'prepared', 'other'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:200'],
            'classes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'states' => ['sometimes', 'nullable', 'string', 'max:100'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // `after_or_equal:from` só quando `from` vem: sem ele a regra
            // compararia com um campo que não existe.
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', Rule::when($this->filled('from'), 'after_or_equal:from')],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.required' => __('Escreve pelo menos dois caracteres para pesquisar.'),
            'to.after_or_equal' => __('A data final não pode ser anterior à inicial.'),
            'from.date_format' => __('A data inicial tem de estar no formato AAAA-MM-DD.'),
            'to.date_format' => __('A data final tem de estar no formato AAAA-MM-DD.'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $term = $this->input('q');

            if (is_string($term)
                && ! $validator->errors()->has('q')
                && mb_strlen(SummarySearchText::normalize(SummarySearchText::cleanTerm($term))) < 2) {
                $validator->errors()->add('q', __('Escreve pelo menos dois caracteres para pesquisar.'));
            }

            if (! $validator->errors()->has('states') && array_diff($this->states(), self::STATES) !== []) {
                $validator->errors()->add('states', __('Os estados pedidos não existem.'));
            }
        });
    }

    /**
     * Os estados pedidos; `taught` quando não vêm.
     *
     * @return list<string>
     */
    public function states(): array
    {
        $states = $this->commaSeparated('states');

        return $states === [] ? ['taught'] : $states;
    }

    /**
     * @return list<string>
     */
    public function classUlids(): array
    {
        return $this->commaSeparated('classes');
    }

    /**
     * @return list<string>
     */
    private function commaSeparated(string $key): array
    {
        $raw = $this->input($key);

        if (! is_string($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            fn (string $value): bool => $value !== '',
        )));
    }
}
