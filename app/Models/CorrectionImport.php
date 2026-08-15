<?php

namespace App\Models;

use App\Domain\Import\Correction\CorrectionGridSource;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One session of importing a correction grid exported from another platform.
 *
 * It holds the conversation — the file, what was read from it, what the teacher
 * decided — and nothing academic. After confirmation the marks live where marks
 * live; this row remains as provenance: which platform, which file, who
 * confirmed it and when.
 *
 * `organization_id` is not fillable, like everywhere else: it is stamped from
 * the resolved tenant, never accepted from a request.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_id
 * @property int|null $instrument_id
 * @property CorrectionGridSource $source
 * @property CorrectionImportStatus $status
 * @property string|null $original_filename
 * @property string|null $stored_path
 * @property string|null $file_sha256
 * @property int|null $file_size
 * @property int $uploaded_by
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property array<string, mixed>|null $source_metadata
 * @property array<string, mixed>|null $canonical_snapshot
 * @property array<string, mixed>|null $mapping_snapshot
 * @property array<string, mixed>|null $summary
 * @property string|null $failure_reason
 */
#[Fillable([
    'class_id', 'instrument_id', 'source', 'status', 'original_filename',
    'stored_path', 'file_sha256', 'file_size', 'uploaded_by', 'confirmed_by',
    'confirmed_at', 'source_metadata', 'canonical_snapshot', 'mapping_snapshot',
    'summary', 'failure_reason',
])]
class CorrectionImport extends Model
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
            'source' => CorrectionGridSource::class,
            'status' => CorrectionImportStatus::class,
            'confirmed_at' => 'datetime',
            'source_metadata' => 'array',
            'canonical_snapshot' => 'array',
            'mapping_snapshot' => 'array',
            'summary' => 'array',
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
     * @return BelongsTo<Instrument, $this>
     */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Whether the uploaded file should still be on disk. Anything final has no
     * business keeping a file full of students' marks around (§27).
     */
    public function shouldRetainFile(): bool
    {
        return $this->status->isOpen();
    }
}
