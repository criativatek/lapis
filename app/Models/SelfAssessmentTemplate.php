<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A self-assessment questionnaire (§15). For now it is derived per class from the
 * profile version's domains — one scale question per domain.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $assessment_profile_version_id
 * @property int|null $class_id
 * @property string $name
 * @property bool $is_active
 */
#[Fillable(['assessment_profile_version_id', 'class_id', 'name', 'is_active'])]
class SelfAssessmentTemplate extends Model
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
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<SelfAssessmentQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(SelfAssessmentQuestion::class)->orderBy('sequence');
    }

    /**
     * The profile version this template was derived from � the criteria its
     * questions speak in, which are not necessarily the class's today.
     *
     * @return BelongsTo<AssessmentProfileVersion, $this>
     */
    public function profileVersion(): BelongsTo
    {
        return $this->belongsTo(AssessmentProfileVersion::class, 'assessment_profile_version_id');
    }

    /**
     * @return HasMany<SelfAssessment, $this>
     */
    public function selfAssessments(): HasMany
    {
        return $this->hasMany(SelfAssessment::class);
    }
}
