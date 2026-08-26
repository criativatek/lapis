<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Limits\LimitKey;
use App\Support\Limits\Limits;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Creates a class and records its creator as the owning teacher (class_teachers),
 * in one transaction. The class_teachers link is the basis of the sidebar's
 * "only my classes" principle.
 *
 * This is the ONE product path that creates a turma (§Lote 3), so the
 * `active_classes` quota is enforced here and nowhere else needs to repeat
 * it: `ClassController::activate()` (Preparation→Active) never grows usage,
 * since both statuses already count via `SchoolClass::scopeCountingTowardsLimit()`.
 */
class ClassService
{
    public function __construct(
        protected Limits $limits,
        protected CurrentOrganization $currentOrganization,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $owner): SchoolClass
    {
        return DB::transaction(function () use ($attributes, $owner): SchoolClass {
            // Row-locked BEFORE counting usage and deciding: this is what
            // serializes two concurrent requests for the same organization
            // (§Lote 3) — the same Organization-lock pattern already used by
            // RequestPersonalAccountClosure/ExecuteDataImport, reused rather
            // than a new lock mechanism.
            $organization = Organization::query()
                ->whereKey($this->currentOrganization->id())
                ->lockForUpdate()
                ->firstOrFail();

            $this->limits->assertCanIncreaseFor($organization, LimitKey::ActiveClasses);

            $class = SchoolClass::create($attributes);

            $class->teachers()->attach($owner, ['role' => 'owner']);

            return $class;
        });
    }
}
