<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What an organization is entitled to, and for how long.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $plan_id
 * @property SubscriptionStatus $status
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 */
#[Fillable(['organization_id', 'plan_id', 'status', 'starts_at', 'ends_at'])]
class OrganizationSubscription extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * In force right now: a status that grants access, and inside its date window.
     */
    public function isInForce(): bool
    {
        if (! $this->status->grantsAccess()) {
            return false;
        }

        $now = Carbon::now();

        return $this->starts_at->lessThanOrEqualTo($now)
            && ($this->ends_at === null || $this->ends_at->greaterThan($now));
    }
}
