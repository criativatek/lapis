<?php

namespace App\Support\Support;

use App\Models\SupportRequest;
use RuntimeException;

/**
 * `SUP-XXXXXX` — o número de protocolo de um pedido.
 *
 * O ALFABETO É O MESMO de `BankTransferReference` e de `VoucherCode`, e pela
 * mesma razão: **sem O nem 0, sem I nem 1**. Uma referência de suporte é lida
 * ao telefone, ditada numa formação, escrita à mão num papel — e os quatro
 * caracteres que se trocam sempre não podem estar lá.
 *
 * NÃO É UMA CREDENCIAL, E O TAMANHO DIZ ISSO. Seis caracteres em 32 são ~10^9
 * combinações: bastante para não colidir na vida deste produto, muito pouco
 * para segurar um segredo. É deliberado — nenhum ecrã aceita uma referência
 * como forma de aceder a um pedido (ADR-0011 §3). Se algum dia alguém propuser
 * «recuperar pelo número», o tamanho deste código é a resposta.
 */
final class SupportReference
{
    /** Sem O/0 nem I/1. O mesmo de `BankTransferReference`, deliberadamente. */
    private const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const TAMANHO = 6;

    private const PREFIXO = 'SUP';

    /**
     * Uma referência nova, única.
     *
     * @throws RuntimeException se vinte sorteios seguidos colidirem: num espaço
     *                          de 10^9 isso não acontece por acaso, e falhar é
     *                          melhor do que devolver um número repetido a duas
     *                          pessoas diferentes.
     */
    public static function generate(): string
    {
        for ($tentativa = 0; $tentativa < 20; $tentativa++) {
            $candidato = self::PREFIXO.'-'.self::sorteia();

            if (! SupportRequest::query()->where('reference', $candidato)->exists()) {
                return $candidato;
            }
        }

        throw new RuntimeException('Não foi possível gerar uma referência de suporte única.');
    }

    private static function sorteia(): string
    {
        $codigo = '';

        for ($i = 0; $i < self::TAMANHO; $i++) {
            $codigo .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
        }

        return $codigo;
    }
}
