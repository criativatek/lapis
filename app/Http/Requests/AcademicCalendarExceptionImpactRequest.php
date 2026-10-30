<?php

namespace App\Http\Requests;

use App\Actions\Lessons\CalendarExceptionChange;
use Illuminate\Validation\Rule;

/**
 * A pré-visualização do impacto de uma alteração ao calendário: o que
 * aconteceria às aulas já existentes se a exceção fosse criada, alterada ou
 * eliminada. Nada se escreve.
 *
 * Para `create` e `update` valida EXATAMENTE como o store/update (as regras e a
 * guarda «dentro do ano letivo» são as da classe-mãe); para `delete` só valida
 * a ação e o identificador — a pertença da exceção ao ano é verificada no
 * controlador (404), como no resto.
 */
class AcademicCalendarExceptionImpactRequest extends AcademicCalendarExceptionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $action = $this->input('action');
        $needsException = in_array($action, [CalendarExceptionChange::UPDATE, CalendarExceptionChange::DELETE], true);

        $rules = [
            'action' => ['required', Rule::in([CalendarExceptionChange::CREATE, CalendarExceptionChange::UPDATE, CalendarExceptionChange::DELETE])],
            'exception' => [Rule::requiredIf($needsException), 'nullable', 'string', 'max:40'],
        ];

        if (in_array($action, [CalendarExceptionChange::CREATE, CalendarExceptionChange::UPDATE], true)) {
            // A pré-visualização não confirma nada: sem reconcile nem plan_token.
            return $rules + array_diff_key(parent::rules(), self::reconcileRules());
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return parent::messages() + [
            'action.required' => __('Indique a operação: criar, alterar ou eliminar.'),
            'action.in' => __('Indique a operação: criar, alterar ou eliminar.'),
            'exception.required' => __('Indique a exceção do calendário.'),
        ];
    }
}
