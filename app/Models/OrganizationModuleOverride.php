<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Grants or withdraws a single module for one organization, on top of its plan.
 *
 * Lets a module be sold separately, or pulled, without inventing a bespoke plan
 * per customer (§4.3, §8.2).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $module_id
 * @property bool $enabled
 * @property string|null $reason
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
#[Fillable(['organization_id', 'module_id', 'enabled', 'reason', 'starts_at', 'ends_at'])]
class OrganizationModuleOverride extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Module, $this>
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    /**
     * A null date means "no bound on that side", so an override with neither is
     * simply always in force.
     */
    public function isInForce(): bool
    {
        $now = Carbon::now();

        return ($this->starts_at === null || $this->starts_at->lessThanOrEqualTo($now))
            && ($this->ends_at === null || $this->ends_at->greaterThan($now));
    }
}
