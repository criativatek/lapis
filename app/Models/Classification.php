<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One decision about one (enrollment, period, scope) — the boundary where the
 * system's proposal becomes the teacher's grade (§3.3, §7.1). The proposed_*
 * columns are the deterministic engine output and are never overwritten; the
 * final_* columns are the teacher's, and may differ only with an override_reason
 * (enforced by DB CHECK on MySQL and by confirm() at the service layer). The
 * calculation_snapshot records HOW the proposal was reached.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $enrollment_id
 * @property int $academic_period_id
 * @property ClassificationScope $scope
 * @property int $assessment_profile_version_id
 * @property int|null $calculation_snapshot_id
 * @property ClassificationStatus $status
 * @property string|null $proposed_normalized_value
 * @property string|null $proposed_value
 * @property int|null $proposed_scale_level_id
 * @property string|null $final_value
 * @property int|null $final_scale_level_id
 * @property string|null $override_reason
 * @property int|null $overridden_by
 * @property Carbon|null $overridden_at
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $published_at
 * @property int|null $superseded_by_id
 * @property int $lock_version
 */
#[Fillable([
    'enrollment_id', 'academic_period_id', 'scope', 'assessment_profile_version_id',
    'calculation_snapshot_id', 'status',
    'proposed_normalized_value', 'proposed_value', 'proposed_scale_level_id',
    'final_value', 'final_scale_level_id', 'override_reason', 'overridden_by', 'overridden_at',
    'confirmed_by', 'confirmed_at', 'published_at', 'superseded_by_id',
])]
class Classification extends Model
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
            'scope' => ClassificationScope::class,
            'status' => ClassificationStatus::class,
            'proposed_normalized_value' => 'decimal:6',
            'proposed_value' => 'decimal:3',
            'final_value' => 'decimal:3',
            'overridden_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * The value that counts: the teacher's final if set, else the proposal. Read
     * only — the grade is written through confirm()/override, never assigned here.
     */
    public function effectiveValue(): ?string
    {
        return $this->final_value ?? $this->proposed_value;
    }

    /** True once the teacher changed the deterministic proposal (A10). */
    public function wasOverridden(): bool
    {
        return $this->override_reason !== null;
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<AcademicPeriod, $this>
     */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    /**
     * What LÁPIS proposed. A conclusion the system drew, and never the record.
     *
     * @return BelongsTo<ScaleLevel, $this>
     */
    public function proposedScaleLevel(): BelongsTo
    {
        return $this->belongsTo(ScaleLevel::class, 'proposed_scale_level_id');
    }

    /**
     * What the teacher decided. THE record — set only by an explicit act, never
     * copied here from the proposal by anything that merely reads (§3.3).
     *
     * @return BelongsTo<ScaleLevel, $this>
     */
    public function finalScaleLevel(): BelongsTo
    {
        return $this->belongsTo(ScaleLevel::class, 'final_scale_level_id');
    }

    /**
     * @return BelongsTo<CalculationSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(CalculationSnapshot::class, 'calculation_snapshot_id');
    }
}
