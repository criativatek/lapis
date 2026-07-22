<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A frozen record of how a value was reached (§13.6, domain-model.md §7.2). The
 * payload holds literal copies — inputs, applied weights, excluded elements with
 * reasons, rounding rule, result — never FKs into live scores. Written once and
 * never updated: there is deliberately no updated_at.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $enrollment_id
 * @property int $academic_period_id
 * @property ClassificationScope $scope
 * @property int $assessment_profile_version_id
 * @property SnapshotTrigger $trigger
 * @property string $engine_version
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property string|null $result_normalized_value
 * @property string|null $result_value
 * @property int|null $result_scale_level_id
 * @property int $created_by
 * @property Carbon $created_at
 */
#[Fillable([
    'enrollment_id', 'academic_period_id', 'scope', 'assessment_profile_version_id',
    'trigger', 'engine_version', 'payload', 'payload_hash',
    'result_normalized_value', 'result_value', 'result_scale_level_id', 'created_by', 'created_at',
])]
class CalculationSnapshot extends Model
{
    use BelongsToOrganization, HasUlids;

    public $timestamps = false;

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
            'scope' => ClassificationScope::class,
            'trigger' => SnapshotTrigger::class,
            'payload' => 'array',
            'result_normalized_value' => 'decimal:6',
            'result_value' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
