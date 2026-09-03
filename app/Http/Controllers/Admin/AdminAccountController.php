<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SetTestAccount;
use App\Actions\Commercial\RedeemVoucher;
use App\Actions\Organizations\AddOrganizationMember;
use App\Actions\Organizations\CreateInstitutionalOrganization;
use App\Actions\Organizations\CreatePersonalOrganization;
use App\Actions\Users\DeleteUserAccount;
use App\Http\Controllers\Controller;
use App\Models\AssessmentProfile;
use App\Models\AuditEvent;
use App\Models\Enrollment;
use App\Models\EvidenceRecord;
use App\Models\Instrument;
use App\Models\Intervention;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\OrganizationType;
use App\Models\Plan;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Commercial\CommercialTerms;
use App\Support\Commercial\FounderSeats;
use App\Support\Entitlements\Entitlements;
use App\Support\Retention\ClosureStatusPresenter;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform backoffice — every organization across the SaaS. Runs outside any
 * tenant (no `organization` middleware): Organization/User are not tenant-scoped,
 * and the tenant-scoped subscription rows are read/written with `withoutGlobalScope`.
 * Admin actions are audited under the target organization's trail.
 */
class AdminAccountController extends Controller
{
    public function __construct(
        protected Entitlements $entitlements,
        protected AuditLog $audit,
        protected CurrentOrganization $currentOrganization,
        // Every subscription transition goes through here. The controller
        // validates, authorises and reports; the lifecycle rule — at most one
        // subscription in force at a time — lives in one place and is not
        // restated at each call site.
        protected ChangeOrganizationPlan $planChange,
        protected CreateInstitutionalOrganization $createInstitutional,
        protected AddOrganizationMember $addOrganizationMember,
        protected ClosureStatusPresenter $closureStatus,
        // What was agreed, read off the evidence rather than typed into a form.
        protected CommercialTerms $terms,
        protected FounderSeats $founderSeats,
        protected RedeemVoucher $voucherRedemptions,
        // Says whether an account is real or exists to try the product out.
        // Not a commercial condition — see the action.
        protected SetTestAccount $setTestAccount,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $organizations = Organization::query()
            ->with('owner')
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('owner', fn ($owner) => $owner->where('email', 'like', "%{$search}%"))))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $current = $this->currentSubscriptions(collect($organizations->items())->pluck('id'));

        $organizations->through(fn (Organization $organization) => [
            'ulid' => $organization->ulid,
            'name' => $organization->name,
            'type' => $organization->type->value,
            'owner' => $organization->owner?->name,
            'owner_email' => $organization->owner?->email,
            'verified' => $organization->owner?->email_verified_at !== null,
            // Two different states travel side by side on purpose: `status` is
            // the subscription's and `active` is the person's. An account can be
            // paid up and shut out, or free and perfectly able to sign in.
            'active' => $organization->owner === null || $organization->owner->isActive(),
            'plan' => $current->get($organization->id)?->plan?->name,
            'status' => $current->get($organization->id)?->status?->value,
            'created_at' => $organization->created_at?->toDateString(),
        ]);

        return Inertia::render('admin/Accounts', [
            'organizations' => $organizations,
            'search' => $search,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/AccountCreate', [
            'plans' => Plan::orderBy('id')->get(['key', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $request->input('type') === 'institutional'
            ? $this->storeInstitutional($request)
            : $this->storePersonal($request);
    }

    protected function storePersonal(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'plan_key' => ['required', Rule::exists('plans', 'key')],
        ]);

        // Provisioned accounts skip email verification (there may be no mailbox to
        // confirm) and can be handed a generated one-time password.
        $generated = ($validated['password'] ?? '') === '' ? Str::password(14) : null;

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $generated ?? $validated['password'],
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        // The organization is created ON the chosen plan. It used to be created
        // on Base and then have the chosen plan laid on top in the same request,
        // which is how two Active, open-ended subscriptions ended up on accounts
        // that were one second old (§6).
        $organization = app(CreatePersonalOrganization::class)->create(
            $user,
            Plan::where('key', $validated['plan_key'])->firstOrFail(),
        );

        $this->entitlements->flush();

        $this->log($organization, 'admin.account_created', "Conta criada: {$user->email} ({$validated['plan_key']}).");

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Conta criada.').($generated !== null ? " Palavra-passe temporária: {$generated}" : '')]);

        return redirect()->route('admin.accounts.show', $organization);
    }

    /**
     * A school's workspace, with an owner from the first instant — either
     * someone who already has an account, or someone provisioned for it here.
     *
     * A NEW owner gets the exact same treatment `storePersonal` gives anyone
     * else: their own personal organization on Base, so a school's owner is
     * never a person with nowhere to work of their own (§58 of the multi-user
     * brief — a teacher may hold a personal workspace and an institutional one
     * at once). The institutional organization is a SECOND workspace on top of
     * that, never a replacement for it. One transaction: a school is never
     * left half-created because a later step failed.
     */
    protected function storeInstitutional(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'owner_mode' => ['required', Rule::in(['existing', 'new'])],
            'plan_key' => ['required', Rule::exists('plans', 'key')],
            'owner_email' => ['required_if:owner_mode,existing', 'nullable', 'email', Rule::exists('users', 'email')],
            'name' => ['required_if:owner_mode,new', 'nullable', 'string', 'max:255'],
            'email' => ['required_if:owner_mode,new', 'nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
        ], [
            'owner_email.exists' => __('Não existe nenhum utilizador com este email.'),
        ]);

        $plan = Plan::where('key', $validated['plan_key'])->firstOrFail();

        return DB::transaction(function () use ($validated, $plan): RedirectResponse {
            $generated = null;

            if ($validated['owner_mode'] === 'existing') {
                $owner = User::where('email', $validated['owner_email'])->firstOrFail();
            } else {
                $generated = ($validated['password'] ?? '') === '' ? Str::password(14) : null;

                $owner = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => $generated ?? $validated['password'],
                ]);
                $owner->forceFill(['email_verified_at' => now()])->save();

                app(CreatePersonalOrganization::class)->create($owner, Plan::where('key', 'base')->first());
            }

            $organization = $this->createInstitutional->create($validated['organization_name'], $owner, $plan);

            $this->entitlements->flush();

            $this->log(
                $organization,
                'admin.account_created',
                "Organização institucional criada: {$organization->name}, responsável {$owner->email} ({$plan->key}).",
            );

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Organização criada.').($generated !== null ? " Palavra-passe temporária: {$generated}" : '')]);

            return redirect()->route('admin.accounts.show', $organization);
        });
    }

    public function show(Request $request, Organization $organization): Response
    {
        $organization->load(['owner', 'members']);
        $subscription = $this->currentSubscriptions(collect([$organization->id]))->get($organization->id);
        $owner = $organization->owner;

        // Computed here rather than discovered by pressing the button: an
        // operator should be able to see that an account cannot be deleted, and
        // why, without attempting it.
        $blocking = $owner === null ? [] : $this->blockingDependencies($organization, $owner);

        return Inertia::render('admin/AccountShow', [
            'account' => [
                'ulid' => $organization->ulid,
                'name' => $organization->name,
                'type' => $organization->type->value,
                'created_at' => $organization->created_at?->toDateString(),
                // Whether anybody ever promised this account anything. Grants
                // and withholds nothing — the commercial preflight is the only
                // reader, and it uses it to know whom NOT to ask about.
                'is_test_account' => (bool) $organization->is_test_account,
                'owner' => [
                    'name' => $owner?->name,
                    'email' => $owner?->email,
                    'verified' => $owner?->email_verified_at !== null,
                    'is_platform_admin' => (bool) ($owner?->is_platform_admin),
                    'active' => $owner === null || $owner->isActive(),
                    'deactivated_at' => $owner?->deactivated_at?->toDateTimeString(),
                    // Read-only (§19 of the lifecycle brief) — this backoffice
                    // has no button that acts on it. The owner's own personal
                    // account, not this organization's own closure below.
                    'closure' => $owner !== null && $owner->isClosureRequested()
                        ? [
                            ...$this->closureStatus->personal($owner->closure_requested_at, $owner->scheduled_deletion_at),
                            'eligible_for_deletion' => $owner->isEligibleForDeletion(),
                        ]
                        : null,
                ],
                // The organization's OWN closure (institutional only) — distinct
                // from owner.closure above, which is the person, not the school.
                'closure' => $organization->isClosureRequested()
                    ? [
                        ...$this->closureStatus->institutional($organization->closure_requested_at, $organization->scheduled_deletion_at),
                        'eligible_for_deletion' => $organization->isEligibleForDeletion(),
                    ]
                    : null,
                'members_count' => $organization->members->count(),
                'plan' => $subscription?->plan?->name,
                'plan_key' => $subscription?->plan?->key,
                // WHICH VERSION OF THE PLAN, since ADR-0008. «Pro» stopped
                // being a complete answer the moment a second Pro could exist:
                // an operator looking at a grandfathered account has to be able
                // to tell v1 from v2 without opening a SQL client. Read-only —
                // moving a subscription between versions is a deliberate act
                // and does not belong behind a label.
                'plan_version' => $subscription?->planVersion?->version,
                'status' => $subscription?->status?->value,
                'modules' => $this->entitlements->modulesFor($organization),
                'deactivation_refusal' => $owner === null ? __('Esta organização não tem dono.') : $this->deactivationRefusal($request, $owner),
                'blocking' => $blocking,
                // Institutional only, in practice — a personal organization has
                // exactly one member and the page does not need a roster for it.
                'members' => $organization->members->map(fn (User $member): array => [
                    'name' => $member->name,
                    'email' => $member->email,
                    'is_owner' => $owner !== null && $member->is($owner),
                    'active' => $member->isActive(),
                ])->sortByDesc('is_owner')->values(),
            ],
            'plans' => Plan::orderBy('id')->get(['key', 'name']),
        ]);
    }

    public function verifyEmail(Organization $organization): RedirectResponse
    {
        $owner = $organization->owner;
        if ($owner !== null && $owner->email_verified_at === null) {
            $owner->forceFill(['email_verified_at' => now()])->save();
            $this->log($organization, 'admin.email_verified', "Email de {$owner->email} verificado manualmente.");
        }

        return back();
    }

    public function resetPassword(Organization $organization): RedirectResponse
    {
        $owner = $organization->owner;

        if ($owner === null) {
            return back()->withErrors(['account' => __('Esta organização não tem dono.')]);
        }

        // Password::sendResetLink() calls the owner's sendPasswordResetNotification()
        // directly, unguarded (Illuminate\Auth\Passwords\PasswordBroker) — a mail
        // transport failure (bounce, relay down) throws here, not a status string.
        // Caught here rather than on the User model: this admin is looking straight
        // at the account page and can be told immediately, the same way an
        // unreachable broker status already is — no need for the model to guess
        // where to surface it, unlike the registration flow (see
        // App\Models\User::sendEmailVerificationNotification).
        try {
            $status = Password::sendResetLink(['email' => $owner->email]);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['account' => __('Não foi possível enviar o email de redefinição. Tente novamente mais tarde.')]);
        }

        if ($status !== Password::RESET_LINK_SENT) {
            return back()->withErrors(['account' => __('Não foi possível enviar o email de redefinição. Tente novamente mais tarde.')]);
        }

        $this->log($organization, 'admin.password_reset_requested', "Link de redefinição de palavra-passe enviado para {$owner->email}.");

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email de redefinição enviado.')]);

        return back();
    }

    public function generateTemporaryPassword(Organization $organization): RedirectResponse
    {
        $owner = $organization->owner;

        if ($owner === null) {
            return back()->withErrors(['account' => __('Esta organização não tem dono.')]);
        }

        $generated = Str::password(14);

        $owner->forceFill(['password' => $generated])->save();

        $this->log($organization, 'admin.password_temporary_generated', "Palavra-passe temporária gerada para {$owner->email}.");

        Inertia::flash([
            'toast' => ['type' => 'success', 'message' => __('Palavra-passe temporária gerada.')],
            'temporary_password' => ['value' => $generated, 'email' => $owner->email],
        ]);

        return back();
    }

    /**
     * Moves the account to a plan — and records what was agreed, when the
     * database already knows.
     *
     * THE PROOF COMES FROM THE MONEY, NEVER FROM A FORM. Activating Pro after
     * confirming a bank transfer is the single most common way a paid
     * subscription is created in this product, and until now it wrote a
     * subscription with all four commercial columns NULL: an account that had
     * just transferred 29,90 € as a Membro Fundador recorded, as its contract,
     * nothing at all. `CommercialTerms::fromPaidEvidence()` reads the payment
     * that is already there — its amount, its currency, the condition an
     * operator put on it and the period it bought — so the price is the one
     * that was PAID rather than the one `config/billing.php` happens to say
     * today (§11 of the brief).
     *
     * No evidence, nothing written. An operator who simply moves somebody to
     * Pro with no payment behind it produces exactly what it did before — a
     * subscription with no commercial claim on it — and the backoffice keeps
     * saying «Origem não registada», which is the truth. Marking such an
     * account is `SetCommercialCondition`'s job, deliberately separate and
     * deliberately explicit.
     */
    public function changePlan(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'plan_key' => ['required', Rule::exists('plans', 'key')],
        ]);

        $plan = Plan::where('key', $validated['plan_key'])->firstOrFail();

        $subscription = $this->planChange->to(
            $organization,
            $plan,
            $this->terms->fromPaidEvidence($organization, $plan->currentVersionOrFail()),
        );

        // Liga o lugar de fundador ao contrato que dele resultou, quando existe.
        // Puramente informativo: um operador que abra a ficha vê o número que
        // foi prometido ao lado da subscrição que o materializou.
        $this->founderSeats->attachSubscription($organization, $subscription);

        // O mesmo fio para um resgate de voucher confirmado que ainda não
        // aponte para contrato nenhum — informativo, como o do lugar.
        $this->voucherRedemptions->attachSubscription($organization, $subscription);

        $this->log($organization, 'admin.plan_changed', "Plano alterado para {$plan->name}.", [
            'plan_key' => $plan->key,
            'contracted_price_cents' => $subscription->contracted_price_cents,
            'contracted_currency' => $subscription->contracted_currency,
            'billing_period' => $subscription->billing_period?->value,
            'commercial_term_ends_at' => $subscription->commercial_term_ends_at?->toDateTimeString(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Plano atualizado para :plan.', ['plan' => $plan->name])]);

        return back();
    }

    /**
     * Takes access away — all of it. The service suspends every subscription in
     * force, not merely the newest, so no older plan underneath can quietly
     * become effective and turn «suspended» into «downgraded to Base».
     */
    public function suspend(Organization $organization): RedirectResponse
    {
        $suspended = $this->planChange->suspend($organization);

        if ($suspended > 0) {
            $this->log($organization, 'admin.subscription_suspended', 'Subscrição suspensa.');
        }

        return back();
    }

    public function reactivate(Organization $organization): RedirectResponse
    {
        if ($this->planChange->reactivate($organization) !== null) {
            $this->log($organization, 'admin.subscription_reactivated', 'Subscrição reativada.');
        }

        return back();
    }

    public function toggleAdmin(Organization $organization): RedirectResponse
    {
        $owner = $organization->owner;
        if ($owner !== null) {
            $grant = ! $owner->is_platform_admin;
            $owner->forceFill([
                'is_platform_admin' => $grant,
                'is_support_technician' => $grant,
            ])->save();
            $this->log($organization, $grant ? 'admin.admin_granted' : 'admin.admin_revoked',
                ($grant ? 'Concedido' : 'Revogado')." acesso de administrador a {$owner->email}.");
        }

        return back();
    }

    /**
     * «Conta de teste» — marked, or unmarked, and never toggled blind.
     *
     * The request carries the STATE IT WANTS, not «flip whatever is there». Two
     * operators on the same account, or one double-click, would otherwise leave
     * the mark wherever the race landed — and this is a fact somebody will later
     * read as «nobody promised this account anything».
     */
    public function setTestAccount(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'is_test_account' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $operator */
        $operator = $request->user();

        $this->setTestAccount->set(
            $organization,
            $operator,
            (bool) $validated['is_test_account'],
            $validated['note'] ?? null,
        );

        return back();
    }

    /**
     * The person's own details — the only block on this screen that belongs to a
     * human being rather than to an organization.
     *
     * A CHANGED ADDRESS LOSES ITS VERIFICATION. The email on file is a claim
     * that a mailbox exists and answers; the moment an operator rewrites it,
     * nobody has shown that of the new one. Carrying the old confirmation across
     * would be asserting something no one checked, so the account goes back
     * through the ordinary verification flow — which the operator can still
     * short-circuit with «Verificar email» if there is no mailbox to reach.
     */
    public function updateUser(Request $request, Organization $organization): RedirectResponse
    {
        $owner = $organization->owner;

        if ($owner === null) {
            return back()->withErrors(['account' => __('Esta organização não tem dono.')]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($owner->getKey())],
        ]);

        $previousEmail = $owner->email;
        $emailChanged = $validated['email'] !== $previousEmail;

        $owner->fill($validated);

        if ($emailChanged) {
            $owner->forceFill(['email_verified_at' => null]);
        }

        $owner->save();

        $this->log(
            $organization,
            'admin.user_updated',
            $emailChanged
                ? "Dados atualizados: {$previousEmail} passou a {$owner->email}, por verificar."
                : "Dados atualizados: {$owner->email}.",
            ['email_changed' => $emailChanged],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => $emailChanged
            ? __('Dados guardados. O novo email fica por verificar.')
            : __('Dados guardados.')]);

        return back();
    }

    /**
     * Shuts the person out. The subscription is untouched.
     *
     * That separation is the point: the organization keeps its plan, its data
     * and its history, and what ends is one person's ability to sign in. It is
     * also what will let this act on a single member of a school without
     * touching the school.
     */
    public function deactivate(Request $request, Organization $organization): RedirectResponse
    {
        $owner = $organization->owner;

        if ($owner === null) {
            return back()->withErrors(['account' => __('Esta organização não tem dono.')]);
        }

        $refusal = $this->deactivationRefusal($request, $owner);

        if ($refusal !== null) {
            return back()->withErrors(['account' => $refusal]);
        }

        if ($owner->isActive()) {
            $owner->forceFill(['deactivated_at' => now()])->save();
            $this->log($organization, 'admin.account_deactivated', "Conta desativada: {$owner->email}.");
        }

        return back();
    }

    public function activate(Organization $organization): RedirectResponse
    {
        $owner = $organization->owner;

        if ($owner !== null && $owner->isDeactivated()) {
            $owner->forceFill(['deactivated_at' => null])->save();
            $this->log($organization, 'admin.account_activated', "Conta reativada: {$owner->email}.");
        }

        return back();
    }

    /**
     * Attaches an existing user to this organization (Fatia 2).
     *
     * Not an invitation — the platform admin is vouching for the person
     * directly here, in the backoffice, which is the minimum needed to build
     * and test an institutional organization with more than one member before
     * Fatia 3 builds the real invite flow. Only institutional organizations
     * gain members this way; a personal organization's one-member shape is
     * definitional, not a rule this action polices.
     */
    public function addMember(Request $request, Organization $organization): RedirectResponse
    {
        if ($organization->type !== OrganizationType::Institutional) {
            return back()->withErrors(['member' => __('Só uma organização institucional pode ter mais do que um membro.')]);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', Rule::exists('users', 'email')],
        ], [
            'email.exists' => __('Não existe nenhum utilizador com este email.'),
        ]);

        $user = User::where('email', $validated['email'])->firstOrFail();

        $added = $this->addOrganizationMember->add($organization, $user);

        if ($added) {
            $this->log($organization, 'admin.member_added', "Membro adicionado: {$user->email}.");
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Membro adicionado.')]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Já era membro desta organização.')]);
        }

        return back();
    }

    /**
     * Exceptional, and refuses far more often than it proceeds.
     *
     * Deactivating is how an account is removed operationally. This exists only
     * for one that never became anything — a provisioning mistake, a duplicate,
     * a test account — and every other case is a refusal with the reason
     * spelled out.
     *
     * IT IS NOT «DELETE THE ORGANIZATION» UNDER ANOTHER NAME. An institutional
     * organization is never deleted here whatever its state, and neither is a
     * user who belongs to a second organization: those are exactly the
     * situations where «remove this person» and «remove this workspace» stop
     * meaning the same thing, and the safe answer is to refuse rather than to
     * guess which was meant.
     */
    public function destroy(Request $request, Organization $organization): RedirectResponse
    {
        $owner = $organization->owner;

        if ($owner === null) {
            return back()->withErrors(['account' => __('Esta organização não tem dono.')]);
        }

        if ($owner->is($request->user())) {
            return back()->withErrors(['account' => __('Não pode apagar a sua própria conta.')]);
        }

        if ($owner->isPlatformAdmin()) {
            return back()->withErrors(['account' => __('Não é possível apagar um administrador da plataforma. Revogue-lhe o acesso primeiro.')]);
        }

        if (! $organization->isPersonal()) {
            return back()->withErrors(['account' => __('Só uma organização pessoal pode ser apagada por aqui.')]);
        }

        $blocking = $this->blockingDependencies($organization, $owner);

        if ($blocking !== []) {
            $detail = collect($blocking)->map(fn (int $total, string $label): string => mb_strtolower($label).": {$total}")->implode(', ');

            return back()->withErrors([
                'account' => __('Esta conta tem dados associados e não pode ser apagada').
                    " ({$detail}). ".__('Desative-a em vez de a apagar.'),
            ]);
        }

        $email = $owner->email;

        // Audited BEFORE the delete, and deliberately not through log(): the
        // organization this event would be stamped with is about to stop
        // existing, and audit_events.organization_id is RESTRICT — writing it
        // here would be writing the row that then refuses the delete. An account
        // with nothing behind it leaves no trail, which is the honest record of
        // an account that never became anything.
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Conta apagada.')." ({$email})"]);

        app(DeleteUserAccount::class)->delete($owner);

        $this->entitlements->flush();

        return redirect()->route('admin.accounts.index');
    }

    /**
     * Why this account may not be shut out, or null when it may.
     *
     * Two refusals, and both are about not locking the operator out of their own
     * backoffice: their own account, and the last platform admin still standing.
     * Nothing here is about the subscription.
     */
    protected function deactivationRefusal(Request $request, User $owner): ?string
    {
        if ($owner->is($request->user())) {
            return __('Não pode desativar a sua própria conta.');
        }

        if ($owner->isPlatformAdmin() && $this->isLastActiveAdmin($owner)) {
            return __('Não é possível desativar o último administrador da plataforma ativo.');
        }

        return null;
    }

    protected function isLastActiveAdmin(User $owner): bool
    {
        return User::query()
            ->where('is_platform_admin', true)
            ->whereNull('deactivated_at')
            ->whereKeyNot($owner->getKey())
            ->doesntExist();
    }

    /**
     * Everything that stands between this account and a hard delete, counted.
     *
     * The audit trail counts, and counts deliberately. Every account provisioned
     * from this backoffice is born with an `admin.account_created` event, so in
     * practice a provisioned account can only be deleted while it is still
     * untouched — which is precisely the window this action is for. The
     * alternative was deleting history to make a delete succeed, and that is not
     * on the table (§22.4, §31).
     *
     * @return array<string, int> label => count, only non-zero entries
     */
    protected function blockingDependencies(Organization $organization, User $owner): array
    {
        $counts = $this->currentOrganization->runFor($organization, fn (): array => [
            'Turmas' => SchoolClass::query()->count(),
            'Alunos' => Student::query()->count(),
            'Inscrições' => Enrollment::query()->count(),
            'Elementos de avaliação' => Instrument::query()->count(),
            'Registos' => EvidenceRecord::query()->count(),
            'Intervenções' => Intervention::query()->count(),
            'Relatórios' => Report::query()->count(),
            'Perfis de avaliação' => AssessmentProfile::query()->count(),
            'Registo de atividade' => AuditEvent::query()->count(),
        ]);

        // Across every organization, not just this one: audit_events.causer_id is
        // RESTRICT, so a person who has acted anywhere cannot be deleted at all,
        // and saying so up front beats a foreign-key error at the end.
        $counts['Ações registadas noutras organizações'] = AuditEvent::query()
            ->withoutGlobalScope('organization')
            ->where('causer_id', $owner->getKey())
            ->whereNot('organization_id', $organization->getKey())
            ->count();

        // A second organization means «remove the person» and «remove the
        // workspace» are no longer the same request.
        $counts['Outras organizações'] = $owner->organizations()
            ->whereKeyNot($organization->getKey())
            ->count();

        return array_filter($counts, fn (int $total): bool => $total > 0);
    }

    /**
     * The in-force subscription per organization id, tenant scope dropped.
     *
     * @param  Collection<int, int>  $organizationIds
     * @return Collection<int, OrganizationSubscription>
     */
    protected function currentSubscriptions($organizationIds)
    {
        return OrganizationSubscription::query()
            ->withoutGlobalScope('organization')
            ->whereIn('organization_id', $organizationIds)
            ->with(['plan', 'planVersion'])
            ->latest('starts_at')
            ->latest('id')
            ->get()
            ->groupBy('organization_id')
            ->map(fn ($subscriptions) => $subscriptions->first(fn (OrganizationSubscription $subscription) => $subscription->isInForce()) ?? $subscriptions->first());
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function log(Organization $organization, string $event, string $summary, array $properties = []): void
    {
        // Admin actions land in the TARGET organization's audit trail — so it is
        // stamped with that org, not the (absent) current tenant.
        $this->currentOrganization->runFor($organization, fn () => $this->audit->record($event, $organization, summary: $summary, properties: $properties));
    }
}
