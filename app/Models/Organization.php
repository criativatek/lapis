<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A workspace. Every teacher gets a personal one on registration; an institution
 * gets an institutional one that can hold many members.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property OrganizationType $type
 * @property int $owner_id
 * @property string $timezone
 * @property string $locale
 * @property string|null $jurisdiction ISO 3166-1 alpha-2; NULL = never stated, not "Portugal"
 * @property Carbon|null $closure_requested_at
 * @property Carbon|null $scheduled_deletion_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'type', 'owner_id', 'timezone', 'locale', 'jurisdiction'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUlids;

    /**
     * Keep the auto-increment id as the primary key for foreign keys and joins,
     * but expose the ULID in URLs so record ids are not guessable or countable.
     *
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
            'type' => OrganizationType::class,
            'closure_requested_at' => 'datetime',
            'scheduled_deletion_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Who this school is on a document — absent until somebody fills it in.
     *
     * @return HasOne<OrganizationIdentity, $this>
     */
    public function identity(): HasOne
    {
        return $this->hasOne(OrganizationIdentity::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_memberships')
            ->withPivot('joined_at')
            ->withTimestamps();
    }

    public function isPersonal(): bool
    {
        return $this->type === OrganizationType::Personal;
    }

    /**
     * @return HasMany<OrganizationInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    /**
     * A voluntary, recoverable closure request against the whole workspace —
     * never a substitute for removing one member. See docs/account-closure.md.
     */
    public function isClosureRequested(): bool
    {
        return $this->closure_requested_at !== null;
    }

    /**
     * `scheduled_deletion_at` is stamped once, at request time, from the
     * policy days in force that moment
     * (App\Actions\Organizations\RequestOrganizationClosure). It does not
     * move if the policy changes later.
     */
    public function isEligibleForDeletion(): bool
    {
        return $this->closure_requested_at !== null
            && $this->scheduled_deletion_at !== null
            && $this->scheduled_deletion_at->isPast();
    }

    /**
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
     */
    public function scopeClosureRequested($query)
    {
        return $query->whereNotNull('closure_requested_at');
    }

    /**
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
     */
    public function scopeEligibleForDeletion($query)
    {
        return $query->whereNotNull('closure_requested_at')
            ->where('scheduled_deletion_at', '<=', now());
    }
}
