<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Organizations\MembershipException;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

class RemoveOrganizationMember
{
    public function __construct(
        protected AuditLog $audit,
        protected CurrentOrganization $currentOrganization,
    ) {}

    public function remove(Organization $organization, User $actingOwner, User $target): void
    {
        $this->guard($organization, $actingOwner, $target);

        DB::transaction(function () use ($organization, $actingOwner, $target): void {
            $lockedOrganization = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guard($lockedOrganization, $actingOwner, $target);

            $this->currentOrganization->runFor($lockedOrganization, function () use ($lockedOrganization, $actingOwner, $target): void {
                SchoolClass::query()
                    ->whereHas('teachers', fn ($query) => $query->whereKey($target->getKey()))
                    ->get()
                    ->each(fn (SchoolClass $class) => $class->teachers()->detach($target));

                $lockedOrganization->members()->detach($target);

                $this->audit->record(
                    'organization.member_removed',
                    $target,
                    causer: $actingOwner,
                    summary: "{$target->name} foi removido/a da organização.",
                );
            });
        });
    }

    protected function guard(Organization $organization, User $actingOwner, User $target): void
    {
        if ($organization->type !== OrganizationType::Institutional) {
            throw MembershipException::institutionalOnly();
        }

        if (! $actingOwner->owns($organization)) {
            throw MembershipException::notOwner();
        }

        if (! $organization->members()->whereKey($target->getKey())->exists()) {
            throw MembershipException::notAMember();
        }

        if ($target->owns($organization)) {
            throw MembershipException::ownerCannotBeRemoved();
        }

        if ($target->getKey() === $actingOwner->getKey()) {
            throw MembershipException::cannotRemoveSelf();
        }
    }
}
