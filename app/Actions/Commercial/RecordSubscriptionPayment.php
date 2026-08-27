<?php

namespace App\Actions\Commercial;

use App\Models\CommercialCondition;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Commercial\EffectiveSubscriptions;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Records a payment the operator has already received.
 *
 * There is no gateway, so this is the only way money enters the system: a bank
 * transfer or an MB WAY that a person confirmed, typed in afterwards. The row it
 * writes is the ONLY thing revenue is ever computed from.
 *
 * WHAT THIS ACTION REFUSES TO DO, and the refusals are the point:
 *
 *  - It never infers the amount. No price list is consulted, no plan is looked
 *    at; the figure is the one the operator was actually paid. If Pro costs
 *    44,90 EUR and somebody paid 29,90 EUR, this stores 29,90 EUR — and never
 *    concludes anything from that.
 *  - It never infers the commercial condition FROM the amount. Paying 29,90 EUR
 *    does not make an account a Membro Fundador; only an operator saying so
 *    does. When no condition is given, the one already RECORDED on the
 *    subscription is copied — reading a stored fact, not deducing a new one —
 *    and when there is none, the payment keeps NULL and reads as "Origem não
 *    registada".
 *  - It never changes the plan, the subscription's dates, or its status. Paying
 *    is not provisioning: an account is put on Pro by
 *    `ChangeOrganizationPlan`, and recording money for it is a separate,
 *    independent fact. Coupling the two here is exactly how "conta Pro" would
 *    start to imply "pagou".
 *  - It never stores card data, and no fiscal document is produced.
 */
class RecordSubscriptionPayment
{
    public function __construct(
        protected AuditLog $audit,
        protected CurrentOrganization $currentOrganization,
        protected EffectiveSubscriptions $effective,
    ) {}

    /**
     * @param  int  $amountCents  Exactly what was received, in cents.
     * @param  OrganizationSubscription|null  $subscription  Defaults to whatever the account currently has.
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        Organization $organization,
        User $operator,
        int $amountCents,
        PaymentStatus $status,
        ?Carbon $paidAt = null,
        string $currency = 'EUR',
        ?PaymentMethod $method = null,
        ?string $providerReference = null,
        ?CommercialCondition $commercialCondition = null,
        ?string $voucherCode = null,
        ?Carbon $periodStartsAt = null,
        ?Carbon $periodEndsAt = null,
        ?OrganizationSubscription $subscription = null,
        array $metadata = [],
    ): SubscriptionPayment {
        // Defence in depth, not duplication: `RecordPaymentRequest` already
        // requires the date when the status claims the money arrived, and MySQL
        // holds a check constraint saying the same. This is the third guard,
        // for the callers that are neither — a command, a future import — and
        // it exists because a `paid` row with no `paid_at` would count towards
        // all-time revenue while falling out of every period total.
        if ($status === PaymentStatus::Paid && $paidAt === null) {
            throw new InvalidArgumentException('A payment recorded as paid must carry the date the money arrived.');
        }

        // Which period this payment bought, when the operator did not say. The
        // account's current subscription is the only defensible default; a
        // payment that lands on an account with nothing in force simply has no
        // subscription attached, which is honest and still counts as revenue.
        $subscription ??= $this->effective->current([$organization->getKey()])->get($organization->getKey());

        // A COPY of an already-recorded fact, never a deduction. If the
        // subscription's condition is itself unrecorded, this stays null.
        $condition = $commercialCondition ?? $subscription?->commercial_condition;

        $payment = SubscriptionPayment::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'organization_subscription_id' => $subscription?->getKey(),
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'status' => $status,
            'method' => $method,
            // Recorded by a person, not by a provider. A future gateway fills
            // these; a hand-entered payment leaves `provider` null and keeps
            // only whatever reference the operator was given.
            'provider' => null,
            'provider_reference' => $providerReference,
            'commercial_condition' => $condition,
            'voucher_code' => $voucherCode,
            'period_starts_at' => $periodStartsAt,
            'period_ends_at' => $periodEndsAt,
            'paid_at' => $paidAt,
            'recorded_by' => $operator->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);

        $this->currentOrganization->runFor($organization, fn () => $this->audit->record(
            'commercial.payment_recorded',
            $organization,
            $operator,
            summary: sprintf(
                'Pagamento registado: %s %s (%s).',
                number_format($amountCents / 100, 2, ',', ' '),
                $currency,
                $status->label(),
            ),
            properties: [
                'payment_ulid' => $payment->ulid,
                'amount_cents' => $amountCents,
                'currency' => $currency,
                'status' => $status->value,
                'method' => $method?->value,
                'commercial_condition' => $condition?->value,
                'voucher_code' => $voucherCode,
                'paid_at' => $paidAt?->toDateTimeString(),
                'organization_subscription_id' => $subscription?->getKey(),
            ],
        ));

        return $payment;
    }
}
