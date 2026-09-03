<?php

namespace App\Models;

use App\Support\Entitlements\CapabilityVoucherCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $ulid
 * @property string $code
 * @property string $normalized_code
 * @property string $label
 * @property int|null $preset_id
 * @property int $duration_days
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 * @property int|null $max_redemptions
 * @property int|null $restricted_organization_id
 * @property Carbon|null $disabled_at
 * @property int|null $disabled_by
 * @property int|null $created_by
 */
#[Fillable(['code', 'label', 'preset_id', 'duration_days', 'valid_from', 'valid_until', 'max_redemptions', 'restricted_organization_id', 'created_by', 'notes'])]
class CapabilityVoucher extends Model
{
    use HasUlids;

    protected const MUTABLE = ['disabled_at', 'disabled_by', 'updated_at'];

    protected static function booted(): void
    {
        static::creating(fn (self $voucher) => $voucher->normalized_code = CapabilityVoucherCode::normalize($voucher->code));
        static::updating(function (self $voucher): void {
            $forbidden = array_diff(array_keys($voucher->getDirty()), self::MUTABLE);
            if ($forbidden !== []) {
                throw new LogicException('An issued capability voucher is immutable except for its disabled state.');
            }
        });
        static::deleting(fn () => throw new LogicException('Capability vouchers are disabled, never deleted.'));
    }

    /** @return array<int, string> */
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
        return ['duration_days' => 'integer', 'max_redemptions' => 'integer', 'valid_from' => 'datetime', 'valid_until' => 'datetime', 'disabled_at' => 'datetime'];
    }

    /** @return BelongsToMany<Module, $this> */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'capability_voucher_module');
    }

    /** @return HasMany<CapabilityVoucherRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CapabilityVoucherRedemption::class);
    }

    /**
     * @param  Builder<CapabilityVoucher>  $query
     * @return Builder<CapabilityVoucher>
     */
    public function scopeCode(Builder $query, string $code): Builder
    {
        return $query->where('normalized_code', CapabilityVoucherCode::normalize($code));
    }
}
