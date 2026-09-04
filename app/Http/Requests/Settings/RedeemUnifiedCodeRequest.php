<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * O único campo "Tem um código?" da página do Plano. Não sabe qual dos dois
 * universos de código (comercial ou de capacidades) vai servir o pedido — só
 * valida a forma mínima do texto; a resolução por existência acontece em
 * `App\Http\Controllers\Settings\PlanController::redeemCode()`.
 */
class RedeemUnifiedCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:64']];
    }
}
