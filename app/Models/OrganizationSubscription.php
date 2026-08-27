<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What an organization is entitled to, and for how long.
 *
 * `plan_id` is WHAT; `commercial_condition` is on what TERMS. The second never
 * influences the first, and `App\Support\Entitlements\Entitlements` never reads
 * it — a Membro Fundador is `plan = pro` with `commercial_condition = founder`,
 * entitled to exactly what a standard Pro is entitled to. A NULL condition is
 * "origem não registada", not a default: see the 2026_09_08_000100 migration
 * for why no existing row was backfilled.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $plan_id
 * @property SubscriptionStatus $status
 * @property CommercialCondition|null $commercial_condition
 * @property string|null $commercial_condition_note
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 */
#[Fillable(['organization_id', 'plan_id', 'status', 'commercial_condition', 'commercial_condition_note', 'starts_at', 'ends_at'])]
class OrganizationSubscription extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'commercial_condition' => CommercialCondition::class,
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
     * Payments recorded against THIS subscription period specifically.
     *
     * Not every payment of the organization: a payment whose subscription was
     * later superseded keeps pointing at the period it actually bought, and one
     * recorded without a period at all points at nothing. The account-level
     * total is a query on `organization_id`, not this relation.
     *
     * @return HasMany<SubscriptionPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class, 'organization_subscription_id');
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
