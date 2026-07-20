<?php

namespace App\Support\Assessment;

use RuntimeException;

/**
 * Multi-row rules an instrument must satisfy (§12.3). They span rows, so they
 * cannot be CHECK constraints and live in the builder instead.
 */
class InstrumentValidationException extends RuntimeException
{
    public static function noItems(): self
    {
        return new self(__('O instrumento precisa de pelo menos uma questão ou critério.'));
    }

    public static function allocationsMustTotal100(string $itemCode, string $actual): self
    {
        return new self(__(
            'A distribuição por domínios da questão :code tem de somar 100%. Total atual: :total%.',
            ['code' => $itemCode, 'total' => $actual],
        ));
    }

    public static function pointsDoNotMatchTotal(string $itemTotal, string $declared): self
    {
        return new self(__(
            'A soma das cotações (:items) não corresponde à cotação total (:declared). '.
            'Ative a opção de bónus se for intencional.',
            ['items' => $itemTotal, 'declared' => $declared],
        ));
    }
}
