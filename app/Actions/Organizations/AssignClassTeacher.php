<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Organizations\MembershipException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AssignClassTeacher
{
    public function __construct(protected AuditLog $audit) {}

    public function assign(SchoolClass $class, User $target): void
    {
        DB::transaction(function () use ($class, $target): void {
            $organization = Organization::query()
                ->whereKey($class->organization_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedClass = SchoolClass::query()
                ->whereKey($class->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedClass->teachers()->exists()) {
                throw MembershipException::classAlreadyAssigned();
            }

            if (! $organization->members()->whereKey($target->getKey())->exists()) {
                throw MembershipException::notAMember();
            }

            $lockedClass->teachers()->attach($target, ['role' => 'owner']);

            $causer = Auth::user();

            $this->audit->record(
                'class.reassigned',
                $lockedClass,
                causer: $causer instanceof User ? $causer : null,
                summary: "Turma «{$lockedClass->label}» atribuída a {$target->name}.",
                properties: ['assigned_to_id' => (int) $target->getKey()],
            );
        });
    }
}
