<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of the audit trail (§22.4). Immutable by contract — a guard blocks any
 * update, because an audit line that can be rewritten proves nothing.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $causer_id
 * @property string $event
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_ulid
 * @property string|null $summary
 * @property array<string, mixed>|null $properties
 * @property Carbon $created_at
 */
#[Fillable([
    'causer_id', 'event', 'subject_type', 'subject_id', 'subject_ulid', 'summary', 'properties', 'created_at',
])]
class AuditEvent extends Model
{
    use BelongsToOrganization, HasUlids;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('An audit event is immutable and cannot be updated.');
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
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }
}
