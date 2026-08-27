<?php

namespace App\Support\Commercial;

use App\Models\OrganizationSubscription;
use App\Models\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which subscription speaks for each organization, across every tenant.
 *
 * `App\Support\Entitlements\Entitlements` answers this one organization at a
 * time, because that is what an access check needs. A commercial dashboard
 * needs the same answer for hundreds of organizations at once, and asking the
 * resolver in a loop would be a query per account.
 *
 * SO THE ORDERING IS COPIED, NOT REINVENTED: newest `starts_at`, then newest
 * `id` as tiebreaker — byte-for-byte what `Entitlements::resolve()` uses — and
 * the "in force" predicate is not reimplemented in SQL at all. SQL only
 * NARROWS to rows that could possibly qualify; `OrganizationSubscription::isInForce()`
 * itself makes the final call, so there is exactly one definition of "in force"
 * in the codebase and this class cannot drift away from the resolver that
 * governs actual access.
 *
 * Deliberately outside tenancy (`withoutGlobalScope('organization')`), like
 * every other read in the backoffice.
 */
final class EffectiveSubscriptions
{
    /**
     * The subscription in force per organization, keyed by organization_id.
     * Organizations with nothing in force are simply absent — that is a real
     * commercial state ("sem subscrição em vigor"), not a zero.
     *
     * @param  iterable<int>|null  $organizationIds  null means every organization.
     * @return Collection<int, OrganizationSubscription>
     */
    public function inForce(?iterable $organizationIds = null): Collection
    {
        $now = Carbon::now();

        $candidates = $this->query($organizationIds)
            // The narrowing half of isInForce(), as SQL — so a database with
            // years of superseded history does not travel into PHP. Statuses
            // that grant access, already started, not yet ended.
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trial])
            ->where('starts_at', '<=', $now)
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->get();

        return $candidates
            ->groupBy('organization_id')
            // ->first() on a collection already ordered newest-first is the
            // resolver's own choice, and isInForce() has the last word.
            ->map(fn (Collection $subscriptions) => $subscriptions
                ->first(fn (OrganizationSubscription $subscription) => $subscription->isInForce()))
            ->filter();
    }

    /**
     * What to SHOW for each organization: the one in force, or — when nothing
     * is — the most recent one anyway, so an expired or suspended account still
     * reads as an account with a history rather than a blank row.
     *
     * The same fallback `AdminAccountController::currentSubscriptions()` has
     * always used, lifted here so the commercial listing and the accounts
     * listing can never disagree about what an account is on.
     *
     * @param  iterable<int>|null  $organizationIds
     * @return Collection<int, OrganizationSubscription>
     */
    public function current(?iterable $organizationIds = null): Collection
    {
        return $this->query($organizationIds)
            ->get()
            ->groupBy('organization_id')
            ->map(fn (Collection $subscriptions) => $subscriptions
                ->first(fn (OrganizationSubscription $subscription) => $subscription->isInForce())
                ?? $subscriptions->first())
            ->filter();
    }

    /**
     * Every subscription of one organization, newest first — the history the
     * detail page shows.
     *
     * @return Collection<int, OrganizationSubscription>
     */
    public function historyOf(int $organizationId): Collection
    {
        return $this->query([$organizationId])->get();
    }

    /**
     * @param  iterable<int>|null  $organizationIds
     * @return Builder<OrganizationSubscription>
     */
    protected function query(?iterable $organizationIds): Builder
    {
        return OrganizationSubscription::query()
            ->withoutGlobalScope('organization')
            ->when($organizationIds !== null, fn (Builder $query) => $query->whereIn('organization_id', $organizationIds))
            ->with('plan')
            ->latest('starts_at')
            ->latest('id');
    }
}
