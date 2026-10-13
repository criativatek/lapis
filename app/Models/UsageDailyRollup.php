<?php

namespace App\Models;

use App\Support\Usage\UsageFeature;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Agregado diário por utilizador, organização e funcionalidade. Reconstruído
 * por `usage:aggregate` a partir de `usage_events` — nunca escrito à mão.
 *
 * Sem `BelongsToOrganization`, pela razão que `UsageEvent` explica.
 *
 * @property int $id
 * @property Carbon $day
 * @property int $user_id
 * @property int $organization_id
 * @property UsageFeature $feature
 * @property int $views
 * @property int $actions
 * @property int $useful_actions
 * @property int $items
 * @property int $items_created
 * @property int $items_changed
 * @property int $items_unchanged
 * @property int $failures
 */
#[Fillable([
    'day', 'user_id', 'organization_id', 'feature', 'views', 'actions', 'useful_actions', 'items', 'items_created', 'items_changed', 'items_unchanged', 'failures',
])]
class UsageDailyRollup extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'date',
            'feature' => UsageFeature::class,
            'views' => 'integer',
            'actions' => 'integer',
            'useful_actions' => 'integer',
            'items' => 'integer',
            'items_created' => 'integer',
            'items_changed' => 'integer',
            'items_unchanged' => 'integer',
            'failures' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
