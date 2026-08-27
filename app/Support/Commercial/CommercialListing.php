<?php

namespace App\Support\Commercial;

use App\Models\CommercialCondition;
use App\Models\OrganizationSubscription;
use App\Models\PaymentStatus;
use App\Models\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The commercial listing: one row per ACCOUNT, showing the subscription that
 * currently speaks for it.
 *
 * Not one row per subscription. An account that has changed plan four times has
 * four rows in `organization_subscriptions`, and showing all four would bury the
 * one fact an operator opened this page for — what this account is on now. The
 * other three are history, and history belongs on the detail page, where
 * `EffectiveSubscriptions::historyOf()` shows it in full.
 *
 * FILTERING HAPPENS IN SQL, on those current rows, so pagination is real: the
 * only work done in PHP is resolving WHICH subscription is current, which is one
 * narrow query, not one per account.
 *
 * The condition filter reproduces `SubscriptionCondition::keyOf()`'s precedence
 * exactly — status Trial first, then the stored column, then NULL as unknown.
 * The two are tested against each other
 * (`CommercialListingTest::filtering_by_condition_agrees_with_the_label_shown`)
 * precisely because they are two expressions of one rule in two languages.
 */
final class CommercialListing
{
    public function __construct(protected EffectiveSubscriptions $effective) {}

    /**
     * @return Builder<OrganizationSubscription>
     */
    public function query(CommercialFilters $filters): Builder
    {
        // Resolving "current" needs `isInForce()` per account, which is PHP,
        // not SQL — so this one step reads the subscription rows and narrows to
        // the ids that speak for each account. Everything after it is a real,
        // paginated SQL query over that id set. At the scale this product runs
        // at (accounts in the hundreds, a handful of subscriptions each) that is
        // one cheap query; if it ever stops being cheap, this is the line to
        // replace with a window function, and nothing else has to move.
        $currentIds = $this->effective->current()
            ->map(fn (OrganizationSubscription $subscription): int => $subscription->getKey())
            ->values()
            ->all();

        return OrganizationSubscription::query()
            ->withoutGlobalScope('organization')
            ->whereKey($currentIds)
            ->with(['plan', 'organization.owner'])
            ->when($filters->planKey !== null, fn (Builder $query) => $query
                ->whereHas('plan', fn (Builder $plan) => $plan->where('key', $filters->planKey)))
            ->when($filters->status !== null, fn (Builder $query) => $query
                ->where('status', $filters->status))
            ->when($filters->condition !== null, fn (Builder $query) => $this->filterByCondition($query, $filters->condition))
            ->when($filters->from !== null, fn (Builder $query) => $query->where('starts_at', '>=', $filters->from))
            ->when($filters->to !== null, fn (Builder $query) => $query->where('starts_at', '<=', $filters->to))
            ->when($filters->payment !== null, fn (Builder $query) => $this->filterByPayment($query, $filters->payment))
            ->when($filters->search !== '', fn (Builder $query) => $query
                ->whereHas('organization', fn (Builder $organization) => $organization
                    ->where('name', 'like', "%{$filters->search}%")
                    ->orWhereHas('owner', fn (Builder $owner) => $owner
                        ->where('name', 'like', "%{$filters->search}%")
                        ->orWhere('email', 'like', "%{$filters->search}%"))))
            ->latest('starts_at')
            ->latest('id');
    }

    /**
     * Money per account, for the rows on screen. One aggregate query for the
     * whole page rather than a subquery per row — and account-level, not
     * subscription-level: an operator asking "quanto pagou esta conta?" means
     * the account, including payments made under a subscription that has since
     * been superseded.
     *
     * Deliberately the query builder, not Eloquent: the result is three
     * aggregates per account, not a payment, and modelling it as a
     * `SubscriptionPayment` with a `total_cents` attribute bolted on would be a
     * row that looks like a payment and is not one.
     *
     * The raw rows are converted to a typed shape here, at the boundary, rather
     * than carried around as `stdClass` for every caller to cast for itself —
     * a driver that returns `SUM()` as a string is exactly how a total ends up
     * concatenated instead of added.
     *
     * @param  array<int, int>  $organizationIds
     * @return array<int, array{total_cents: int, payment_count: int, last_paid_at: string|null}>
     */
    public function paymentTotals(array $organizationIds): array
    {
        return DB::table('subscription_payments')
            ->whereIn('organization_id', $organizationIds)
            // Only revenue-bearing rows. A refunded or voided payment must not
            // show as money this account has paid.
            ->where('status', PaymentStatus::Paid->value)
            ->groupBy('organization_id')
            ->select([
                'organization_id',
                DB::raw('SUM(amount_cents) as total_cents'),
                DB::raw('COUNT(*) as payment_count'),
                DB::raw('MAX(paid_at) as last_paid_at'),
            ])
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->organization_id => [
                    'total_cents' => (int) $row->total_cents,
                    'payment_count' => (int) $row->payment_count,
                    'last_paid_at' => $row->last_paid_at === null ? null : (string) $row->last_paid_at,
                ],
            ])
            ->all();
    }

    /**
     * @param  Builder<OrganizationSubscription>  $query
     * @return Builder<OrganizationSubscription>
     */
    protected function filterByCondition(Builder $query, string $condition): Builder
    {
        if ($condition === SubscriptionCondition::TRIAL) {
            return $query->where('status', SubscriptionStatus::Trial);
        }

        // Every branch below excludes trials, because a trial's effective
        // condition is `trial` no matter what the column happens to hold.
        $query->where('status', '!=', SubscriptionStatus::Trial);

        if ($condition === SubscriptionCondition::UNKNOWN) {
            return $query->whereNull('commercial_condition');
        }

        return $query->where('commercial_condition', CommercialCondition::tryFrom($condition));
    }

    /**
     * @param  Builder<OrganizationSubscription>  $query
     * @return Builder<OrganizationSubscription>
     */
    protected function filterByPayment(Builder $query, string $payment): Builder
    {
        // Account-level, matching what the column shows: "pago" means this
        // ACCOUNT has revenue-bearing payments, not that this particular
        // subscription period does.
        $exists = function (QueryBuilder $inner): void {
            $inner->select(DB::raw(1))
                ->from('subscription_payments')
                ->whereColumn('subscription_payments.organization_id', 'organization_subscriptions.organization_id')
                ->where('subscription_payments.status', PaymentStatus::Paid->value);
        };

        return $payment === 'paid'
            ? $query->whereExists($exists)
            : $query->whereNotExists($exists);
    }
}
