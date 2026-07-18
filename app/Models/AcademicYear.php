<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AcademicYearFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A school year — the calculation boundary. No calculation crosses it; prior-year
 * data is never mixed into the current year (§9.1, domain-model.md §2.2).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property string $label
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property AcademicYearStatus $status
 * @property string $country_code
 * @property string|null $region_code
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 */
#[Fillable(['label', 'starts_on', 'ends_on', 'status', 'country_code', 'region_code'])]
class AcademicYear extends Model
{
    /** @use HasFactory<AcademicYearFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

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
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => AcademicYearStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AcademicPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(AcademicPeriod::class)->orderBy('sequence');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }
}
