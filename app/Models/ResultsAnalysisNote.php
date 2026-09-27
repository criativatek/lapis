<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A teacher's own observations for a results-analysis context (design spec
 * §5) — qualitative text, separate from the indicators, which are always
 * recalculated on read. `lock_version` is this table's own optimistic-lock
 * counter, independent of any score's.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property string $context_kind
 * @property int|null $instrument_id
 * @property string|null $body
 * @property int $lock_version
 * @property int|null $created_by
 * @property int|null $updated_by
 */
#[Fillable(['context_kind', 'instrument_id', 'body', 'lock_version', 'created_by', 'updated_by'])]
class ResultsAnalysisNote extends Model
{
    use BelongsToOrganization, HasUlids;

    /**
     * The two limits the form (`InstrumentResultsController::updateNote()`,
     * through `App\Rules\ResultsAnalysisNoteBody`) and the backup importer
     * (`ValidateBackupPayload`) both enforce, through
     * {@see bodyLimitViolation()} — one definition, so the two never drift.
     *
     * Characters are what the teacher sees. BYTES are what the `TEXT` column
     * holds (65 535 in UTF-8): 20 000 emoji are 80 000 bytes, and an INSERT
     * past that would fail as a 500 in the form and roll back a whole
     * restore — with the driver's error, text included, in the log.
     */
    public const int BODY_MAX_LENGTH = 20000;

    public const int BODY_MAX_BYTES = 65535;

    /**
     * Which limit a body breaks, if any: `'characters'`, `'bytes'` or null.
     */
    public static function bodyLimitViolation(string $body): ?string
    {
        if (mb_strlen($body, 'UTF-8') > self::BODY_MAX_LENGTH) {
            return 'characters';
        }

        if (strlen($body) > self::BODY_MAX_BYTES) {
            return 'bytes';
        }

        return null;
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
            'lock_version' => 'integer',
        ];
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
