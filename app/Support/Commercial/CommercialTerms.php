<?php

namespace App\Support\Commercial;

use App\Models\BillingPeriod;
use App\Models\CommercialCondition;
use App\Models\Organization;
use App\Models\PaymentStatus;
use App\Models\PlanVersion;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Carbon;

/**
 * ON WHAT TERMS IS THIS ADHESION BEING MADE — asked once, answered here.
 *
 * The 0.88.0 schema gave `organization_subscriptions` four columns of
 * commercial proof and nothing to fill them: every writer left all four NULL,
 * so the landing's «Gratuito no ano letivo 2026/27» and «Subscrição anual»
 * were promises with no counterpart in the database. This class is the
 * counterpart. Every flow that creates a subscription asks it, and no flow
 * decides for itself what was agreed.
 *
 * THREE ANSWERS, AND THE FOURTH IS «NOTHING»:
 *
 *  - `forNewAdhesion()` — a plan being taken up right now with no money
 *    involved. Today that is the free Base under the 2026/27 promotion, and
 *    ONLY the free Base: Pro is not sold here (it is sold by a payment, below)
 *    and Institucional is «sob consulta», so both get NULL rather than an
 *    invented figure.
 *  - `forTrial()` — a voluntary Pro trial. Zero, in a currency, with no billing
 *    cycle. Zero is a RECORDED fact («somebody agreed this costs nothing») and
 *    is not the NULL of «nobody knows», which is exactly the distinction the
 *    trial needs so it can never read as a paid contract.
 *  - `fromPaidEvidence()` — a plan being provisioned after money actually
 *    arrived. The price comes off the PAYMENT, never off `config/billing.php`:
 *    a price list changed next year must not rewrite what somebody contracted
 *    this year (§11 of the brief).
 *  - NULL, whenever none of the three applies. An operator's grant, an
 *    institutional arrangement, a plan moved by hand with no payment behind it
 *    — nothing is agreed that this class can see, so nothing is written, and
 *    the backoffice keeps saying «Origem não registada» rather than a
 *    plausible-looking guess. That is the same discipline the
 *    2026_09_11_000300 migration applied when it backfilled nothing.
 *
 * NOT AN ENTITLEMENT SOURCE. Nothing here is ever read by
 * `App\Support\Entitlements\Entitlements` or by `isInForce()`, for the reason
 * ADR-0008 §8 gives: the moment a commercial term reached the resolver, «the
 * promotional price ended» would silently become «the account was cut off».
 */
class CommercialTerms
{
    /**
     * The terms a plan is being taken up on right now, with no payment behind
     * it. NULL when nothing is being agreed that can be written down.
     *
     * KEYED ON THE PLAN, NOT ON THE CALLER. Registration, an operator
     * provisioning an account, a downgrade back to Base and the dormant
     * fallback a trial leaves behind are all the same commercial fact — a Base
     * adhesion made today — and they must not be able to disagree about it.
     */
    public function forNewAdhesion(PlanVersion $version): ?ContractedTerms
    {
        if ($version->plan->key !== 'base') {
            return null;
        }

        return $this->freeBasePromotion();
    }

    /**
     * The free-Base promotion, if it is open right now.
     *
     * Open means «on or before the end date», inclusive to the day: somebody
     * who creates an account on 31/08/2027 is still creating it «no ano letivo
     * 2026/27», which is what the page promises. The same date closes the
     * window and ends the term, because the sentence only makes one promise.
     */
    public function freeBasePromotion(): ?ContractedTerms
    {
        if (! config('billing.promotions.free_base.enabled')) {
            return null;
        }

        $endsAt = $this->freeBaseEndsAt();

        if (Carbon::now()->greaterThan($endsAt)) {
            // The promotion is over. A Base created afterwards is still free
            // today, but NOTHING has been decided about what it costs, and
            // recording `promotional` would date-stamp it into a condition it
            // was never offered. NULL is the honest answer until somebody
            // decides what replaces this.
            return null;
        }

        return new ContractedTerms(
            condition: CommercialCondition::Promotional,
            priceCents: 0,
            currency: $this->currency(),
            // `None`, not `Annual`: nothing recurs and nothing is billed. The
            // enum's own docblock reserves `Annual` for «the one paid cycle
            // that exists», and this cycle is not paid.
            billingPeriod: BillingPeriod::None,
            termEndsAt: $endsAt,
        );
    }

    /** Inclusive of the whole final day. */
    public function freeBaseEndsAt(): Carbon
    {
        return Carbon::parse((string) config('billing.promotions.free_base.ends_at'))->endOfDay();
    }

    /**
     * A voluntary Pro trial.
     *
     * `commercial_condition` STAYS NULL, and that is not an omission: the enum
     * has no `Trial` case on purpose, because `status = SubscriptionStatus::Trial`
     * already records it immutably and `SubscriptionCondition` derives the
     * label from there. Writing a condition here would give the row two places
     * to disagree.
     *
     * `commercial_term_ends_at` also stays NULL, deliberately. For a trial the
     * commercial term and the access window are the SAME instant, and `ends_at`
     * already carries it; writing it a second time into a column whose whole
     * documented purpose is «this is NOT ends_at» would teach the next reader
     * that the two are synonyms.
     */
    public function forTrial(): ContractedTerms
    {
        return new ContractedTerms(
            priceCents: 0,
            currency: $this->currency(),
            billingPeriod: BillingPeriod::None,
        );
    }

    /**
     * The terms proved by money that actually arrived.
     *
     * THE PRICE IS THE ONE THAT WAS PAID. Not `config/billing.php`, which says
     * what is asked of a customer TODAY and is free to change tomorrow; not the
     * plan; not the founder price list. If a teacher transferred 29,90 € under
     * a launch condition, this subscription records 29,90 € for ever, and
     * raising the list price next August changes nothing about it.
     *
     * THE CONDITION IS COPIED, NEVER DEDUCED — the rule
     * `RecordSubscriptionPayment` already states: paying 29,90 € does not make
     * anybody a Membro Fundador; the payment carrying `commercial_condition =
     * founder` does, because a person put it there.
     *
     * Returns NULL when there is no revenue-bearing payment still covering the
     * period being provisioned, which is the ordinary case for an operator
     * moving somebody's plan by hand: nothing was paid, so nothing is claimed.
     */
    public function fromPaidEvidence(Organization $organization, PlanVersion $version): ?ContractedTerms
    {
        if ($version->plan->key === 'base') {
            // A Base adhesion is never «what the payment bought»: the Base is
            // free, and a payment on the account bought the Pro this
            // organization is coming DOWN from. Attaching it here would record
            // 44,90 € against a free plan.
            return null;
        }

        $payment = $this->latestCoveringPayment($organization);

        if ($payment === null) {
            return null;
        }

        return new ContractedTerms(
            condition: $payment->commercial_condition,
            priceCents: $payment->amount_cents,
            currency: $payment->currency,
            // The one paid cycle this product has. `BillingPeriod` deliberately
            // carries no `monthly`, and the payment itself was written with a
            // one-year period — this states the same fact on the subscription,
            // which is where «subscrição anual» was missing entirely.
            billingPeriod: BillingPeriod::Annual,
            // Until when the CONDITION holds — the end of the period the money
            // bought. Access is `ends_at` and stays open; this is the date an
            // operator needs in order to know when to talk to somebody.
            termEndsAt: $payment->period_ends_at,
        );
    }

    /**
     * The most recent payment that counts as revenue and whose period has not
     * already run out.
     *
     * `PaymentStatus::Paid` is asked for directly rather than filtered in PHP,
     * so a refunded or voided payment can never become proof of a contract —
     * the same single revenue rule `countsAsRevenue()` states. A payment with
     * no period recorded still counts: it is money that arrived, and its term
     * is simply unknown, which is NULL rather than invented.
     */
    protected function latestCoveringPayment(Organization $organization): ?SubscriptionPayment
    {
        return SubscriptionPayment::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->where('status', PaymentStatus::Paid)
            ->where(fn ($query) => $query
                ->whereNull('period_ends_at')
                ->orWhere('period_ends_at', '>', Carbon::now()))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->first();
    }

    protected function currency(): string
    {
        return (string) config('billing.currency');
    }
}
