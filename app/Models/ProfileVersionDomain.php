<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where the domain weights live — versioned, unlike the domain itself.
 *
 * @property int $id
 * @property int $assessment_profile_version_id
 * @property int $domain_id
 * @property string $weight_percent
 * @property int $sequence
 * @property int|null $expected_element_count
 * @property int|null $minimum_element_count
 */
#[Fillable(['assessment_profile_version_id', 'domain_id', 'weight_percent', 'sequence', 'expected_element_count', 'minimum_element_count'])]
class ProfileVersionDomain extends Model
{
    protected function casts(): array
    {
        return [
            'weight_percent' => 'decimal:4',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AssessmentProfileVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(AssessmentProfileVersion::class, 'assessment_profile_version_id');
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
