<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform backoffice — every organization across the SaaS. Runs outside any
 * tenant (no `organization` middleware): Organization/User are not tenant-scoped,
 * and the tenant-scoped subscription rows are read with `withoutGlobalScope`.
 */
class AdminAccountController extends Controller
{
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

        // Current plan per listed org — cross-org, so the tenant scope is dropped.
        $current = OrganizationSubscription::query()
            ->withoutGlobalScope('organization')
            ->whereIn('organization_id', collect($organizations->items())->pluck('id'))
            ->with('plan')
            ->latest('starts_at')
            ->get()
            ->groupBy('organization_id')
            ->map(fn ($subscriptions) => $subscriptions->first(fn (OrganizationSubscription $subscription) => $subscription->isInForce()) ?? $subscriptions->first());

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
}
