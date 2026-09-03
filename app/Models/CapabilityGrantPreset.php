<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $notes
 * @property int|null $default_duration_days
 * @property bool $active
 */
#[Fillable(['key', 'name', 'notes', 'default_duration_days', 'active', 'created_by'])]
class CapabilityGrantPreset extends Model
{
    protected function casts(): array
    {
        return ['default_duration_days' => 'integer', 'active' => 'boolean'];
    }

    /** @return BelongsToMany<Module, $this> */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'capability_grant_preset_module');
    }
}
