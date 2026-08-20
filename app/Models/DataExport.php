<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user-requested export of their own accessible data (Fatia 4). `ulid`
 * identifies the row; `disk_path` is a separate, server-generated token
 * folder on the private `local` disk — the ulid alone never resolves a file,
 * `DataExportPolicy::download` still checks `requested_by` on every read.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $requested_by
 * @property string $status
 * @property string|null $disk_path
 * @property int|null $byte_size
 * @property string|null $failed_reason
 * @property Carbon|null $expires_at
 * @property Carbon|null $downloaded_at
 */
#[Fillable(['requested_by', 'status', 'disk_path', 'byte_size', 'failed_reason', 'expires_at', 'downloaded_at'])]
class DataExport extends Model
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
            'expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }

    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->disk_path !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
