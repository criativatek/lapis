<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\OrganizationType;
use App\Models\Plan;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Trial\TrialException;
use App\Support\Trial\TrialPolicy;

/**
 * Lets a Personal organization's owner start their own voluntary, 30-day
 * (§TrialPolicy) Pro trial — self-service, no operator involved.
 *
 * Never accepts a plan, a day count or any date from the caller: everything
 * dated is computed here from `now()` and `TrialPolicy`, and the plans are
 * resolved by their stable `key`, the same canonical lookup
 * `CreatePersonalOrganization`/`AdminAccountController` already use. The
 * atomic write itself — lock, re-verify eligibility, supersede, insert both
 * rows — is `ChangeOrganizationPlan::startProTrial()`'s job, not this one's;
 * this action only resolves inputs and checks the two guards that need no
 * lock because they can never change out from under a concurrent request:
 * the organization's type (immutable after creation) and who its owner is
 * (this call does not race a transfer of ownership any more than any other
 * single request does).
 */
class ActivateProTrial
{
    public function __construct(
        protected ChangeOrganizationPlan $changePlan,
        protected TrialPolicy $policy,
    ) {}

    public function activate(User $user, Organization $organization): OrganizationSubscription
    {
        if ($organization->type !== OrganizationType::Personal) {
            throw TrialException::notEligible();
        }

        if (! $user->owns($organization)) {
            throw TrialException::notOwner();
        }

        $trialPlan = Plan::where('key', 'pro')->firstOrFail();
        $fallbackPlan = Plan::where('key', 'base')->firstOrFail();

        return $this->changePlan->startProTrial($organization, $trialPlan, $fallbackPlan, $this->policy->proDays());
    }
}
