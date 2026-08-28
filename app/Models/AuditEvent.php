<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of the audit trail (§22.4). Immutable by contract — a guard blocks any
 * update, because an audit line that can be rewritten proves nothing.
 *
 * A ROW MAY BELONG TO THE PLATFORM RATHER THAN TO A TENANT. `organization_id` is
 * null on those, they are written only by `AuditLog::recordPlatform()`, and they
 * are invisible to every tenant query for free — the global scope compares the
 * column to a number, and NULL is never equal to one. They exist so that acts of
 * the SaaS operator that affect every organization (turning the AI engine on,
 * replacing its credential) have somewhere truthful to be recorded.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $organization_id
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

    /**
     * The audit trail as this user is allowed to read it (Fatia 1, §22.4).
     *
     * The organization owner reads the trail whole — the audit log is an
     * institutional security function, not a personal diary, and the owner is
     * the one authority this schema has above "a teacher who belongs here".
     * Everyone else reads only what THEY caused: `causer_id = $user->id`. A
     * system-caused row (`causer_id` null) never equals anyone's id, so a
     * plain equality already excludes it for a member without a special case
     * — and the owner still sees it, because the unscoped branch above
     * doesn't filter at all.
     *
     * On a personal organization the owner IS the only person who ever acts
     * there, so the two branches return the same rows — this is not
     * special-cased for organization type, on purpose: the rule is uniform,
     * and personal organizations get the "owner sees everything" answer for
     * free because there is only ever one everything to see.
     *
     * @param  Builder<AuditEvent>  $query
     * @return Builder<AuditEvent>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->ownsCurrentOrganization()) {
            return $query;
        }

        return $query->where('causer_id', $user->getKey());
    }
}
