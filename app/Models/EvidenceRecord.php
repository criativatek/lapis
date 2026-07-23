<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property string $description
 * @property bool $include_in_report
 * @property int $created_by
 */
#[Fillable([
    'class_id', 'enrollment_id', 'academic_period_id', 'domain_id',
    'quick_rating_scale_level_id', 'occurred_at', 'kind', 'description',
    'include_in_report', 'created_by',
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
            'include_in_report' => 'boolean',
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
}
