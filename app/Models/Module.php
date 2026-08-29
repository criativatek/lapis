<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A gateable capability. Not tenant-owned — the catalogue is the same for everyone.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 */
#[Fillable(['key', 'name'])]
class Module extends Model
{
    /**
     * The plan VERSIONS this capability is composed into — never «the plans»,
     * since ADR-0008. A module belongs to an offer published at a moment, and
     * «which plans carry it» is a question about one version of each (usually
     * the current one, which is what `Plan::currentVersion()` answers).
     *
     * @return BelongsToMany<PlanVersion, $this>
     */
    public function planVersions(): BelongsToMany
    {
        return $this->belongsToMany(PlanVersion::class, 'module_plan_version');
    }
}
