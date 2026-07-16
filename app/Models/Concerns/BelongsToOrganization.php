<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the resolved organization, and stamps organization_id on create.
 *
 * The scope reads the tenant from the container. If none is resolved it throws
 * (see TenantNotResolvedException) rather than returning every organization's
 * rows. Queries that must legitimately cross organizations — institutional
 * aggregates, support tooling — have to say so explicitly with
 * withoutGlobalScope('organization') and are expected to be rare and reviewed.
 */
/**
 * @property int $organization_id
 *
 * @phpstan-require-extends Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $query): void {
            $query->where(
                $query->getModel()->qualifyColumn('organization_id'),
                app(CurrentOrganization::class)->id(),
            );
        });

        static::creating(function (self $model): void {
            $model->organization_id ??= app(CurrentOrganization::class)->id();
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
