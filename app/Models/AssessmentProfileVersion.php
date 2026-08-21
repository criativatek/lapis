<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Assessment\FrozenProfileVersionException;
use Database\Factories\AssessmentProfileVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The freezable calculation rule. Everything about how results are computed lives
 * here and in the child rows; a result points at one version and thus knows
 * exactly how it was produced (§10.2, domain-model.md §3).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $assessment_profile_id
 * @property int $version_number
 * @property ProfileVersionStatus $status
 * @property int $scale_id
 * @property string $domain_weight_mode
 * @property Carbon|null $activated_at
 * @property Carbon|null $frozen_at
 * @property Carbon|null $superseded_at
 */
#[Fillable([
    'assessment_profile_id', 'version_number', 'status', 'scale_id',
    'domain_weight_mode', 'period_result_mode', 'accumulated_mode', 'absence_mode',
    'rounding_mode', 'rounding_scale', 'rounding_stage', 'minimum_rules', 'change_note',
    'created_from_version_id',
])]
class AssessmentProfileVersion extends Model
{
    /** @use HasFactory<AssessmentProfileVersionFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

    protected static function booted(): void
    {
        // Immutability guard (§10.2, domain-model.md §3.3). Once frozen, a version
        // is the historical record a result points at — it must never change under
        // it. The activation Action clears this guard for its own controlled write.
        static::updating(function (self $version): void {
            if ($version->getOriginal('frozen_at') !== null && ! $version->allowFrozenWrite) {
                throw FrozenProfileVersionException::make($version);
            }
        });
    }

    /** Escape hatch used only by the activation Action's controlled transition. */
    public bool $allowFrozenWrite = false;

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
            'status' => ProfileVersionStatus::class,
            'minimum_rules' => 'array',
            'activated_at' => 'datetime',
            'frozen_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AssessmentProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AssessmentProfile::class, 'assessment_profile_id');
    }

    /**
     * @return BelongsTo<Scale, $this>
     */
    public function scale(): BelongsTo
    {
        return $this->belongsTo(Scale::class);
    }

    /**
     * @return HasMany<ProfileVersionDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(ProfileVersionDomain::class)->orderBy('sequence');
    }

    /**
     * @return HasMany<ProfileVersionPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(ProfileVersionPeriod::class);
    }

    public function isFrozen(): bool
    {
        return $this->frozen_at !== null;
    }

    /**
     * The sum of the domain weights, for the 100% activation check.
     */
    public function totalDomainWeight(): string
    {
        return (string) $this->domains()->sum('weight_percent');
    }
}
