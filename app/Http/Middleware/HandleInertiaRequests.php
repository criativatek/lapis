<?php

namespace App\Http\Middleware;

use App\Models\AcademicYear;
use App\Models\Subject;
use App\Support\Entitlements\Entitlements;
use App\Support\Navigation\NavigationBuilder;
use App\Support\Retention\ClosureStatusPresenter;
use App\Support\Retention\ResolveSelectedAcademicYear;
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
        $closureStatus = app(ClosureStatusPresenter::class);
        $academicYears = $hasOrganization
            ? AcademicYear::query()->orderByDesc('starts_on')->get()
            : collect();
        $sessionSelectedId = $request->session()->get('academic_year_id');
        $selectedAcademicYear = app(ResolveSelectedAcademicYear::class)->for(
            $academicYears,
            is_int($sessionSelectedId) ? $sessionSelectedId : null,
        );

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
            // The header context selectors. Academic year reads the same
            // heuristic AcademicYearRetentionClassifier already uses and tests
            // (single Active year, else most recent by starts_on) — never
            // invented here. Subject/gradeLevel/class/period stay null: there
            // is no canonical "current" one to read yet, and guessing would be
            // exactly the kind of invented data this prop was built to avoid.
            'selectableAcademicYears' => fn () => $hasOrganization
                ? $academicYears
                    ->take(4)
                    ->map(fn (AcademicYear $academicYear): array => [
                        'ulid' => $academicYear->ulid,
                        'label' => $academicYear->label,
                        'is_current' => $selectedAcademicYear !== null && $academicYear->is($selectedAcademicYear),
                    ])
                    ->values()
                : [],
            'scope' => fn () => [
                'academicYear' => $selectedAcademicYear?->label,
                'subject' => null,
                'hasSubjects' => $hasOrganization && Subject::query()->exists(),
                'gradeLevel' => null,
                'class' => null,
                'period' => null,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // A platform admin viewing the app as a teacher — drives the banner.
            'impersonating' => $request->session()->has('impersonator_id')
                ? ['name' => $request->user()?->name]
                : null,
            // Fatia 5 (§13-§14): the recovery-window banners. Backend decides
            // recoverable/eligible — the client only ever displays what it is
            // told, never recomputes the boundary.
            'accountClosure' => $user !== null && $user->isClosureRequested()
                ? $closureStatus->personal($user->closure_requested_at, $user->scheduled_deletion_at)
                : null,
            'organizationClosure' => $hasOrganization && $currentOrganization->get()->isClosureRequested()
                ? [
                    ...$closureStatus->institutional(
                        $currentOrganization->get()->closure_requested_at,
                        $currentOrganization->get()->scheduled_deletion_at,
                    ),
                    'is_owner' => $user !== null && $user->owns($currentOrganization->get()),
                ]
                : null,
        ];
    }
}
