<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmar que a transferência de um pedido pendente entrou.
 *
 * O VALOR VEM DE QUEM VÊ O EXTRATO, e não do pedido. Se o cliente transferiu
 * 44,90 € quando o pedido dizia 29,90 €, é 44,90 € que se regista — o pedido
 * era uma intenção, o extrato é o facto.
 *
 * A DATA É OBRIGATÓRIA porque uma linha `paid` sem ela contaria para a receita
 * total e cairia fora de todos os totais por período.
 */
class ConfirmTransferRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'paid_at' => ['required', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'amount' => __('valor recebido'),
            'paid_at' => __('data em que o dinheiro entrou'),
        ];
    }

    public function amountCents(): int
    {
        return (int) round(((float) $this->validated('amount')) * 100);
    }
}
