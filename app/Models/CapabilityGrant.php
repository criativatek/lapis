<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property CapabilityGrantSource $source
 * @property int|null $capability_voucher_id
 * @property int|null $granted_by
 * @property string|null $reason
 * @property Carbon $starts_at
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 */
#[Fillable(['source', 'capability_voucher_id', 'granted_by', 'reason', 'starts_at', 'expires_at'])]
class CapabilityGrant extends Model
{
    use BelongsToOrganization, HasUlids;

    protected const MUTABLE = ['revoked_at', 'revoked_by', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $grant): void {
            $forbidden = array_diff(array_keys($grant->getDirty()), self::MUTABLE);
            if ($forbidden !== []) {
                throw new LogicException('A capability grant is immutable except for revocation.');
            }
        });
        static::deleting(fn () => throw new LogicException('Capability grants are revoked, never deleted.'));
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
        return [
            'source' => CapabilityGrantSource::class,
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Module, $this> */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'capability_grant_module');
    }

    /**
     * The capability voucher this grant came from, when `source` is
     * `CapabilityGrantSource::Voucher` — null for a `Direct` grant. Read-only:
     * a presentation lookup for "Benefícios ativos" (§settings/Plan), never
     * consulted by any access-control decision (that stays in `Entitlements`).
     *
     * @return BelongsTo<CapabilityVoucher, $this>
     */
    public function capabilityVoucher(): BelongsTo
    {
        return $this->belongsTo(CapabilityVoucher::class);
    }
}
