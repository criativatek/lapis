<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A support measure for a student (§14), with its own lifecycle. Never part of
 * the calculation — no weight, no FK into results (§14.3).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $enrollment_id
 * @property int|null $academic_period_id
 * @property int|null $domain_id
 * @property string $title
 * @property string|null $description
 * @property InterventionStatus $status
 * @property Carbon $started_on
 * @property Carbon|null $expected_end_on
 * @property Carbon|null $concluded_on
 * @property bool $include_in_report
 * @property int $created_by
 */
#[Fillable([
    'enrollment_id', 'academic_period_id', 'domain_id', 'title', 'description',
    'status', 'started_on', 'expected_end_on', 'concluded_on', 'include_in_report', 'created_by',
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
            'status' => InterventionStatus::class,
            'started_on' => 'date',
            'expected_end_on' => 'date',
            'concluded_on' => 'date',
            'include_in_report' => 'boolean',
        ];
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
     * @return HasMany<InterventionReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(InterventionReview::class)->orderByDesc('reviewed_on');
    }
}
