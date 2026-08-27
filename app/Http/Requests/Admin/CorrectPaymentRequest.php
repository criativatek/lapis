<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A refund or a void. Both carry exactly one field, and it is mandatory.
 *
 * THE REASON IS THE WHOLE POINT. A payment that leaves the revenue total
 * without a recorded «porquê» is a hole in the accounts that nobody can close a
 * year later. `min:3` is not a formality — it refuses «.» and «x», which is
 * what a required field with no floor collects in practice.
 */
class CorrectPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('Indique o motivo. Um pagamento não sai da receita sem justificação registada.'),
            'reason.min' => __('Descreva o motivo com um mínimo de detalhe.'),
        ];
    }
}
