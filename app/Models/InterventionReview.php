<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A periodic appraisal of an intervention's effect (§14). A long intervention is
 * reviewed several times, so these are rows, not a single column.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $intervention_id
 * @property Carbon $reviewed_on
 * @property InterventionEffectiveness|null $effectiveness
 * @property string|null $notes
 * @property int|null $reviewed_by
 */
#[Fillable(['intervention_id', 'reviewed_on', 'effectiveness', 'notes', 'reviewed_by'])]
class InterventionReview extends Model
{
    use BelongsToOrganization, HasUlids;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'reviewed_on' => 'date',
            'effectiveness' => InterventionEffectiveness::class,
        ];
    }
}
