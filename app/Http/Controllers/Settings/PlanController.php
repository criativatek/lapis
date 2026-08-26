<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Organizations\ActivateProTrial;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Controllers\Controller;
use App\Models\OrganizationSubscription;
use App\Models\OrganizationType;
use App\Models\SubscriptionStatus;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Trial\TrialEligibility;
use App\Support\Trial\TrialException;
use App\Support\Trial\TrialPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization's own plan, and the one self-service upgrade LÁPIS offers
 * without any operator involved: a voluntary, time-boxed Pro trial (§Trial).
 *
 * `state` is computed once, here, from exactly the same primitives every
 * other plan-aware screen already reads — `ChangeOrganizationPlan::inForce()`
 * (the same "what is in force" `Entitlements`/the admin backoffice use) and
 * `TrialEligibility` (the once-per-account history check `ChangeOrganizationPlan::startProTrial()`
 * re-checks under lock). The page itself never derives a plan/trial state
 * from anything else.
 */
class PlanController extends Controller
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected CurrentOrganization $currentOrganization,
        protected ChangeOrganizationPlan $changePlan,
        protected TrialEligibility $trialEligibility,
        protected TrialPolicy $trialPolicy,
        protected ActivateProTrial $activateProTrial,
    ) {}

    public function edit(): Response
    {
        $organization = $this->currentOrganization->get();
        $inForce = $this->changePlan->inForce($organization);
        $usedTrialBefore = $this->trialEligibility->usedBefore($organization);

        $state = match (true) {
            $organization->type === OrganizationType::Institutional => 'institutional',
            $inForce?->status === SubscriptionStatus::Trial => 'trial_active',
            $inForce?->plan?->key === 'pro' => 'pro_active',
            $usedTrialBefore => 'trial_expired',
            default => 'eligible',
        };

        return Inertia::render('settings/Plan', [
            'state' => $state,
            'proDays' => $this->trialPolicy->proDays(),
            'currentPlanName' => $inForce?->plan?->name,
            'trial' => $state === 'trial_active' ? $this->trialPayload($inForce) : null,
            'usedTrialBefore' => $state === 'pro_active' ? $usedTrialBefore : null,
        ]);
    }

    /**
     * No validated input at all: every request field is ignored, on purpose —
     * the request body carries nothing this action is allowed to read. Every
     * date, the plan chosen, and which organization is affected are resolved
     * server-side, from `now()`, `TrialPolicy` and `CurrentOrganization`.
     */
    public function activateTrial(Request $request): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        try {
            $trial = $this->activateProTrial->activate($request->user(), $this->currentOrganization->get());
        } catch (TrialException $exception) {
            return back()->withErrors(['trial' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('O período experimental Pro foi ativado até :date.', ['date' => $trial->ends_at->format('d/m/Y')]),
        ]);

        return to_route('settings.plan.edit');
    }

    /**
     * @return array{starts_at: string, ends_at: string, days_remaining: int}
     */
    protected function trialPayload(OrganizationSubscription $trial): array
    {
        return [
            'starts_at' => $trial->starts_at->toIso8601String(),
            'ends_at' => $trial->ends_at->toIso8601String(),
            // Same computation as ClosureStatusPresenter::present(): future
            // only, never negative.
            'days_remaining' => $trial->ends_at->isFuture() ? (int) now()->diffInDays($trial->ends_at) : 0,
        ];
    }
}
