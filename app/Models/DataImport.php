<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One session of restoring a Lapispro-generated backup (Fatia 6).
 *
 * Holds the conversation — the file, what was read and validated from it,
 * what got created/matched/skipped — never a duplicate of the pedagogical
 * data itself. After confirmation the restored records live where records
 * of that kind always live; this row remains as provenance: which backup,
 * requested by whom, confirmed by whom and when.
 *
 * `organization_id` is not fillable: stamped from the resolved tenant,
 * never accepted from a request.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property DataImportStatus $status
 * @property string|null $original_filename
 * @property string|null $stored_path
 * @property string|null $file_sha256
 * @property int|null $file_size
 * @property int|null $source_schema_version
 * @property string|null $source_app_version
 * @property Carbon|null $source_generated_at
 * @property array{ulid: string, name: string, type: string}|null $source_organization
 * @property array<string, mixed>|null $canonical_snapshot
 * @property array<string, mixed>|null $summary
 * @property string|null $failure_reason
 * @property int $requested_by
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $expires_at
 */
#[Fillable([
    'status', 'original_filename', 'stored_path', 'file_sha256', 'file_size',
    'source_schema_version', 'source_app_version', 'source_generated_at', 'source_organization',
    'canonical_snapshot', 'summary', 'failure_reason',
    'requested_by', 'confirmed_by', 'confirmed_at', 'expires_at',
])]
class DataImport extends Model
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
            'status' => DataImportStatus::class,
            'source_generated_at' => 'datetime',
            'source_organization' => 'array',
            'canonical_snapshot' => 'array',
            'summary' => 'array',
            'confirmed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether the uploaded file should still be on disk. Anything final has
     * no business keeping a backup of pedagogical data around.
     */
    public function shouldRetainFile(): bool
    {
        return $this->status->isOpen();
    }
}
