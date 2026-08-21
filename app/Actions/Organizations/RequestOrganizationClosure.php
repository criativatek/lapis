<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Organizations\MembershipException;
use App\Support\Retention\RetentionPolicy;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Starts the recoverable closure window for an institutional organization
 * (§9-§11 of the lifecycle brief). Memberships, the owner and every
 * pedagogical record stay exactly as they are — this only stamps a deadline
 * that CancelOrganizationClosure can undo and that
 * EnsureAccountIsOperational reads to block new pedagogical activity.
 */
class RequestOrganizationClosure
{
    public function __construct(
        protected AuditLog $audit,
        protected RetentionPolicy $policy,
        protected CurrentOrganization $currentOrganization,
    ) {}

    public function request(Organization $organization, User $owner): Organization
    {
        $this->guard($organization, $owner);

        return DB::transaction(function () use ($organization, $owner): Organization {
            $lockedOrganization = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guard($lockedOrganization, $owner);

            $requestedAt = now();
            $scheduledDeletionAt = $requestedAt->copy()->addDays($this->policy->institutionalClosureDays());

            $lockedOrganization->forceFill([
                'closure_requested_at' => $requestedAt,
                'scheduled_deletion_at' => $scheduledDeletionAt,
            ])->save();

            $this->currentOrganization->runFor($lockedOrganization, fn () => $this->audit->record(
                'organization.closure_requested',
                $lockedOrganization,
                causer: $owner,
                summary: __('Pediu o encerramento da organização.'),
                properties: ['scheduled_deletion_at' => $scheduledDeletionAt->toIso8601String()],
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

        if ($organization->isClosureRequested()) {
            throw MembershipException::closureAlreadyRequested();
        }
    }
}
