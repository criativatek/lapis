<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Commercial\RequestBankTransferPayment;
use App\Actions\Organizations\ActivateProTrial;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Commercial\FounderAvailability;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Trial\TrialEligibility;
use App\Support\Trial\TrialException;
use App\Support\Trial\TrialPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization's own plan, and the one self-service upgrade Lapispro offers
 * without any operator involved: a voluntary, time-boxed Pro trial (§Trial).
 *
 * `state` is computed once, here, from exactly the same primitives every
 * other plan-aware screen already reads — `ChangeOrganizationPlan::inForce()`
 * (the same "what is in force" `Entitlements`/the admin backoffice use) and
 * `TrialEligibility` (`usedBefore()`, the once-per-account history check, and
 * `canActivate()`, the canonical "may this organization start a trial right
 * now" rule — both re-checked again under lock by
 * `ChangeOrganizationPlan::startProTrial()`). The 'institutional' state is
 * keyed off the IN-FORCE PLAN's key, never `$organization->type`: a Personal
 * organization an operator put on the Institutional plan must read as
 * `'institutional'` here too, not fall through to `'eligible'`. The page
 * itself never derives a plan/trial state from anything else.
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
        protected RequestBankTransferPayment $transferRequests,
        protected FounderAvailability $founder,
    ) {}

    public function edit(Request $request): Response
    {
        $organization = $this->currentOrganization->get();
        $inForce = $this->changePlan->inForce($organization);
        $usedTrialBefore = $this->trialEligibility->usedBefore($organization);

        $state = match (true) {
            $inForce?->status === SubscriptionStatus::Trial => 'trial_active',
            $inForce?->plan?->key === 'institutional' => 'institutional',
            $inForce?->plan?->key === 'pro' => 'pro_active',
            $this->trialEligibility->canActivate($organization, $request->user()) => 'eligible',
            $usedTrialBefore => 'trial_expired',
            default => 'unavailable',
        };

        return Inertia::render('settings/Plan', [
            'state' => $state,
            // Decidido no servidor, não na página: se a página decidisse quando
            // mostrar o botão, teria de saber o preço, a condição Fundador e
            // quem é dono da conta — três coisas que ela não tem como verificar.
            'subscribe' => $this->subscribeOffer($organization, $request->user(), $state),
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
     * A oferta de subscrição por transferência, ou null quando não há nenhuma.
     *
     * NULL EM MAIS CASOS DO QUE PARECE, e cada um por uma razão diferente: quem
     * já tem Pro não tem o que comprar; o Institucional é sob consulta; quem não
     * é dono da conta não compra em nome dela; e sem IBAN configurado um botão
     * levaria a um ecrã com o campo em branco.
     *
     * @return array{price: string, standardPrice: string, isFounder: bool, seatsRemaining: int, pendingUlid: ?string, pendingReference: ?string}|null
     */
    protected function subscribeOffer(Organization $organization, ?User $user, string $state): ?array
    {
        if (! in_array($state, ['eligible', 'trial_active', 'trial_expired', 'unavailable'], true)) {
            return null;
        }

        if ($user === null || ! $user->owns($organization)) {
            return null;
        }

        if (! config('billing.bank_transfer.enabled') || blank(config('billing.bank_transfer.iban'))) {
            return null;
        }

        $plan = Plan::where('key', 'pro')->first();
        $standard = config('billing.prices.pro');

        if ($plan === null || $standard === null) {
            return null;
        }

        $pendente = $this->transferRequests->validPendingFor($organization);
        $cents = $this->transferRequests->priceFor($plan);

        return [
            'price' => $this->money($cents),
            'standardPrice' => $this->money((int) $standard),
            'isFounder' => $this->founder->isOpen(),
            'seatsRemaining' => $this->founder->remaining(),
            'pendingUlid' => $pendente?->ulid,
            'pendingReference' => $pendente?->provider_reference,
        ];
    }

    protected function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ').' €';
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
