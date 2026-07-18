<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The periods a version applies to, and how each aggregates. This is where
 * "O 2.º semestre é cumulativo" becomes data (is_cumulative) — a versioned
 * pedagogical rule, not a property of the period itself.
 *
 * @property int $id
 * @property int $assessment_profile_version_id
 * @property int $academic_period_id
 * @property bool $is_cumulative
 * @property string|null $period_weight_percent
 * @property bool $contributes_to_accumulated
 */
#[Fillable(['assessment_profile_version_id', 'academic_period_id', 'is_cumulative', 'period_weight_percent', 'contributes_to_accumulated'])]
class ProfileVersionPeriod extends Model
{
    protected function casts(): array
    {
        return [
            'is_cumulative' => 'boolean',
            'period_weight_percent' => 'decimal:4',
            'contributes_to_accumulated' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AcademicPeriod, $this>
     */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }
}
