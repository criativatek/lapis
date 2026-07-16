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
     * @return BelongsToMany<Plan, $this>
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class);
    }
}
