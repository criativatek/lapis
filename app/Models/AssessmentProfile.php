<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AssessmentProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The stable container for an assessment rule. Holds no weights, scales or
 * formulas — those live in versions, so editing the profile name never changes
 * history (§10.2).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $academic_year_id
 * @property int $subject_id
 * @property string|null $grade_level
 * @property string $name
 * @property string|null $description
 * @property int|null $current_version_id
 */
#[Fillable(['academic_year_id', 'subject_id', 'grade_level', 'name', 'description'])]
class AssessmentProfile extends Model
{
    /** @use HasFactory<AssessmentProfileFactory> */
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

    /**
     * @return HasMany<AssessmentProfileVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AssessmentProfileVersion::class)->orderBy('version_number');
    }

    /**
     * @return BelongsTo<AssessmentProfileVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AssessmentProfileVersion::class, 'current_version_id');
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
     * The draft version being edited, if any. Activation turns it into the active
     * one; a fresh edit of an active profile creates a new draft.
     */
    public function draftVersion(): ?AssessmentProfileVersion
    {
        return $this->versions()->where('status', ProfileVersionStatus::Draft->value)->latest('version_number')->first();
    }
}
