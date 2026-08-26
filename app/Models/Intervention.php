<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Interventions\InterventionLegalFramework;
use App\Support\Interventions\LegalMapping;
use App\Support\Interventions\PedagogicalText;
use Carbon\CarbonInterface;
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
 * @property InterventionPurpose|null $purpose
 * @property string|null $motive_code
 * @property string|null $motive_label
 * @property string|null $strategy_code
 * @property string|null $strategy_label
 * @property string|null $objective
 * @property Carbon|null $review_on
 * @property string|null $frequency
 * @property string|null $tracking_indicator
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
    'target_type', 'intervention_type', 'purpose', 'domain_relation',
    'motive_code', 'motive_label', 'strategy_code', 'strategy_label', 'objective',
    'title', 'description', 'description_source',
    'status', 'started_on', 'expected_end_on', 'review_on', 'concluded_on',
    'frequency', 'tracking_indicator',
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
            'purpose' => InterventionPurpose::class,
            'domain_relation' => InterventionDomainRelation::class,
            'description_source' => InterventionDescriptionSource::class,
            'status' => InterventionStatus::class,
            'started_on' => 'date',
            'expected_end_on' => 'date',
            'review_on' => 'date',
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
     * Who registered it — meaningful once a class has more than one teacher.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The follow-ups, newest first.
     *
     * A HISTORY, NOT A FIELD. Each one is what was observed on a day, and a
     * later one never replaces an earlier one — «continua a precisar de apoio na
     * revisão» in March and «maior autonomia» in April are both true, of
     * different days, and an intervention that kept only the latest would have
     * thrown away the part that shows movement (§23, §28, §97).
     *
     * @return HasMany<InterventionReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(InterventionReview::class)
            ->orderByDesc('reviewed_on')
            ->orderByDesc('id');
    }

    /**
     * The appraisal that stands right now: the most recent follow-up that made
     * one.
     *
     * DERIVED, NEVER STORED (§59). A second column holding «the current rating»
     * is a second answer to a question the follow-ups already answer, and the
     * two would disagree the first time somebody corrected an old note. A
     * follow-up that only observed and did not judge is skipped rather than
     * treated as «no longer rated».
     */
    public function currentEffectiveness(): ?InterventionEffectiveness
    {
        foreach ($this->reviews as $review) {
            if ($review->effectiveness !== null) {
                return $review->effectiveness;
            }
        }

        return null;
    }

    /**
     * Whether the teacher's own review date has arrived on something still open.
     *
     * THE DATE IS THE TEACHER'S AND THE ONLY SOURCE OF PENDENCY. There is no
     * «thirty days without a follow-up» rule anywhere in this module: an
     * intervention that has run quietly since October is not neglected, and a
     * number nobody chose would put a badge on it saying otherwise (§22, §37).
     *
     * A concluded, suspended or cancelled intervention is never pending — its
     * review date is history like the rest of it.
     */
    public function needsReview(?CarbonInterface $on = null): bool
    {
        if ($this->review_on === null || ! $this->status->isOpen()) {
            return false;
        }

        return ! $this->review_on->isAfter(($on ?? Carbon::now())->startOfDay());
    }

    /**
     * Interventions whose review date has come and gone, and that are still
     * running.
     *
     * @param  Builder<Intervention>  $query
     */
    public function scopeNeedingReview(Builder $query, ?CarbonInterface $on = null): void
    {
        $query
            ->whereNotNull('review_on')
            ->whereDate('review_on', '<=', ($on ?? Carbon::now())->startOfDay())
            ->whereIn('status', [InterventionStatus::New->value, InterventionStatus::InProgress->value]);
    }

    /**
     * How this intervention should be named on a screen, or NULL when it has no
     * name of its own.
     *
     * THE STRATEGY FIRST, because that is what the teacher did and what they
     * will recognise in a list. The catalogue type is a classification and the
     * title is what older rows have; each is used only when the one before it
     * has nothing to say.
     *
     * EVERY CANDIDATE GOES THROUGH PedagogicalText. A stored title is not
     * automatically content: one real row carries «Legado sem dominio», written
     * by an old process to fill a NOT NULL column, and putting it after a
     * student's name reads as a category somebody chose. When nothing survives,
     * the answer is null — the row is named by its participants and its date,
     * which is all it ever actually said (§1, §3).
     */
    public function pedagogicalTitle(): ?string
    {
        foreach ([$this->strategy_label, $this->title, $this->intervention_type?->label()] as $candidate) {
            $meaningful = PedagogicalText::meaningful($candidate);

            if ($meaningful !== null) {
                return $meaningful;
            }
        }

        return null;
    }

    /**
     * The same name, for the places that need a string rather than a gap — an
     * audit summary, a log line. A screen uses pedagogicalTitle() and renders
     * nothing when it is null.
     */
    public function displayTitle(): string
    {
        return $this->pedagogicalTitle() ?? __('Intervenção');
    }

    /**
     * What to write about domains in a compact list, or nothing.
     *
     * «Sem domínio específico» is a truthful sentence and still the wrong thing
     * to print: in a list it occupies the place a domain name would, and reads
     * as one. «Todos os domínios» is different — it is a statement the teacher
     * made, and it stays (§5).
     */
    public function domainLabel(): ?string
    {
        if ($this->domain_relation === InterventionDomainRelation::All) {
            return __('Todos os domínios');
        }

        return $this->domain_relation === InterventionDomainRelation::Specific
            ? $this->domain?->name
            : null;
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
