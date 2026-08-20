<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Organizations\MembershipException;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

class TransferOrganizationOwnership
{
    public function __construct(
        protected AuditLog $audit,
        protected CurrentOrganization $currentOrganization,
    ) {}

    public function transfer(Organization $organization, User $currentOwner, User $target): Organization
    {
        $this->guard($organization, $currentOwner, $target);

        return DB::transaction(function () use ($organization, $currentOwner, $target): Organization {
            $lockedOrganization = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guard($lockedOrganization, $currentOwner, $target);
            $previousOwnerId = (int) $lockedOrganization->owner_id;

            $lockedOrganization->update(['owner_id' => $target->getKey()]);

            $this->currentOrganization->runFor($lockedOrganization, fn () => $this->audit->record(
                'organization.ownership_transferred',
                $lockedOrganization,
                causer: $currentOwner,
                summary: "A responsabilidade da organização foi transferida para {$target->name}.",
                properties: [
                    'previous_owner_id' => $previousOwnerId,
                    'new_owner_id' => (int) $target->getKey(),
                ],
            ));

            $lockedOrganization->refresh();
            $freshOrganization = $lockedOrganization;

            if ($this->currentOrganization->isResolved()
                && $this->currentOrganization->id() === $freshOrganization->getKey()) {
                $this->currentOrganization->set($freshOrganization);
            }

            return $freshOrganization;
        });
    }

    protected function guard(Organization $organization, User $currentOwner, User $target): void
    {
        if ($organization->type !== OrganizationType::Institutional) {
            throw MembershipException::institutionalOnly();
        }

        if (! $currentOwner->owns($organization)) {
            throw MembershipException::notOwner();
        }

        if (! $organization->members()->whereKey($target->getKey())->exists()) {
            throw MembershipException::notAMember();
        }

        if ($target->getKey() === $currentOwner->getKey()) {
            throw MembershipException::ownershipTargetMustDiffer();
        }
    }
}
