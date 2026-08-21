<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Organizations\MembershipException;
use App\Support\Retention\ClosureRetention;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Reverses an organization closure request within the recovery window
 * (§12 of the lifecycle brief). Memberships, the owner and every pedagogical
 * record were never touched by the request, so there is nothing to restore
 * beyond the two lifecycle columns.
 */
class CancelOrganizationClosure
{
    public function __construct(
        protected AuditLog $audit,
        protected ClosureRetention $retention,
        protected CurrentOrganization $currentOrganization,
    ) {}

    public function cancel(Organization $organization, User $owner): Organization
    {
        $this->guard($organization, $owner);

        return DB::transaction(function () use ($organization, $owner): Organization {
            $lockedOrganization = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guard($lockedOrganization, $owner);

            $lockedOrganization->forceFill([
                'closure_requested_at' => null,
                'scheduled_deletion_at' => null,
            ])->save();

            $this->currentOrganization->runFor($lockedOrganization, fn () => $this->audit->record(
                'organization.closure_cancelled',
                $lockedOrganization,
                causer: $owner,
                summary: __('Cancelou o encerramento da organização e reativou o acesso normal.'),
            ));

            $lockedOrganization->refresh();

            if ($this->currentOrganization->isResolved()
                && $this->currentOrganization->id() === $lockedOrganization->getKey()) {
                $this->currentOrganization->set($lockedOrganization);
            }

            return $lockedOrganization;
        });
    }

    protected function guard(Organization $organization, User $owner): void
    {
        if ($organization->type !== OrganizationType::Institutional) {
            throw MembershipException::institutionalOnly();
        }

        if (! $owner->owns($organization)) {
            throw MembershipException::notOwner();
        }

        if (! $organization->isClosureRequested()) {
            throw MembershipException::closureNotRequested();
        }

        if (! $this->retention->isInstitutionalOrganizationRecoverable($organization->closure_requested_at, now())) {
            throw MembershipException::closureNoLongerRecoverable();
        }
    }
}
