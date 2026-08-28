<?php

namespace App\Models;

use App\Services\Ai\Gateway\AiUseCase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One measured call to an AI engine.
 *
 * A METER, NOT A TRANSCRIPT. Every column below is a number, a timestamp, or a
 * word from a closed vocabulary. There is no column that can hold a prompt, an
 * answer, a name, a grade or a sentence somebody wrote about a child — not
 * because callers are trusted to leave them out, but because there is nowhere to
 * put them (§7 of the AI Core brief).
 *
 * DELIBERATELY NOT `BelongsToOrganization`. The trait would refuse to write a
 * row when no tenant is resolved, and the backoffice's connection test runs in
 * exactly that state. Isolation is enforced here by `scopeForOrganization()`
 * being the only way anything reads this table, and by every quota query naming
 * its organization explicitly — see `AiQuota`. That is a smaller guarantee than
 * the global scope and it is written down rather than assumed.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property int|null $user_id
 * @property string $capability
 * @property AiUseCase $use_case
 * @property string $provider
 * @property string $model
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $total_tokens
 * @property int|null $duration_ms
 * @property string $status
 * @property string|null $error_category
 * @property string|null $subject_hash
 * @property Carbon $created_at
 */
#[Fillable([
    'organization_id', 'user_id', 'capability', 'use_case', 'provider', 'model',
    'input_tokens', 'output_tokens', 'total_tokens', 'duration_ms',
    'status', 'error_category', 'subject_hash', 'created_at',
])]
class AiUsageEvent extends Model
{
    /** The engine answered and the answer was usable. */
    public const SUCCEEDED = 'succeeded';

    /** The engine was asked and did not answer usefully. `error_category` says how. */
    public const FAILED = 'failed';

    /**
     * The call never left the building: an entitlement, a quota or a rate limit
     * stopped it. Recorded rather than dropped — «we refused four hundred
     * requests last month» is the number that says a ceiling is set wrong, and
     * it is invisible if only successes are written down.
     */
    public const BLOCKED = 'blocked';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'use_case' => AiUseCase::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Rows belonging to one organization — never the platform's own.
     *
     * `where`, not `whereIn`, and no null branch: a quota is a question about a
     * school, and the operator's connection tests are not that school's doing.
     *
     * @param  Builder<AiUsageEvent>  $query
     * @return Builder<AiUsageEvent>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Rows that count towards a quota.
     *
     * A BLOCKED CALL DOES NOT CONSUME QUOTA. It never reached an engine and cost
     * nothing, and counting it would mean that hitting a ceiling made the
     * ceiling harder to get back under. A FAILED one does count: the request was
     * made, the tokens were spent, and an engine that fails on every call would
     * otherwise be free.
     *
     * @param  Builder<AiUsageEvent>  $query
     * @return Builder<AiUsageEvent>
     */
    public function scopeBillable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::SUCCEEDED, self::FAILED]);
    }
}
