<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\SchoolClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A class (turma). Named SchoolClass because Class is reserved; the table is
 * `classes`. Assessed by a specific active profile version (§11.1).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $academic_year_id
 * @property int $subject_id
 * @property string|null $grade_level
 * @property string $label
 * @property bool $is_support_class
 * @property int|null $assessment_profile_version_id
 * @property ClassStatus $status
 * @property bool $include_evidence_in_report
 * @property Carbon|null $archived_at
 */
#[Fillable(['academic_year_id', 'subject_id', 'grade_level', 'course_code', 'label', 'is_support_class', 'assessment_profile_version_id', 'status', 'include_evidence_in_report', 'archived_at'])]
class SchoolClass extends Model
{
    /** @use HasFactory<SchoolClassFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

    protected $table = 'classes';

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
            'status' => ClassStatus::class,
            'is_support_class' => 'boolean',
            'include_evidence_in_report' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Derived rather than stored: teacher pivots are the source of truth, so
     * reassignment state cannot drift and needs no risky migration.
     *
     * @param  Builder<SchoolClass>  $query
     * @return Builder<SchoolClass>
     */
    public function scopeNeedingReassignment(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('teachers')
            ->whereIn('status', [ClassStatus::Preparation, ClassStatus::Active]);
    }

    /**
     * Turmas that count towards the `active_classes` quota (§Lote 3):
     * `Preparation` and `Active` together — the same "still open" cluster
     * `scopeNeedingReassignment()` above already groups, which is the
     * precedent this scope reuses rather than inventing a second grouping.
     * `Closed`/`Archived` never count, though no code path reaches either
     * status yet — both are reserved for a future archive workflow.
     *
     * @param  Builder<SchoolClass>  $query
     * @return Builder<SchoolClass>
     */
    public function scopeCountingTowardsLimit(Builder $query): Builder
    {
        return $query->whereIn('status', [ClassStatus::Preparation, ClassStatus::Active]);
    }

    /**
     * The turmas one teacher actually teaches (§23), via the class_teachers
     * pivot and on top of this model's own organization scope — never a
     * colleague's turma, never another organization's.
     *
     * Lives on the model rather than in a controller because more than one
     * entry point now asks the same question: «Turmas» and «Configurar
     * horários» (ClassController) and «Horário do Professor»
     * (TeacherTimetableController), which must all agree about whose turmas
     * they are looking at.
     *
     * @param  Builder<SchoolClass>  $query
     * @return Builder<SchoolClass>
     */
    public function scopeTaughtBy(Builder $query, User $teacher): Builder
    {
        return $query->whereHas('teachers', fn ($teachers) => $teachers->whereKey($teacher->getKey()));
    }

    /**
     * As turmas que continuam nas listas por omissão — não arquivadas.
     *
     * NÃO SE CHAMA `scopeActive()`, de propósito. Já existe `ClassStatus::Active`
     * neste modelo, e um `scopeActive()` aqui ao lado leria como o mesmo
     * conceito — quando são dois eixos ortogonais: uma turma `status = Active`
     * pode estar arquivada ao mesmo tempo (encerrada há dois anos, mas ainda
     * dentro dos três anos de retenção), e o inverso também é possível.
     *
     * @param  Builder<SchoolClass>  $query
     * @return Builder<SchoolClass>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<SchoolClass>  $query
     * @return Builder<SchoolClass>
     */
    public function scopeArchivedOnly(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * A partir de quando esta turma pode ser eliminada em definitivo — três
     * anos depois do FIM DO ANO LETIVO, e só depois disso.
     *
     * A BASE É SEMPRE `academic_year.ends_on`, NUNCA `created_at`, `archived_at`
     * NEM QUALQUER DATA DE ÚLTIMO ACESSO. É a única data com significado legal
     * para retenção de dados escolares — quando arquivar ou quando alguém
     * voltou a abrir a turma não altera esse prazo em nada.
     */
    public function eligibleForPermanentDeletionAt(): ?CarbonImmutable
    {
        if (! $this->isArchived()) {
            return null;
        }

        return CarbonImmutable::instance($this->academicYear->ends_on)->addYears(3);
    }

    public function isEligibleForPermanentDeletion(): bool
    {
        if (! $this->isArchived()) {
            return false;
        }

        $eligibleAt = $this->eligibleForPermanentDeletionAt();

        return $eligibleAt !== null
            && CarbonImmutable::now('Europe/Lisbon')->startOfDay()->greaterThanOrEqualTo($eligibleAt->startOfDay());
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<AssessmentProfileVersion, $this>
     */
    public function profileVersion(): BelongsTo
    {
        return $this->belongsTo(AssessmentProfileVersion::class, 'assessment_profile_version_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function teachers(): BelongsToMany
    {
        // Pivot keys are class_id/user_id — SchoolClass would otherwise derive
        // school_class_id from the model name.
        return $this->belongsToMany(User::class, 'class_teachers', 'class_id', 'user_id')
            ->withPivot('role')->withTimestamps();
    }

    /**
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'class_id');
    }

    /**
     * The class AS IT STANDS TODAY — the composition an operational screen means.
     *
     * DELIBERATELY NOT THE DEFAULT. `enrollments()` still returns everyone who
     * was ever on this roll, because that is what history needs: a student who
     * transferred out in February was in the class in November, and their
     * results, their classifications and any photograph taken then are all
     * still true. A global scope here would have quietly rewritten every one of
     * those readings.
     *
     * So the choice is made at each call site, by what the screen is FOR
     * (§31): «quem está nesta turma» asks this one, «quem esteve» asks the
     * other, and neither can be reached by accident.
     *
     * @return HasMany<Enrollment, $this>
     */
    public function activeEnrollments(): HasMany
    {
        return $this->enrollments()->where('status', EnrollmentStatus::Active);
    }

    /**
     * Os grupos em que esta turma se desdobra — todos, incluindo os
     * arquivados, pela mesma razão que `enrollments()` devolve toda a
     * relação de turma: um grupo arquivado continua a ser o que uma aula de
     * novembro diz. Quem quer só os que ainda aceitam trabalho novo pede
     * `->active()` (ClassGroup::scopeActive).
     *
     * @return HasMany<ClassGroup, $this>
     */
    public function classGroups(): HasMany
    {
        return $this->hasMany(ClassGroup::class, 'class_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<Instrument, $this>
     */
    public function instruments(): HasMany
    {
        return $this->hasMany(Instrument::class, 'class_id')->orderByDesc('applied_on');
    }

    /**
     * @return HasMany<RecurringLessonSlot, $this>
     */
    public function recurringLessonSlots(): HasMany
    {
        return $this->hasMany(RecurringLessonSlot::class, 'class_id');
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'class_id');
    }

    /**
     * @return HasMany<EvidenceRecord, $this>
     */
    public function evidenceRecords(): HasMany
    {
        return $this->hasMany(EvidenceRecord::class, 'class_id');
    }

    /**
     * @return HasMany<Intervention, $this>
     */
    public function interventions(): HasMany
    {
        return $this->hasMany(Intervention::class, 'class_id');
    }
}
