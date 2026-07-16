<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A commercial plan: Base, Pro or Institucional.
 *
 * Which modules a plan carries lives in plan_module, not in code, so the
 * composition can change without a deploy (§4.3).
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property array<string, mixed>|null $limits
 * @property int $sort_order
 */
#[Fillable(['key', 'name', 'limits', 'sort_order'])]
class Plan extends Model
{
    protected function casts(): array
    {
        return [
            'limits' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<Module, $this>
     */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class);
    }
}
