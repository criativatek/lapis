<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Eliminar uma exceção letiva: o pedido não traz dados da exceção, só (opcional)
 * o pedido de reconciliar as aulas já existentes e o `plan_token` da
 * pré-visualização. A autorização é a do controlador (AcademicYearPolicy).
 */
class AcademicCalendarExceptionDeleteRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return AcademicCalendarExceptionRequest::reconcileRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return AcademicCalendarExceptionRequest::reconcileMessages();
    }
}
