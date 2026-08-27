<?php

namespace App\Support\Commercial;

use RuntimeException;

/**
 * A commercial condition that must not be written. Same shape as
 * `PaymentCorrectionException` and `App\Support\Trial\TrialException`.
 */
class CommercialConditionException extends RuntimeException
{
    public static function trialIsDerived(): self
    {
        return new self(__('Uma subscrição em experiência já regista a sua condição no estado — não pode receber outra.'));
    }

    public static function wrongOrganization(): self
    {
        return new self(__('Esta subscrição não pertence a esta conta.'));
    }
}
