<?php

namespace App\Support\Trial;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\OrganizationType;
use App\Models\SubscriptionStatus;

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
}
