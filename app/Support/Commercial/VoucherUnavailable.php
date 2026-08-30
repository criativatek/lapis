<?php

namespace App\Support\Commercial;

use RuntimeException;

/**
 * Um resgate que não pode acontecer, e porquê.
 *
 * Transporta o `VoucherOutcome` para que o chamador que apanha a exceção possa
 * falar com o utilizador na língua do caso concreto, em vez de re-perguntar ao
 * resolver o que a transação acabou de decidir sob lock.
 */
class VoucherUnavailable extends RuntimeException
{
    public function __construct(
        public readonly VoucherOutcome $outcome,
        string $message,
    ) {
        parent::__construct($message);
    }
}
