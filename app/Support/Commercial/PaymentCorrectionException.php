<?php

namespace App\Support\Commercial;

use App\Models\PaymentStatus;
use RuntimeException;

/**
 * A correction that must not happen. Mirrors `App\Support\Trial\TrialException`:
 * named constructors so the refusal reads the same in the action that raises it
 * and in the controller that reports it, and messages already in pt-PT because
 * they are shown to the operator.
 */
class PaymentCorrectionException extends RuntimeException
{
    public static function reasonRequired(): self
    {
        return new self(__('Indique o motivo da correção.'));
    }

    public static function notRefundable(PaymentStatus $status): self
    {
        return new self(__('Só um pagamento pago pode ser reembolsado. Este está :status — para o corrigir, anule-o.', [
            'status' => mb_strtolower($status->label()),
        ]));
    }

    public static function alreadyCorrected(PaymentStatus $status): self
    {
        return new self(__('Este pagamento já foi corrigido (:status) e não pode ser alterado outra vez.', [
            'status' => mb_strtolower($status->label()),
        ]));
    }
}
