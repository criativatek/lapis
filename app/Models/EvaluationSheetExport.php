<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Hashing\CanonicalPayload;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An immutable record of an evaluation-sheet export and the exact values sent.
 *
 * There is deliberately no persisted `is_latest`: recency is derived from the
 * last exported_at within (class, period, scope, interim), so history cannot
 * acquire contradictory "latest" flags after concurrent exports.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_id
 * @property int $academic_period_id
 * @property ClassificationScope $scope
 * @property int|null $interim_assessment_id
 * @property string $adapter
 * @property string $moment_label
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property string $file_disk
 * @property string $file_path
 * @property string $file_checksum
 * @property string $original_extension
 * @property bool $exported_with_warnings
 * @property int $warning_count
 * @property int $exported_by
 * @property Carbon $exported_at
 */
#[Fillable([
    'class_id', 'academic_period_id', 'scope', 'interim_assessment_id', 'adapter',
    'moment_label', 'payload', 'payload_hash', 'file_disk', 'file_path',
    'file_checksum', 'original_extension', 'exported_with_warnings',
    'warning_count', 'exported_by', 'exported_at',
])]
class EvaluationSheetExport extends Model
{
    use BelongsToOrganization, HasUlids;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('An evaluation sheet export is immutable and cannot be updated.');
        });
    }

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
            'payload' => 'array',
            'exported_with_warnings' => 'boolean',
            'warning_count' => 'integer',
            'exported_at' => 'datetime',
        ];
    }

    public function isIntact(): bool
    {
        return $this->payload_hash === CanonicalPayload::hash($this->payload);
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<AcademicPeriod, $this>
     */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    /**
     * @return BelongsTo<InterimAssessment, $this>
     */
    public function interimAssessment(): BelongsTo
    {
        return $this->belongsTo(InterimAssessment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function exporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by');
    }
}
