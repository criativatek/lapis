<?php

namespace App\Support\Commercial;

/**
 * O que a pergunta «este código vale para este resgate?» pode responder.
 *
 * UM RESULTADO, NÃO UMA EXCEÇÃO: a maior parte dos chamadores — a landing, o
 * checkout — quer distinguir os casos para falar com o utilizador, e um enum
 * obriga o `match` a cobri-los todos. `VoucherUnavailable` transporta um destes
 * quando a resposta tem mesmo de interromper (o resgate propriamente dito).
 *
 * O QUE SE DIZ CÁ FORA É OUTRA DECISÃO. `publicCategory()` achata isto nas
 * categorias que a página pública pode dizer sem confirmar a existência de
 * códigos a quem anda a adivinhar — ver o comentário lá.
 */
enum VoucherOutcome: string
{
    case Valid = 'valid';

    /** Nem sequer tem a forma de um código. Nunca tocou na base de dados. */
    case Malformed = 'malformed';

    case NotFound = 'not_found';

    /** Emitido, mas a janela ainda não abriu. */
    case NotStarted = 'not_started';

    /** A janela fechou. */
    case Expired = 'expired';

    /** Todos os lugares do código estão tomados por confirmações ou reservas vivas. */
    case Exhausted = 'exhausted';

    /** Desactivado por um operador. */
    case Disabled = 'disabled';

    /** O código existe mas não serve o plano-alvo deste resgate. */
    case WrongPlan = 'wrong_plan';

    /** Esta organização já o resgatou — cada código, uma vez por organização. */
    case AlreadyRedeemed = 'already_redeemed';

    public function isValid(): bool
    {
        return $this === self::Valid;
    }

    /**
     * O que a página PÚBLICA pode dizer, sem sessão e sem confirmar existências.
     *
     * TRÊS CATEGORIAS, DE PROPÓSITO. «Expirado» e «esgotado» só se dizem de um
     * código que EXISTE — quem o tem na mão merece saber que chegou tarde, e
     * dizê-lo não dá nada a quem anda a enumerar (o código já não serve a
     * ninguém). Tudo o resto — não existe, desactivado, mal formado — colapsa
     * em «inválido», porque distinguir «nunca existiu» de «foi desactivado»
     * é exactamente o mapa que um atacante quereria.
     */
    public function publicCategory(): string
    {
        return match ($this) {
            self::Valid => 'valid',
            self::Expired, self::Exhausted => 'expired',
            self::Malformed, self::NotFound, self::NotStarted,
            self::Disabled, self::WrongPlan, self::AlreadyRedeemed => 'invalid',
        };
    }
}
