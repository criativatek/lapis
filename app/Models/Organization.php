<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
}
