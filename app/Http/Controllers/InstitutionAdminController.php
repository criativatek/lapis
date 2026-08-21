<?php

namespace App\Http\Controllers;

use App\Models\OrganizationInvitation;
use App\Support\Retention\ClosureStatusPresenter;
use App\Support\Retention\RetentionPolicy;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InstitutionAdminController extends Controller
{
    public function __construct(
        protected CurrentOrganization $currentOrganization,
        protected ClosureStatusPresenter $closureStatus,
        protected RetentionPolicy $retentionPolicy,
    ) {}

    public function index(): Response
    {
        $organization = $this->currentOrganization->get();

        Gate::authorize('viewAny', [OrganizationInvitation::class, $organization]);

        return Inertia::render('institution/Index', [
            'organization' => [
                'name' => $organization->name,
                'type' => $organization->type,
            ],
            'closure' => $organization->isClosureRequested()
                ? $this->closureStatus->institutional($organization->closure_requested_at, $organization->scheduled_deletion_at)
                : null,
            'closureRetentionDays' => $this->retentionPolicy->institutionalClosureDays(),
        ]);
    }
}
