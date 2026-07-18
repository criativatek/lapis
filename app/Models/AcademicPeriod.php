<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AcademicPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A period within a year — semester, term, trimester, module. Not limited to two
 * (§9.2). `kind` is a label; cumulative behaviour is a versioned profile rule.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $academic_year_id
 * @property string $label
 * @property AcademicPeriodKind $kind
 * @property int $sequence
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property AcademicPeriodStatus $status
 */
#[Fillable(['academic_year_id', 'label', 'kind', 'sequence', 'starts_on', 'ends_on', 'status'])]
class AcademicPeriod extends Model
{
    /** @use HasFactory<AcademicPeriodFactory> */
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
            'kind' => AcademicPeriodKind::class,
            'sequence' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => AcademicPeriodStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
