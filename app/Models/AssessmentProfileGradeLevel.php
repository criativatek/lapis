<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One grade level a profile covers (§10 multi-grade support). A pure detail
 * row of its profile — no ULID of its own, since it is never addressed
 * directly by a URL.
 *
 * @property int $id
 * @property int $assessment_profile_id
 * @property string $grade_level
 */
#[Fillable(['grade_level'])]
class AssessmentProfileGradeLevel extends Model
{
    /**
     * @return BelongsTo<AssessmentProfile, $this>
     */
    public function assessmentProfile(): BelongsTo
    {
        return $this->belongsTo(AssessmentProfile::class);
    }
}
