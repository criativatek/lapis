<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Services\Audit\AuditLog;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
            'plan' => $current->get($organization->id)?->plan?->name,
            'status' => $current->get($organization->id)?->status?->value,
            'created_at' => $organization->created_at?->toDateString(),
        ]);

        return Inertia::render('admin/Accounts', [
            'organizations' => $organizations,
            'search' => $search,
        ]);
    }

    public function show(Organization $organization): Response
    {
        $organization->load(['owner', 'members']);
        $subscription = $this->currentSubscriptions(collect([$organization->id]))->get($organization->id);

        return Inertia::render('admin/AccountShow', [
            'account' => [
                'ulid' => $organization->ulid,
                'name' => $organization->name,
                'type' => $organization->type->value,
                'created_at' => $organization->created_at?->toDateString(),
                'owner' => [
                    'name' => $organization->owner?->name,
                    'email' => $organization->owner?->email,
                    'verified' => $organization->owner?->email_verified_at !== null,
                    'is_platform_admin' => (bool) ($organization->owner?->is_platform_admin),
                ],
                'members_count' => $organization->members->count(),
                'plan' => $subscription?->plan?->name,
                'plan_key' => $subscription?->plan?->key,
                'status' => $subscription?->status?->value,
                'modules' => $this->entitlements->modulesFor($organization),
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

        OrganizationSubscription::query()->withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now(),
        ]);
        $this->entitlements->flush();

        $this->log($organization, 'admin.plan_changed', "Plano alterado para {$plan->name}.", ['plan_key' => $plan->key]);

        return back();
    }

    public function suspend(Organization $organization): RedirectResponse
    {
        return $this->setStatus($organization, SubscriptionStatus::Suspended, 'admin.subscription_suspended', 'Subscrição suspensa.');
    }

    public function reactivate(Organization $organization): RedirectResponse
    {
        return $this->setStatus($organization, SubscriptionStatus::Active, 'admin.subscription_reactivated', 'Subscrição reativada.');
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

    protected function setStatus(Organization $organization, SubscriptionStatus $status, string $event, string $summary): RedirectResponse
    {
        $subscription = OrganizationSubscription::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->latest('starts_at')
            ->latest('id')
            ->first();

        if ($subscription !== null) {
            $subscription->update(['status' => $status]);
            $this->entitlements->flush();
            $this->log($organization, $event, $summary);
        }

        return back();
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
