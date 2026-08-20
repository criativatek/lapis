<?php

namespace App\Http\Controllers\Admin;

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
use App\Models\Plan;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Conta criada.').($generated !== null ? " Password temporária: {$generated}" : '')]);

        return redirect()->route('admin.accounts.show', $organization);
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
                'owner' => [
                    'name' => $owner?->name,
                    'email' => $owner?->email,
                    'verified' => $owner?->email_verified_at !== null,
                    'is_platform_admin' => (bool) ($owner?->is_platform_admin),
                    'active' => $owner === null || $owner->isActive(),
                    'deactivated_at' => $owner?->deactivated_at?->toDateTimeString(),
                ],
                'members_count' => $organization->members->count(),
                'plan' => $subscription?->plan?->name,
                'plan_key' => $subscription?->plan?->key,
                'status' => $subscription?->status?->value,
                'modules' => $this->entitlements->modulesFor($organization),
                'deactivation_refusal' => $owner === null ? __('Esta organização não tem dono.') : $this->deactivationRefusal($request, $owner),
                'blocking' => $blocking,
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

    public function changePlan(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'plan_key' => ['required', Rule::exists('plans', 'key')],
        ]);

        $plan = Plan::where('key', $validated['plan_key'])->firstOrFail();

        $this->planChange->to($organization, $plan);

        $this->log($organization, 'admin.plan_changed', "Plano alterado para {$plan->name}.", ['plan_key' => $plan->key]);

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
            $owner->forceFill(['is_platform_admin' => $grant])->save();
            $this->log($organization, $grant ? 'admin.admin_granted' : 'admin.admin_revoked',
                ($grant ? 'Concedido' : 'Revogado')." acesso de administrador a {$owner->email}.");
        }

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
            ->with('plan')
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
