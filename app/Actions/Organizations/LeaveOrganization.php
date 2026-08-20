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

class LeaveOrganization
{
    public function __construct(
        protected AuditLog $audit,
        protected CurrentOrganization $currentOrganization,
    ) {}

    public function leave(Organization $organization, User $user): void
    {
        $this->guardInstitutional($organization);
        $this->guardMember($organization, $user);
        $this->guardNotOwner($organization, $user);

        DB::transaction(function () use ($organization, $user): void {
            $lockedOrganization = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardMember($lockedOrganization, $user);
            $this->guardNotOwner($lockedOrganization, $user);

            $this->currentOrganization->runFor($lockedOrganization, function () use ($lockedOrganization, $user): void {
                SchoolClass::query()
                    ->whereHas('teachers', fn ($query) => $query->whereKey($user->getKey()))
                    ->get()
                    ->each(fn (SchoolClass $class) => $class->teachers()->detach($user));

                $lockedOrganization->members()->detach($user);

                $this->audit->record(
                    'organization.member_left',
                    $lockedOrganization,
                    causer: $user,
                    summary: "{$user->name} saiu da organização.",
                );
            });
        });
    }

    protected function guardInstitutional(Organization $organization): void
    {
        if ($organization->type !== OrganizationType::Institutional) {
            throw MembershipException::institutionalOnly();
        }
    }

    protected function guardMember(Organization $organization, User $user): void
    {
        if (! $organization->members()->whereKey($user->getKey())->exists()) {
            throw MembershipException::notAMember();
        }
    }

    protected function guardNotOwner(Organization $organization, User $user): void
    {
        if ($user->owns($organization)) {
            throw MembershipException::ownerCannotLeave();
        }
    }
}
