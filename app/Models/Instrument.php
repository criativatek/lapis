<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\InstrumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An assessment instrument — a test, a question-aula, an oral observation (§12).
 *
 * Three independent axes, by requirement (§4.1, menus §6):
 *  - purpose: a pedagogical LABEL. The engine never reads it.
 *  - counts_toward_classification: the ONLY gate into the calculation.
 *  - weight: how much it counts, independent of both.
 * A diagnostic instrument may still count if the teacher decides so.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_id
 * @property int $academic_period_id
 * @property int $instrument_type_id
 * @property string $title
 * @property Carbon $applied_on
 * @property InstrumentStatus $status
 * @property bool $counts_toward_classification
 * @property string $purpose
 * @property string|null $total_points
 * @property string|null $weight
 * @property bool $allow_bonus
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 */
#[Fillable([
    'class_id', 'academic_period_id', 'instrument_type_id', 'title', 'applied_on',
    'status', 'counts_toward_classification', 'purpose', 'total_points', 'scale_id',
    'weight', 'allow_bonus', 'internal_notes', 'status_before_cancellation',
    'cancelled_at', 'cancelled_by', 'cancellation_reason',
    'completed_at', 'completed_by',
])]
class Instrument extends Model
{
    /** @use HasFactory<InstrumentFactory> */
    use BelongsToOrganization, HasFactory, HasUlids, SoftDeletes;

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
            'applied_on' => 'date',
            'status' => InstrumentStatus::class,
            'counts_toward_classification' => 'boolean',
            'total_points' => 'decimal:4',
            'weight' => 'decimal:4',
            'allow_bonus' => 'boolean',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<InstrumentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InstrumentItem::class)->orderBy('sequence');
    }

    /**
     * The instrument's sections. Every instrument has at least one — an
     * unnamed, implicit group — so a simple test needs no structure from the
     * teacher and shows none.
     *
     * @return HasMany<InstrumentGroup, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(InstrumentGroup::class)->orderBy('sequence');
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<AcademicPeriod, $this>
     */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    /**
     * @return BelongsTo<InstrumentType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(InstrumentType::class, 'instrument_type_id');
    }

    /**
     * The single gate into the calculation: it must be flagged as counting AND
     * be in a state the engine reads (§4.1).
     */
    public function entersCalculation(): bool
    {
        return $this->counts_toward_classification && $this->status->entersCalculation();
    }

    /**
     * The sum of the item points, excluding bonus items (which do not enter the
     * denominator). Used to check the total against the declared cotação (§12.3).
     *
     * Formatted to the column's 4 decimals rather than returned raw: SQLite gives
     * "100" where MySQL gives "100.0000" for the same sum, and a grade path must
     * not behave differently per engine.
     */
    public function itemPointsTotal(): string
    {
        return number_format(
            (float) $this->items()->where('is_bonus', false)->sum('points_possible'),
            4,
            '.',
            '',
        );
    }
}
