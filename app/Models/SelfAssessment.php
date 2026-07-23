<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A student's self-assessment for one period (§15). Never part of the
 * calculation: it is compared with the grade, not summed into it.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $enrollment_id
 * @property int $academic_period_id
 * @property int $self_assessment_template_id
 * @property SelfAssessmentStatus $status
 * @property SelfAssessmentFilledBy $filled_by
 * @property string|null $reflection
 * @property Carbon|null $submitted_at
 * @property Carbon|null $reviewed_at
 * @property int|null $reviewed_by
 */
#[Fillable([
    'enrollment_id', 'academic_period_id', 'self_assessment_template_id',
    'status', 'filled_by', 'reflection', 'submitted_at', 'reviewed_at', 'reviewed_by',
])]
class SelfAssessment extends Model
{
    use BelongsToOrganization, HasUlids;

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
            'status' => SelfAssessmentStatus::class,
            'filled_by' => SelfAssessmentFilledBy::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return HasMany<SelfAssessmentResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(SelfAssessmentResponse::class);
    }
}
