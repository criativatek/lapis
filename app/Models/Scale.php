<?php

namespace App\Models;

use App\Support\Tenancy\CurrentOrganization;
use Database\Factories\ScaleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A grading scale. organization_id NULL = a shared system scale (1–5, 0–20, 0–100).
 *
 * Scale is a mixed entity — system scales belong to everyone, org scales to one
 * tenant — so it does NOT use the strict BelongsToOrganization scope, which would
 * hide system scales (and make $version->scale null for them). Its global scope
 * is "this organization's OR system", and it stamps organization_id on create
 * only for genuinely org-owned scales.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $organization_id
 * @property string $name
 * @property string $kind
 * @property string|null $min_value
 * @property string|null $max_value
 * @property Carbon|null $frozen_at
 */
#[Fillable(['name', 'kind', 'min_value', 'max_value'])]
class Scale extends Model
{
    /** @use HasFactory<ScaleFactory> */
    use HasFactory, HasUlids;

    protected static function boot(): void
    {
        parent::boot();
        // Visible: this organization's scales plus the shared system ones. Other
        // organizations' scales stay hidden, so isolation holds for owned scales.
        static::addGlobalScope('scaleVisibility', function (Builder $query): void {
            $tenant = app(CurrentOrganization::class);

            $query->where(function (Builder $query) use ($tenant): void {
                $query->whereNull($query->getModel()->qualifyColumn('organization_id'));

                if ($tenant->isResolved()) {
                    $query->orWhere($query->getModel()->qualifyColumn('organization_id'), $tenant->id());
                }
            });
        });

        static::creating(function (self $scale): void {
            // Stamp the tenant only when organization_id was not set at all. A
            // scale created explicitly with null (a system scale, seeded) stays null.
            if (! array_key_exists('organization_id', $scale->getAttributes())) {
                $scale->organization_id = app(CurrentOrganization::class)->id();
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
            'min_value' => 'decimal:3',
            'max_value' => 'decimal:3',
            'frozen_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ScaleLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(ScaleLevel::class)->orderBy('sequence');
    }

    /**
     * Whether a classification on this scale is CHOSEN from the scale's bands or
     * WRITTEN as a number on its interval.
     *
     * A 1–5 is a closed list: there is no 3,5 to assign, and offering a free
     * number invites one. A 0–20 or a percentage is an interval, and its own
     * limits are the definition of what is valid there.
     *
     * Read from the scale's kind, never from a year of schooling: «básico» and
     * «secundário» are not properties of anything this app stores.
     */
    public function classifiesByLevel(): bool
    {
        return $this->kind === 'level';
    }

    public function isSystem(): bool
    {
        return $this->organization_id === null;
    }

    public function isFrozen(): bool
    {
        return $this->frozen_at !== null;
    }
}
