<?php

namespace App\Support\Commercial;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\PaymentStatus;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The numbers on the commercial dashboard.
 *
 * ONE RULE GOVERNS EVERY EURO IN THIS CLASS: revenue is the sum of
 * `subscription_payments.amount_cents` where the status counts as revenue
 * (`PaymentStatus::countsAsRevenue()` — today, `paid` alone), grouped by
 * `paid_at`, the day the money arrived. Nothing else contributes. Not the plan
 * an account is on, not the number of Pro accounts, not the list price, not a
 * pending payment, not a failed one, and not a refunded one.
 *
 * So an empty `subscription_payments` produces 0 EUR across the board, and that
 * is the correct answer for this database today — every Pro account in it was
 * put there by an operator or a trial, and no money was ever recorded. Showing
 * an estimate instead would be inventing revenue that nobody received.
 *
 * COUNTS AND MONEY ARE KEPT APART on purpose. `accounts()` says how many
 * accounts are on Pro; `revenue()` says how much was received. Multiplying one
 * by a price to obtain the other is exactly the mistake this whole slice exists
 * to make impossible, so no method here does it and none ever should.
 */
final class CommercialMetrics
{
    public function __construct(protected EffectiveSubscriptions $effective) {}

    /**
     * Everything the dashboard shows, in one pass.
     *
     * @return array{accounts: array<string, mixed>, revenue: array<string, mixed>}
     */
    public function snapshot(?Carbon $from = null, ?Carbon $to = null): array
    {
        return [
            'accounts' => $this->accounts(),
            'revenue' => $this->revenue($from, $to),
        ];
    }

    /**
     * How many accounts, on what, and on what terms.
     *
     * `by_plan` counts only what is IN FORCE — an account whose subscription
     * expired is not "on Base", it is on nothing, and lands in
     * `without_subscription` instead. Reading a lapsed account as Base would
     * quietly inflate the free tier with accounts that have no product at all.
     *
     * @return array<string, mixed>
     */
    public function accounts(): array
    {
        $total = Organization::query()->count();
        $inForce = $this->effective->inForce();

        $byPlan = $inForce
            ->groupBy(fn (OrganizationSubscription $subscription): string => $subscription->plan->key)
            ->map(fn (Collection $group): int => $group->count());

        // Pro broken down by how each account got there — the distinction the
        // whole slice exists for. A Pro whose condition nobody recorded lands
        // in `unknown`, never in `standard`.
        $proByCondition = $inForce
            ->filter(fn (OrganizationSubscription $subscription): bool => $subscription->plan->key === 'pro')
            ->groupBy(fn (OrganizationSubscription $subscription): string => SubscriptionCondition::keyOf($subscription))
            ->map(fn (Collection $group): int => $group->count());

        return [
            'total' => $total,
            'by_plan' => [
                'base' => $byPlan->get('base', 0),
                'pro' => $byPlan->get('pro', 0),
                'institutional' => $byPlan->get('institutional', 0),
            ],
            // Every account minus those with something in force. Derived by
            // subtraction rather than counted separately, so the buckets always
            // add up to `total` even if a plan key appears that this method
            // does not name.
            'without_subscription' => max(0, $total - $inForce->count()),
            'trials_active' => $inForce
                ->filter(fn (OrganizationSubscription $subscription): bool => $subscription->status === SubscriptionStatus::Trial)
                ->count(),
            'pro_by_condition' => $proByCondition->all(),
            // Accounts with at least one payment that counts as revenue. Not
            // "accounts on Pro": the two are different questions, and today the
            // honest answer to this one is zero.
            'paying' => SubscriptionPayment::query()
                ->withoutGlobalScope('organization')
                ->revenue()
                ->distinct()
                ->count('organization_id'),
        ];
    }

    /**
     * Money actually received.
     *
     * @return array{total_cents: int, period_cents: int, current_year_cents: int, paid_count: int, average_cents: int|null, currency: string, from: string|null, to: string|null}
     */
    public function revenue(?Carbon $from = null, ?Carbon $to = null): array
    {
        $totalCents = (int) $this->revenueQuery()->sum('amount_cents');
        $paidCount = $this->revenueQuery()->count();

        $yearStart = Carbon::now()->startOfYear();
        $yearEnd = Carbon::now()->endOfYear();

        return [
            'total_cents' => $totalCents,
            'period_cents' => (int) $this->revenueQuery($from, $to)->sum('amount_cents'),
            'current_year_cents' => (int) $this->revenueQuery($yearStart, $yearEnd)->sum('amount_cents'),
            'paid_count' => $paidCount,
            // Null, not zero, when there is nothing to average. A 0 EUR average
            // reads as "payments averaging nothing"; absence reads as "no
            // payments", which is what is true.
            'average_cents' => $paidCount > 0 ? (int) round($totalCents / $paidCount) : null,
            // Single-currency by construction — see `currency()`.
            'currency' => $this->currency(),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
        ];
    }

    /**
     * The currency the totals are in.
     *
     * Every figure above is a plain `SUM(amount_cents)`, which is only
     * meaningful if every row shares one currency. Today they do: the column
     * defaults to EUR and nothing in the application offers another. Rather
     * than pretend to multi-currency arithmetic it does not do, this reports
     * the single distinct currency actually present, and falls back to EUR when
     * there are no payments at all. If a second currency ever appears, this
     * returns `'MIXED'` — a visible wrong-looking label beats a silently
     * meaningless sum.
     */
    public function currency(): string
    {
        $currencies = SubscriptionPayment::query()
            ->withoutGlobalScope('organization')
            ->revenue()
            ->distinct()
            ->pluck('currency');

        return match ($currencies->count()) {
            0 => 'EUR',
            1 => (string) $currencies->first(),
            default => 'MIXED',
        };
    }

    /**
     * The one query every total is built from.
     *
     * `paid_at`, never `created_at`: a payment received in December and typed
     * in in January belongs to December. The database guarantees `paid_at` is
     * present whenever the status is `paid` (see the migration's check
     * constraint), so no row can slip out of a period total while still
     * counting towards the all-time one.
     *
     * @return Builder<SubscriptionPayment>
     */
    protected function revenueQuery(?Carbon $from = null, ?Carbon $to = null): Builder
    {
        return SubscriptionPayment::query()
            ->withoutGlobalScope('organization')
            ->where('status', PaymentStatus::Paid)
            ->when($from !== null, fn (Builder $query) => $query->where('paid_at', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->where('paid_at', '<=', $to));
    }
}
