<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Assessment\EmptyIsNotZeroException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One student's score on one item — the highest-volume table in the system.
 *
 * "Empty is never zero" (§12.4) is structural here: points_earned is NULL unless
 * result_state is `assessed`. Enforced twice — by CHECK constraints on MySQL and
 * by the guard below, which also runs on SQLite so the rule is covered by the
 * local test suite and produces a readable error instead of a driver exception.
 *
 * The absence of a row is meaningful and legitimate: it means `pending`. The grid
 * does not pre-create empty cells.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $instrument_id
 * @property int $instrument_item_id
 * @property int $enrollment_id
 * @property ResultState $result_state
 * @property string|null $points_earned
 * @property int|null $scale_level_id
 * @property string|null $state_reason
 * @property Carbon|null $assessed_at
 * @property int|null $assessed_by
 */
#[Fillable([
    'instrument_id', 'instrument_item_id', 'enrollment_id', 'result_state',
    'points_earned', 'scale_level_id', 'state_reason', 'assessed_at', 'assessed_by',
])]
class StudentItemScore extends Model
{
    use BelongsToOrganization;

    protected static function booted(): void
    {
        static::saving(function (self $score): void {
            // The enum cast means this is always a ResultState, whether the caller
            // passed the enum or its string value.
            $state = $score->result_state;

            // A number may only exist on an assessed score. Writing 0 for an
            // absent student is the exact bug this refuses.
            if (! $state->carriesValue() && $score->points_earned !== null) {
                throw EmptyIsNotZeroException::forState($state);
            }

            // Conversely, an assessed score must actually carry something.
            if ($state->carriesValue() && $score->points_earned === null && $score->scale_level_id === null) {
                throw EmptyIsNotZeroException::assessedWithoutValue();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'result_state' => ResultState::class,
            'points_earned' => 'decimal:4',
            'assessed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InstrumentItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InstrumentItem::class, 'instrument_item_id');
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
