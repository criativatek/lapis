<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Services\Assessment\InstrumentEligibility;
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
 * Three axes (§4.1, menus §6), no longer fully independent:
 *  - purpose: a pedagogical label for most values — EXCEPT `diagnostic`,
 *    which is load-bearing: a diagnostic instrument NEVER counts toward the
 *    classification, whatever counts_toward_classification stores (R2).
 *  - counts_toward_classification: what the teacher configured, subject to
 *    the diagnostic override above.
 *  - weight: how much it counts, once it does.
 * Whether an instrument actually contributes to averages/classifications —
 * configuration AND status together — is decided in ONE place:
 * App\Services\Assessment\InstrumentEligibility. See that class for the full
 * rule (including the status gate and its retroactivity switch).
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
     * The server-side guarantee for R2: a diagnostic instrument NEVER
     * persists as counting toward the classification, whatever the caller
     * sent. `saving` runs on every write path — create, update, the
     * correction-grid import, the assessment-data import, and a backup
     * restore's forceFill()+save() — so none of them can bypass this by not
     * going through InstrumentBuilder. It only acts on writes; existing rows
     * are not migrated by this hook.
     */
    protected static function booted(): void
    {
        static::saving(function (self $instrument): void {
            if ($instrument->purpose === InstrumentEligibility::DIAGNOSTIC) {
                $instrument->counts_toward_classification = false;
            }
        });
    }

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
     * The single gate into the calculation. Delegates to InstrumentEligibility
     * — the one place that resolves configuration, the diagnostic override
     * (R2) and the status gate (R1, with its retroactivity switch) together.
     */
    public function entersCalculation(): bool
    {
        return app(InstrumentEligibility::class)->contributesToAverages($this);
    }

    /**
     * The sum of the item points, excluding bonus items (which do not enter the
     * denominator). Used to check the total against the declared cotação (§12.3).
     *
     * Formatted to the column's 4 decimals rather than returned raw: SQLite gives
     * "100" where MySQL gives "100.0000" for the same sum, and a grade path must
     * not behave differently per engine.
     */
    public function itemPointsTotal(): ?string
    {
        if ($this->items()->where('is_bonus', false)->whereNull('points_possible')->exists()) {
            return null;
        }

        return number_format(
            (float) $this->items()->where('is_bonus', false)->sum('points_possible'),
            4,
            '.',
            '',
        );
    }
}
