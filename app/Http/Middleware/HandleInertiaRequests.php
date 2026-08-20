<?php

namespace App\Http\Middleware;

use App\Support\Entitlements\Entitlements;
use App\Support\Navigation\NavigationBuilder;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $currentOrganization = app(CurrentOrganization::class);
        $hasOrganization = $currentOrganization->isResolved();
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'appVersion' => config('app.version'),
            'auth' => [
                'user' => $user,
                'organization' => $hasOrganization ? [
                    'ulid' => $currentOrganization->get()->ulid,
                    'name' => $currentOrganization->get()->name,
                    'type' => $currentOrganization->get()->type->value,
                    'is_owner' => $user !== null && $user->owns($currentOrganization->get()),
                ] : null,
                // Every organization this person can switch into (Fatia 2) — one
                // indexed query on the pivot, not per-page N+1. A user with just
                // the one organization everybody starts with gets a one-item list;
                // the switcher itself decides whether that is worth showing.
                'organizations' => $user === null
                    ? []
                    : $user->organizations()->orderBy('organization_memberships.joined_at')->get()->map(fn ($organization) => [
                        'ulid' => $organization->ulid,
                        'name' => $organization->name,
                        'type' => $organization->type->value,
                        'is_owner' => $user->owns($organization),
                    ])->values(),
            ],
            // Menu already filtered by entitlements, and the module keys the org
            // holds, so the client can gate presentation without re-deriving it.
            'nav' => fn () => $hasOrganization
                ? app(NavigationBuilder::class)->forCurrentOrganization()
                : ['sections' => [], 'footer' => []],
            'modules' => fn () => app(Entitlements::class)->modules(),
            // The header context selectors. Empty until the academic model exists
            // (Fase 1) — the structure is shipped, the data is not invented.
            'scope' => [
                'academicYear' => null,
                'subject' => null,
                'gradeLevel' => null,
                'class' => null,
                'period' => null,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // A platform admin viewing the app as a teacher — drives the banner.
            'impersonating' => $request->session()->has('impersonator_id')
                ? ['name' => $request->user()?->name]
                : null,
        ];
    }
}
