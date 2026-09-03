<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $capability_voucher_id
 * @property int $capability_grant_id
 * @property int|null $redeemed_by
 * @property Carbon $redeemed_at
 */
#[Fillable(['capability_voucher_id', 'capability_grant_id', 'redeemed_by', 'redeemed_at'])]
class CapabilityVoucherRedemption extends Model
{
    use BelongsToOrganization, HasUlids;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A capability voucher redemption is immutable.'));
        static::deleting(fn () => throw new LogicException('A capability voucher redemption cannot be deleted.'));
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
        return ['redeemed_at' => 'datetime'];
    }
}
