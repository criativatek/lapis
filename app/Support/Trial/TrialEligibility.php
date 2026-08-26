<?php

namespace App\Support\Trial;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\OrganizationType;
use App\Models\SubscriptionStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The once-per-account trial history check (§4 of the trial brief).
 *
 * An organization has used its trial if an `OrganizationSubscription` with
 * `status = Trial` EVER existed — at any point in history, regardless of its
 * current `ends_at` or whether it was later superseded — for the organization
 * itself, or defensively, for any OTHER Personal organization owned by the
 * same person. Today an account only ever has one Personal organization, but
 * nothing in the schema enforces that, so a hypothetical second one must not
 * be able to bypass the once-per-account rule.
 *
 * Deliberately a single, small, stateless reader rather than inlined twice:
 * `ChangeOrganizationPlan::startProTrial()` re-runs it against the LOCKED
 * organization row as the final, authoritative check, and
 * `PlanController::edit()` runs it again (unlocked — it is a read, not a
 * write) purely to describe the current state to the settings page. Both
 * must always agree on the exact same definition of "already used".
 */
final class TrialEligibility
{
    public function usedBefore(Organization $organization): bool
    {
        $personalOrganizationIds = Organization::query()
            ->withoutGlobalScope('organization')
            ->where('type', OrganizationType::Personal)
            ->where('owner_id', $organization->owner_id)
            ->pluck('id');

        return OrganizationSubscription::query()
            ->withoutGlobalScope('organization')
            ->whereIn('organization_id', $personalOrganizationIds)
            ->where('status', SubscriptionStatus::Trial)
            ->exists();
    }

    /**
     * The canonical rule for WHICH plan a trial may supersede: the sole
     * authorized cycle is Base (in force) → Trial Pro → Base, so this
     * returns true only when the organization's own currently-in-force
     * subscription is on the Base plan, and nothing already scheduled would
     * be silently destroyed by a trial's `supersede()` call. An organization
     * on Pro, on Institutional, suspended, or with nothing in force at all
     * must never be read as eligible here, no matter what `usedBefore()`
     * says — the two checks are independent and `canActivate()` requires
     * both.
     *
     * Deliberately runs its OWN, independent `isInForce()` scan instead of
     * asking `ChangeOrganizationPlan::inForce()` for the answer:
     * `ChangeOrganizationPlan` already depends on `TrialEligibility` (it
     * calls `usedBefore()` under lock), so the reverse dependency would be
     * circular. `App\Support\Limits\Limits::planInForce()` documents and
     * accepts this exact same small duplication, for the exact same reason
     * — this is that trade-off again, not a new one.
     */
    public function hasEligibleBasePlan(Organization $organization): bool
    {
        $subscriptions = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->with('plan')
            ->get();

        $inForce = $subscriptions->filter(fn (OrganizationSubscription $subscription): bool => $subscription->isInForce());

        // Anything other than exactly one in force fails safe: none at all
        // (including a suspended-only organization — Suspended never grants
        // access, so it is never "in force") is not Base, and more than one
        // should never legitimately happen, but must never be read as
        // eligible either.
        if ($inForce->count() !== 1) {
            return false;
        }

        // The canonical stable key, never a translated/display name.
        if ($inForce->first()->plan->key !== 'base') {
            return false;
        }

        // Defensive, not reachable by any code path today: nothing currently
        // schedules a future plan change — `ChangeOrganizationPlan::to()`
        // always writes `starts_at = now()` — so this shape cannot exist in
        // production. Kept anyway, the same way `ChangeOrganizationPlan::supersede()`
        // already defends against shapes its own callers cannot currently
        // produce: a subscription scheduled to start later that already
        // grants access and is not Base is an already-committed future
        // Pro/Institutional change that a trial's `supersede()` call would
        // silently void.
        $now = Carbon::now();

        $hasIncompatibleFutureChange = $subscriptions->contains(
            fn (OrganizationSubscription $subscription): bool => $subscription->starts_at->greaterThan($now)
                && $subscription->status->grantsAccess()
                && $subscription->plan->key !== 'base',
        );

        return ! $hasIncompatibleFutureChange;
    }

    /**
     * The single convenience boolean both the settings page and the
     * write-side re-check use: is this organization, right now, allowed to
     * START a trial? Personal type, owned by the given user, never used a
     * trial before, and currently on an eligible Base plan.
     *
     * `PlanController::edit()` calls this once, unlocked, purely to describe
     * the current state to the page. `ChangeOrganizationPlan::startProTrial()`
     * calls it again against the LOCKED organization row, at the write gate
     * — so every condition here is genuinely re-evaluated at write time,
     * never assumed from a value read before the lock was acquired.
     */
    public function canActivate(Organization $organization, User $user): bool
    {
        return $organization->type === OrganizationType::Personal
            && $user->owns($organization)
            && ! $this->usedBefore($organization)
            && $this->hasEligibleBasePlan($organization);
    }
}
