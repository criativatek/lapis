<?php

namespace App\Http\Requests\Lessons;

use Illuminate\Foundation\Http\FormRequest;

class WeeklyLessonsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * `class` e `group` aceitam qualquer texto de propósito: um ulid de turma
     * que o professor já não leciona, ou um grupo que já não existe, não pode
     * dar 422 numa vista que se abre por ligação — a vista da turma cai na
     * turma por omissão e no filtro «todos» (ClassLessonsView).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'week' => ['sometimes', 'date_format:Y-m-d'],
            'view' => ['sometimes', 'in:semana,turma,horario'],
            'class' => ['sometimes', 'nullable', 'string', 'max:64'],
            'group' => ['sometimes', 'nullable', 'string', 'max:32'],
            'range' => ['sometimes', 'in:1,2,4,ano'],
        ];
    }
}
