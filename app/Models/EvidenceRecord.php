<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One logbook entry (§14). Qualitative, never a grade — no path into the
 * calculation. Tied to a class, optionally to one student and one domain.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_id
 * @property int|null $enrollment_id
 * @property int|null $academic_period_id
 * @property int|null $domain_id
 * @property int|null $quick_rating_scale_level_id
 * @property Carbon $occurred_at
 * @property EvidenceKind $kind
 * @property HomeworkStatus|null $homework_status
 * @property ParticipationLevel|null $participation_level
 * @property ActivityEvaluation|null $activity_evaluation
 * @property bool|null $activity_include_in_report
 * @property DisciplinarySeverity|null $disciplinary_severity
 * @property string $description
 * @property int|null $created_by
 */
#[Fillable([
    'class_id', 'enrollment_id', 'academic_period_id', 'domain_id',
    'quick_rating_scale_level_id', 'occurred_at', 'kind', 'disciplinary_severity',
    'homework_status', 'participation_level', 'activity_evaluation', 'activity_include_in_report',
    'description', 'created_by',
])]
class EvidenceRecord extends Model
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
            'occurred_at' => 'datetime',
            'kind' => EvidenceKind::class,
            'disciplinary_severity' => DisciplinarySeverity::class,
            'homework_status' => HomeworkStatus::class,
            'participation_level' => ParticipationLevel::class,
            'activity_evaluation' => ActivityEvaluation::class,
            'activity_include_in_report' => 'boolean',
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
     * @param  Builder<EvidenceRecord>  $query
     * @return Builder<EvidenceRecord>
     */
    public function scopeForClass(Builder $query, int $classId): Builder
    {
        return $query->where('class_id', $classId);
    }

    /**
     * Null keeps every record (student-specific and class-wide alike) — a
     * "no filter" default, never an accidental "class-wide only" filter.
     *
     * @param  Builder<EvidenceRecord>  $query
     * @return Builder<EvidenceRecord>
     */
    public function scopeForEnrollmentOrWholeClass(Builder $query, ?int $enrollmentId): Builder
    {
        if ($enrollmentId === null) {
            return $query;
        }

        return $query->where('enrollment_id', $enrollmentId);
    }

    /**
     * @param  Builder<EvidenceRecord>  $query
     * @return Builder<EvidenceRecord>
     */
    public function scopeInPeriod(Builder $query, AcademicPeriod $period): Builder
    {
        return $query->whereBetween('occurred_at', [$period->starts_on, $period->ends_on]);
    }

    /**
     * Resolves the group's member kinds from EvidenceKind::group() itself —
     * one place decides what belongs to a group, this just asks it.
     *
     * @param  Builder<EvidenceRecord>  $query
     * @return Builder<EvidenceRecord>
     */
    public function scopeInGroup(Builder $query, EvidenceInternalGroup $group): Builder
    {
        $kinds = array_values(array_filter(
            EvidenceKind::cases(),
            fn (EvidenceKind $kind) => $kind->group() === $group,
        ));

        return $query->whereIn('kind', array_map(fn (EvidenceKind $kind) => $kind->value, $kinds));
    }

    /**
     * Which records a future report-preparation screen could pre-select
     * without the teacher's say-so (§12): today that is exactly Activity
     * records explicitly flagged "Incluir no relatório = Sim" — never
     * Contact/Note/Support, however their fields happen to be set, and never
     * an Activity left at "Não" or unset.
     *
     * @param  Builder<EvidenceRecord>  $query
     * @return Builder<EvidenceRecord>
     */
    public function scopeAutoSelectableForReport(Builder $query): Builder
    {
        return $query->where('kind', EvidenceKind::Activity->value)->where('activity_include_in_report', true);
    }
}
