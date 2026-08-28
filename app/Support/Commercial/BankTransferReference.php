<?php

namespace App\Support\Commercial;

use App\Models\SubscriptionPayment;

/**
 * A referência que o cliente escreve na descrição da transferência.
 *
 * É por ela que quem confirma liga uma linha do extrato bancário a um pedido —
 * muitas vezes lida ao telefone ou copiada à mão de um PDF do banco. Por isso o
 * alfabeto **não tem O nem 0, nem I nem 1**, os quatro caracteres que se trocam
 * sempre, e o comprimento é curto o suficiente para se ditar.
 *
 * Seis caracteres em 32 dão mil milhões de combinações. A colisão verifica-se
 * na mesma, porque «improvável» e «impossível» não são a mesma coisa.
 */
class BankTransferReference
{
    private const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const COMPRIMENTO = 6;

    public function generate(): string
    {
        $prefixo = (string) config('billing.reference_prefix');

        for ($tentativa = 0; $tentativa < 20; $tentativa++) {
            $candidato = $prefixo.'-'.$this->sorteia();

            $existe = SubscriptionPayment::query()
                ->withoutGlobalScope('organization')
                ->where('provider_reference', $candidato)
                ->exists();

            if (! $existe) {
                return $candidato;
            }
        }

        // Vinte colisões seguidas não acontecem por acaso: ou o alfabeto
        // encolheu ou o gerador está partido. Falhar é melhor do que devolver
        // uma referência repetida que ninguém consegue distinguir no extrato.
        throw new \RuntimeException('Não foi possível gerar uma referência de pagamento única.');
    }

    private function sorteia(): string
    {
        $saida = '';

        for ($i = 0; $i < self::COMPRIMENTO; $i++) {
            $saida .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
        }

        return $saida;
    }
}
