<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The teacher's declaration «Registado no Inovar» for one follow-up case. A
 * statement, not a verification: the application never sees the Inovar.
 *
 * `occurrences` is the exact set the declaration covered, as {ulid, date}
 * pairs — so a later correction or deletion of a source record can be told
 * apart from the set that was declared, without ever claiming the external
 * record was corrected. Undoing keeps the row (`undone_at`/`undone_by`).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $record_followup_case_id
 * @property int|null $confirmed_by
 * @property Carbon $confirmed_at
 * @property int $occurrence_count
 * @property list<array{ulid: string, date: string}> $occurrences
 * @property int|null $undone_by
 * @property Carbon|null $undone_at
 */
#[Fillable(['record_followup_case_id', 'confirmed_by', 'confirmed_at', 'occurrence_count', 'occurrences', 'undone_by', 'undone_at'])]
class RecordFollowupConfirmation extends Model
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
            'confirmed_at' => 'datetime',
            'undone_at' => 'datetime',
            'occurrence_count' => 'integer',
            'occurrences' => 'array',
        ];
    }

    /**
     * @return BelongsTo<RecordFollowupCase, $this>
     */
    public function followupCase(): BelongsTo
    {
        return $this->belongsTo(RecordFollowupCase::class, 'record_followup_case_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function undoer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }
}
