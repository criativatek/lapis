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
 * An immutable record of a kept evaluation sheet and the exact values it held.
 *
 * There is deliberately no persisted `is_latest`: recency is derived from the
 * last exported_at within (class, period, scope, interim), so history cannot
 * acquire contradictory "latest" flags after concurrent exports.
 *
 * THE COLUMN NAMES SAY «EXPORT», THE RECORD DOES NOT REQUIRE ONE. The table was
 * born serving the INOVAR export and kept its vocabulary. Read them as the act
 * of creating the record, whatever produced it:
 *
 * - `exported_at`  — WHEN the record was created (a plain "guardar" counts).
 * - `exported_by`  — WHO created it.
 * - `exported_with_warnings` — whether the kept document carried warnings.
 * - `file_path` / `file_checksum` / `original_extension` — null whenever no
 *   file was produced. A sheet kept without an Excel is a first-class record,
 *   not a half-written one.
 *
 * `effective_at` is the REFERENCE DATE OF THE MOMENT — «the sheet as it stood
 * on 15 December» — and is a different statement from `exported_at`, which is
 * when somebody pressed the button. Keeping a December moment in January is a
 * legitimate thing to do, and the two dates then disagree on purpose.
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
 * @property Carbon|null $effective_at
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property string $file_disk
 * @property string|null $file_path
 * @property string|null $file_checksum
 * @property string|null $original_extension
 * @property bool $exported_with_warnings
 * @property int $warning_count
 * @property int $exported_by
 * @property Carbon $exported_at
 */
#[Fillable([
    'class_id', 'academic_period_id', 'scope', 'interim_assessment_id', 'adapter',
    'moment_label', 'effective_at', 'payload', 'payload_hash', 'file_disk', 'file_path',
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
            'effective_at' => 'date',
            'payload' => 'array',
            'exported_with_warnings' => 'boolean',
            'warning_count' => 'integer',
            'exported_at' => 'datetime',
        ];
    }

    /**
     * The state to show, derived and never stored.
     *
     * A column would be one more thing that can disagree with the record it
     * describes: whether a file exists is already written down, and whether the
     * document carried warnings is already written down.
     */
    public function statusLabel(): string
    {
        if ($this->file_path === null) {
            return 'Guardado';
        }

        return $this->exported_with_warnings ? 'Exportado com avisos' : 'Exportado';
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
