<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Commercial\RedeemVoucher;
use App\Actions\Commercial\RequestBankTransferPayment;
use App\Actions\Entitlements\RedeemCapabilityVoucher;
use App\Actions\Organizations\ActivateProTrial;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RedeemCapabilityVoucherRequest;
use App\Models\BillingPeriod;
use App\Models\CommercialCondition;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Models\VoucherBenefitType;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Commercial\ContractedTerms;
use App\Support\Commercial\FounderAvailability;
use App\Support\Commercial\Vouchers;
use App\Support\Commercial\VoucherUnavailable;
use App\Support\Entitlements\CapabilityVoucherUnavailable;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Trial\TrialEligibility;
use App\Support\Trial\TrialException;
use App\Support\Trial\TrialPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
        protected Vouchers $vouchers,
        protected RedeemVoucher $voucherRedemptions,
        protected RedeemCapabilityVoucher $capabilityVoucherRedemptions,
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
            'canRedeemCapabilityCode' => $request->user()?->can('subscribe', $organization) === true,
        ]);
    }

    public function redeemCapabilityVoucher(RedeemCapabilityVoucherRequest $request): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);
        $organization = $this->currentOrganization->get();
        Gate::authorize('subscribe', $organization);
        try {
            $grant = $this->capabilityVoucherRedemptions->handle((string) $request->validated('capability_code'), $organization, $this->user($request));
        } catch (CapabilityVoucherUnavailable $exception) {
            return back()->withErrors(['capability_code' => $exception->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Capacidades disponíveis até :date.', ['date' => $grant->expires_at->format('d/m/Y')])]);

        return to_route('settings.plan.edit');
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
     * Resgatar um voucher `free_until` — o único tipo que não passa pelo
     * checkout, porque não define uma quantia: define um TERMO.
     *
     * O PLANO-ALVO VEM DO PEDIDO, EXPLÍCITO — nunca do voucher. `plan_id` no
     * voucher é uma restrição sobre o alvo, e é contra o alvo pedido que se
     * verifica; um código nunca decide sozinho para que plano uma conta vai. O
     * Institucional continua fora: não está disponível para adesão, com ou sem
     * código, e é a mesma recusa do checkout (não há preço, não há adesão).
     *
     * O RESGATE E A MUDANÇA DE PLANO ACONTECEM NA MESMA TRANSAÇÃO, e a mudança
     * usa o mecanismo normal (`ChangeOrganizationPlan::to()`) com os termos
     * congelados do resgate: `Voucher / 0 / :moeda / none / free_until`. Nada
     * aqui toca em versões de plano nem em módulos.
     */
    public function redeemVoucher(Request $request): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $organization = $this->currentOrganization->get();
        Gate::authorize('subscribe', $organization);

        $validated = $request->validate([
            'voucher_code' => ['required', 'string', 'max:64'],
            'plan_key' => ['required', 'string', Rule::exists('plans', 'key')],
        ]);

        $target = Plan::where('key', $validated['plan_key'])->firstOrFail();

        if ($target->key === 'institutional') {
            return back()->withErrors(['voucher_code' => __(
                'O plano Institucional ainda não está disponível para adesão — fale connosco.',
            )]);
        }

        $resolution = $this->vouchers->resolve($validated['voucher_code'], $organization, $target);

        if (! $resolution->isValid() || $resolution->voucher === null) {
            return back()->withErrors(['voucher_code' => $this->vouchers->messageFor($resolution->outcome)]);
        }

        if ($resolution->voucher->benefit_type !== VoucherBenefitType::FreeUntil) {
            return back()->withErrors(['voucher_code' => __(
                'Este código define um preço e resgata-se no checkout, não aqui.',
            )]);
        }

        try {
            $subscription = DB::transaction(function () use ($resolution, $organization, $request, $target) {
                $redemption = $this->voucherRedemptions->redeemFreeUntil(
                    $resolution->voucher,
                    $organization,
                    $request->user(),
                    $target,
                );

                $subscription = $this->changePlan->to($organization, $target, new ContractedTerms(
                    condition: CommercialCondition::Voucher,
                    priceCents: 0,
                    currency: (string) config('billing.currency'),
                    billingPeriod: BillingPeriod::None,
                    termEndsAt: $redemption->result_term_ends_at,
                ));

                $redemption->forceFill(['organization_subscription_id' => $subscription->getKey()])->save();

                return $subscription;
            });
        } catch (VoucherUnavailable $exception) {
            return back()->withErrors(['voucher_code' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Voucher aplicado: :plan gratuito até :date.', [
                'plan' => $subscription->plan->name,
                'date' => $subscription->commercial_term_ends_at?->format('d/m/Y') ?? '—',
            ]),
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

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
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
