<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The audit record of a class moving between profile versions (§10.2, A4). The
 * impact_preview is a frozen document of what the teacher saw before confirming.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_id
 * @property int|null $from_version_id
 * @property int $to_version_id
 * @property array<string, mixed> $impact_preview
 * @property int $affected_enrollment_count
 * @property int $recalculated_result_count
 * @property int $confirmed_by
 * @property Carbon $confirmed_at
 * @property string $reason
 */
#[Fillable([
    'class_id', 'from_version_id', 'to_version_id', 'impact_preview',
    'affected_enrollment_count', 'recalculated_result_count',
    'confirmed_by', 'confirmed_at', 'reason',
])]
class ClassProfileMigration extends Model
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
        return [
            'impact_preview' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}
