<?php

namespace App\Models;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The teacher's own names for instrument types — configurable, never hard-coded
 * (§12.1). Like Scale, it is a mixed entity: organization_id NULL is a shared
 * system type, so it uses a "mine OR system" scope rather than the strict one.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $organization_id
 * @property string $name
 * @property string $code
 * @property string $default_purpose
 * @property bool $is_active
 */
#[Fillable(['name', 'code', 'default_purpose', 'is_active'])]
class InstrumentType extends Model
{
    use HasUlids;

    protected static function booted(): void
    {
        static::addGlobalScope('typeVisibility', function (Builder $query): void {
            $tenant = app(CurrentOrganization::class);

            $query->where(function (Builder $query) use ($tenant): void {
                $query->whereNull($query->getModel()->qualifyColumn('organization_id'));

                if ($tenant->isResolved()) {
                    $query->orWhere($query->getModel()->qualifyColumn('organization_id'), $tenant->id());
                }
            });
        });

        static::creating(function (self $type): void {
            if (! array_key_exists('organization_id', $type->getAttributes())) {
                $type->organization_id = app(CurrentOrganization::class)->id();
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
            'is_active' => 'boolean',
        ];
    }

    public function isSystem(): bool
    {
        return $this->organization_id === null;
    }
}
