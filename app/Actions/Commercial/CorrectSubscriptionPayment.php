<?php

namespace App\Actions\Commercial;

use App\Models\Organization;
use App\Models\PaymentStatus;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Commercial\PaymentCorrectionException;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only way a recorded payment ever changes — and it never changes the money.
 *
 * `SubscriptionPayment` refuses at the model level any update touching the
 * amount, the currency, the date the money arrived, the organization, the period
 * or who recorded it. So "correcting" a payment cannot mean editing it. It means
 * moving its STATUS, with a reason and a name attached, and leaving the original
 * figure and date exactly where they are:
 *
 *  - **Refund** — the money went back to the customer. The payment stays in the
 *    history at its original amount, and leaves net revenue entirely, because
 *    only `paid` counts. TOTAL REFUNDS ONLY: this schema carries one amount, so
 *    a partial refund cannot be represented honestly and is not faked.
 *  - **Void** — the payment should never have been recorded (typed twice, wrong
 *    account, wrong figure). Same mechanism, different meaning, and the right
 *    fix for a wrong amount: void the bad row with a reason, record the correct
 *    one. Two rows and an audit trail, instead of one row that quietly became
 *    something else.
 *
 * A REASON IS MANDATORY in both cases, and both are refused on a payment that is
 * already refunded or cancelled — correcting a correction would be editing
 * history through the back door, and the trail would no longer say what
 * happened.
 */
class CorrectSubscriptionPayment
{
    public function __construct(
        protected AuditLog $audit,
        protected CurrentOrganization $currentOrganization,
    ) {}

    /**
     * The money went back. Only a payment that actually counted as revenue can
     * be refunded — refunding a `pending` or `failed` one would claim money
     * moved that never did; void it instead.
     *
     * @throws PaymentCorrectionException
     */
    public function refund(SubscriptionPayment $payment, Organization $organization, User $operator, string $reason): SubscriptionPayment
    {
        if ($payment->status !== PaymentStatus::Paid) {
            throw PaymentCorrectionException::notRefundable($payment->status);
        }

        return $this->transition($payment, $organization, $operator, PaymentStatus::Refunded, $reason, 'commercial.payment_refunded', 'Pagamento reembolsado');
    }

    /**
     * The record was wrong. Available from any non-terminal status, because a
     * mistyped `pending` is as wrong as a mistyped `paid`.
     *
     * @throws PaymentCorrectionException
     */
    public function void(SubscriptionPayment $payment, Organization $organization, User $operator, string $reason): SubscriptionPayment
    {
        return $this->transition($payment, $organization, $operator, PaymentStatus::Cancelled, $reason, 'commercial.payment_voided', 'Pagamento anulado');
    }

    /**
     * @throws PaymentCorrectionException
     */
    protected function transition(
        SubscriptionPayment $payment,
        Organization $organization,
        User $operator,
        PaymentStatus $status,
        string $reason,
        string $event,
        string $summaryVerb,
    ): SubscriptionPayment {
        $reason = trim($reason);

        if ($reason === '') {
            throw PaymentCorrectionException::reasonRequired();
        }

        return DB::transaction(function () use ($payment, $organization, $operator, $status, $reason, $event, $summaryVerb): SubscriptionPayment {
            // Re-read under lock. Two operators pressing "reembolsar" on the
            // same payment must not both succeed and both write an audit line
            // claiming they were the one who did it.
            $locked = SubscriptionPayment::query()
                ->withoutGlobalScope('organization')
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->isTerminal()) {
                throw PaymentCorrectionException::alreadyCorrected($locked->status);
            }

            $previous = $locked->status;

            // Exactly the four columns the model's guard allows. Anything else
            // here would throw, which is the intended safety net rather than a
            // thing to work around.
            $locked->forceFill([
                'status' => $status,
                'status_changed_at' => Carbon::now(),
                'status_reason' => $reason,
                'status_changed_by' => $operator->getKey(),
            ])->save();

            $this->currentOrganization->runFor($organization, fn () => $this->audit->record(
                $event,
                $organization,
                $operator,
                summary: sprintf(
                    '%s: %s %s. Motivo: %s',
                    $summaryVerb,
                    number_format($locked->amount_cents / 100, 2, ',', ' '),
                    $locked->currency,
                    $reason,
                ),
                properties: [
                    'payment_ulid' => $locked->ulid,
                    // The figure is repeated into the trail deliberately: the
                    // audit line must stay readable even to somebody who only
                    // has the trail in front of them.
                    'amount_cents' => $locked->amount_cents,
                    'currency' => $locked->currency,
                    'previous_status' => $previous->value,
                    'status' => $status->value,
                    'reason' => $reason,
                ],
            ));

            return $locked;
        });
    }
}
