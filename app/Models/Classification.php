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
 * final_* columns are the teacher's. The calculation_snapshot records HOW the
 * proposal was reached.
 *
 * THE TWO PAIRS ARE NOT THE SAME KIND OF THING, and this is worth stating
 * because reading them as if they were is what once put a percentage in a column
 * headed with the grade:
 *
 *  - `proposed_normalized_value` / `proposed_value` — the engine's percentage,
 *    raw and rounded. A technical figure, and what the staleness check compares.
 *  - `proposed_scale_level_id` — the same proposal read on the profile's scale.
 *  - `final_scale_level_id` / `final_value` — the DECISION, always expressed on
 *    the classification scale: the level on a scale made of levels (with
 *    `final_value` carrying that level's own number), the value itself on a
 *    scale that is an interval.
 *
 * A decision that differs from the proposal is ordinary and needs no reason;
 * `override_reason` is a pedagogical note the teacher may leave, and
 * `overridden_by`/`overridden_at` are what record that a decision differed.
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
     * True once the teacher decided something other than the proposal.
     *
     * Read from the stamp that records exactly that, and no longer from the
     * presence of a reason: a reason is now an optional note, and one left on a
     * decision that matched the proposal would have made this say the opposite
     * of what happened.
     */
    public function wasOverridden(): bool
    {
        return $this->overridden_at !== null;
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
