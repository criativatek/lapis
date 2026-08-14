<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Interventions\InterventionLegalFramework;
use App\Support\Interventions\LegalMapping;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A deliberate pedagogical action taken in response to a need, a difficulty, a
 * goal or a context (§14) — one-off or ongoing, for a student, a group or a
 * whole class, optionally framed pedagogically/legally and optionally appraised
 * for effectiveness afterwards.
 *
 * An intervention is what the teacher DID. That is what separates it from an
 * EvidenceRecord, which is what the teacher OBSERVED. Neither ever enters the
 * calculation: no weight, no points, no FK into results (§14.3). If a support
 * action must ever influence a grade, the route is an instrument with
 * `counts_toward_classification` — explicit and weighted, never a side effect.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_id
 * @property int|null $enrollment_id
 * @property int|null $academic_period_id
 * @property int|null $domain_id
 * @property InterventionTargetType $target_type
 * @property InterventionType|null $intervention_type
 * @property InterventionDomainRelation $domain_relation
 * @property string $title
 * @property string|null $description
 * @property InterventionDescriptionSource $description_source
 * @property InterventionStatus $status
 * @property Carbon $started_on
 * @property Carbon|null $expected_end_on
 * @property Carbon|null $concluded_on
 * @property bool $include_in_report
 * @property bool $available_for_reports
 * @property SupportMeasureLevel|null $support_measure_level
 * @property SupportMeasureCode|null $support_measure_code
 * @property EvaluationAdaptationCode|null $evaluation_adaptation_code
 * @property LegalMappingSource|null $legal_mapping_source
 * @property int $created_by
 */
#[Fillable([
    'class_id', 'enrollment_id', 'academic_period_id', 'domain_id',
    'target_type', 'intervention_type', 'domain_relation',
    'title', 'description', 'description_source',
    'status', 'started_on', 'expected_end_on', 'concluded_on',
    'include_in_report', 'available_for_reports',
    'support_measure_level', 'support_measure_code', 'evaluation_adaptation_code',
    'legal_mapping_source', 'created_by',
])]
class Intervention extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

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
            'target_type' => InterventionTargetType::class,
            'intervention_type' => InterventionType::class,
            'domain_relation' => InterventionDomainRelation::class,
            'description_source' => InterventionDescriptionSource::class,
            'status' => InterventionStatus::class,
            'started_on' => 'date',
            'expected_end_on' => 'date',
            'concluded_on' => 'date',
            'include_in_report' => 'boolean',
            'available_for_reports' => 'boolean',
            'support_measure_level' => SupportMeasureLevel::class,
            'support_measure_code' => SupportMeasureCode::class,
            'evaluation_adaptation_code' => EvaluationAdaptationCode::class,
            'legal_mapping_source' => LegalMappingSource::class,
        ];
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * The canonical list of participants. A student intervention has one, a
     * group two or more, a class-wide one none — the class itself is the
     * target, so naming every enrollment would only rot as students join or
     * leave.
     *
     * @return BelongsToMany<Enrollment, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Enrollment::class, 'intervention_enrollment')->withTimestamps();
    }

    /**
     * The single-student column kept from the original schema. Still written
     * for target_type = student so pre-existing code and rows keep working;
     * `participants()` is what new code should read.
     *
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return HasMany<InterventionReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(InterventionReview::class)->orderByDesc('reviewed_on');
    }

    /**
     * The transversal area, derived from the catalogue rather than stored, so
     * reclassifying a type updates every intervention consistently instead of
     * leaving old rows behind (§6). Null only for rows created before the
     * catalogue existed, which carry a free-text title instead.
     */
    public function context(): ?InterventionContext
    {
        return $this->intervention_type?->context();
    }

    /**
     * What a framework would propose for this intervention's type — the
     * suggestion, not the decision. What was actually decided lives in the
     * support_measure_* / evaluation_adaptation_code columns and is never
     * recomputed, so changing law cannot rewrite history.
     *
     * The framework is passed in rather than looked up here: which one applies
     * depends on the organization's jurisdiction and on this intervention's own
     * `started_on`, and the model has no business resolving either.
     */
    public function catalogueLegalMapping(InterventionLegalFramework $framework): ?LegalMapping
    {
        return $this->intervention_type === null ? null : $framework->mappingFor($this->intervention_type);
    }

    /**
     * Whether a legal framing was actually settled on this intervention. A
     * contextual suggestion the teacher never confirmed is not stored at all,
     * so it can never make this true (§12.2).
     */
    public function hasConfirmedLegalFraming(): bool
    {
        return $this->legal_mapping_source !== null;
    }

    /**
     * @param  Builder<Intervention>  $query
     */
    public function scopeForClass(Builder $query, int $classId): void
    {
        $query->where('class_id', $classId);
    }

    /**
     * Interventions that reach a given student: the ones naming them as a
     * participant, plus every class-wide intervention of their class — a
     * whole-class action reached them too, even though it names nobody.
     *
     * @param  Builder<Intervention>  $query
     */
    public function scopeForEnrollment(Builder $query, int $enrollmentId): void
    {
        $query->where(function (Builder $inner) use ($enrollmentId): void {
            $inner
                ->whereHas('participants', fn (Builder $participants) => $participants->whereKey($enrollmentId))
                ->orWhere('target_type', InterventionTargetType::SchoolClass);
        });
    }

    /**
     * @param  Builder<Intervention>  $query
     */
    public function scopeInPeriod(Builder $query, AcademicPeriod $period): void
    {
        $query->whereBetween('started_on', [$period->starts_on, $period->ends_on]);
    }

    /**
     * Interventions whose type belongs to a context. Resolved through
     * InterventionType::context() rather than a stored column, so the enum
     * stays the only place the classification is written down (§7).
     *
     * @param  Builder<Intervention>  $query
     */
    public function scopeByContext(Builder $query, InterventionContext $context): void
    {
        $types = array_values(array_filter(
            InterventionType::cases(),
            fn (InterventionType $type) => $type->context() === $context,
        ));

        $query->whereIn('intervention_type', array_column($types, 'value'));
    }

    /**
     * @param  Builder<Intervention>  $query
     */
    public function scopeByDomain(Builder $query, int $domainId): void
    {
        $query->where('domain_id', $domainId);
    }

    /**
     * The interventions a future report may draw on. Elegibility only: nothing
     * here copies text into a report, and Relatórios is untouched by this
     * delivery (§9, §23).
     *
     * @param  Builder<Intervention>  $query
     */
    public function scopeAvailableForReports(Builder $query): void
    {
        $query->where('available_for_reports', true);
    }

    /**
     * Only interventions whose legal framing was actually settled — a
     * suggestion never reaches this scope, because it is never stored.
     *
     * @param  Builder<Intervention>  $query
     */
    public function scopeBySupportMeasureLevel(Builder $query, SupportMeasureLevel $level): void
    {
        $query->where('support_measure_level', $level)->whereNotNull('legal_mapping_source');
    }
}
