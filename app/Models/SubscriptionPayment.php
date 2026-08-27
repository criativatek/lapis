<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One payment that really was received.
 *
 * IMMUTABLE WHERE IT MATTERS. `AuditEvent` forbids every update; this model
 * cannot, because a refund and a void are updates. So it forbids a specific,
 * enumerated set instead: the money, the currency, the date the money arrived,
 * the organization, the period paid for, the condition it was paid under and
 * who recorded it can never change after the row exists. Only the status group
 * moves, and only with a reason and an author attached.
 *
 * That is what makes "o preço histórico é preservado" a property of the schema
 * rather than a promise in a document: when the Pro price moves, no code path
 * exists that could carry the new figure back onto a payment made at the old
 * one, because `amount_cents` cannot be written twice.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $organization_subscription_id
 * @property int $amount_cents
 * @property string $currency
 * @property PaymentStatus $status
 * @property PaymentMethod|null $method
 * @property string|null $provider
 * @property string|null $provider_reference
 * @property CommercialCondition|null $commercial_condition
 * @property string|null $voucher_code
 * @property Carbon|null $period_starts_at
 * @property Carbon|null $period_ends_at
 * @property Carbon|null $paid_at
 * @property int|null $recorded_by
 * @property Carbon|null $status_changed_at
 * @property string|null $status_reason
 * @property int|null $status_changed_by
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'organization_id', 'organization_subscription_id', 'amount_cents', 'currency', 'status',
    'method', 'provider', 'provider_reference', 'commercial_condition', 'voucher_code',
    'period_starts_at', 'period_ends_at', 'paid_at', 'recorded_by', 'metadata',
])]
class SubscriptionPayment extends Model
{
    use BelongsToOrganization, HasUlids;

    /**
     * Everything a correction is allowed to touch. Anything else is history.
     *
     * `updated_at` rides along because Eloquent stamps it on any save; it is
     * metadata about the row, not about the money.
     *
     * @var list<string>
     */
    protected const MUTABLE_AFTER_CREATION = [
        'status', 'status_changed_at', 'status_reason', 'status_changed_by', 'updated_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $payment): void {
            $forbidden = array_diff(array_keys($payment->getDirty()), self::MUTABLE_AFTER_CREATION);

            if ($forbidden !== []) {
                throw new LogicException(
                    'A recorded payment is immutable except for its status: refused change to '
                    .implode(', ', $forbidden).'. Correct it with a refund or a void instead.'
                );
            }
        });
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'status' => PaymentStatus::class,
            'method' => PaymentMethod::class,
            'commercial_condition' => CommercialCondition::class,
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'paid_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<OrganizationSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(OrganizationSubscription::class, 'organization_subscription_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    public function countsAsRevenue(): bool
    {
        return $this->status->countsAsRevenue();
    }

    /**
     * The rows revenue is made of. Every total in the backoffice starts here,
     * so there is exactly one definition of "receita" to get wrong.
     *
     * @param  Builder<SubscriptionPayment>  $query
     * @return Builder<SubscriptionPayment>
     */
    public function scopeRevenue(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Paid);
    }
}
