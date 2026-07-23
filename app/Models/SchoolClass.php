<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\SchoolClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @property int|null $assessment_profile_version_id
 * @property ClassStatus $status
 */
#[Fillable(['academic_year_id', 'subject_id', 'grade_level', 'course_code', 'label', 'assessment_profile_version_id', 'status'])]
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
        ];
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
     * @return HasMany<Instrument, $this>
     */
    public function instruments(): HasMany
    {
        return $this->hasMany(Instrument::class, 'class_id')->orderByDesc('applied_on');
    }

    /**
     * @return HasMany<EvidenceRecord, $this>
     */
    public function evidenceRecords(): HasMany
    {
        return $this->hasMany(EvidenceRecord::class, 'class_id');
    }
}
