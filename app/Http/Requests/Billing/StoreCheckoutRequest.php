<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Os dados de faturação, validados antes de tocarem em alguma coisa.
 *
 * O NIF É OPCIONAL, e é uma decisão e não um esquecimento: um consumidor
 * particular pode pedir fatura sem contribuinte, e obrigar afastaria os
 * professores que compram a título individual. Quando vem, tem de ser
 * plausível — nove dígitos, para Portugal.
 *
 * O PLANO NÃO VEM DAQUI. Vem da rota, e a única coisa que o cliente escolhe é
 * para onde vai a fatura. Aceitar um `plan_id` no corpo seria aceitar que
 * alguém subscrevesse o Institucional ao preço do Pro.
 */
class StoreCheckoutRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:20', Rule::when(
                $this->input('country', 'PT') === 'PT',
                ['regex:/^\d{9}$/'],
            )],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'size:2'],
            'email' => ['required', 'email', 'max:255'],
            // Um código de voucher, opcional. Só a FORMA se valida aqui — a
            // existência, a janela e a capacidade são decididas pelo motor, no
            // controlador e depois sob lock. Ver `VoucherCode::isWellFormed()`.
            'voucher_code' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['tax_number.regex' => __('O NIF português tem nove dígitos.')];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => __('nome'),
            'tax_number' => __('NIF'),
            'address_line1' => __('morada'),
            'address_line2' => __('complemento da morada'),
            'postal_code' => __('código postal'),
            'city' => __('localidade'),
            'country' => __('país'),
            'email' => __('email de faturação'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'country' => strtoupper((string) $this->input('country', 'PT')),
            'tax_number' => blank($this->input('tax_number'))
                ? null
                : preg_replace('/\s+/', '', (string) $this->input('tax_number')),
        ]);
    }
}
