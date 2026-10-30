<?php

namespace App\Http\Requests\Lessons;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Os pedidos do professor sobre as SUAS aulas em dias não letivos: o ano
 * (opcional — por omissão, o ano corrente) e, ao regularizar, o `plan_token` da
 * pré-visualização. A autorização é a da ação (só as turmas que leciona).
 */
class CalendarConflictRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'academic_year' => ['nullable', 'string', 'max:40'],
            // Um sha256 em hexadecimal, e mais nada: um token malformado é
            // recusado AQUI, antes de a execução prender o ano e as turmas.
            'plan_token' => [$this->routeIs('lessons.calendar-conflicts.regularize') ? 'required' : 'nullable', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_token.required' => __('Pede primeiro a pré-visualização e confirma o que ela mostra.'),
            'plan_token.size' => __('A pré-visualização é inválida. Pede-a de novo.'),
            'plan_token.regex' => __('A pré-visualização é inválida. Pede-a de novo.'),
        ];
    }
}
