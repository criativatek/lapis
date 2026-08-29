<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One thing a plan actually sold: its modules and its contracted limits,
 * frozen (ADR-0008).
 *
 * `Plan` is the commercial IDENTITY — «Pro» — and it is stable. This is the
 * OFFER, and it is not: publishing Pro v2 is how the offer changes, and it
 * leaves every subscription on Pro v1 exactly as it was. Grandfathering stops
 * being a feature somebody has to remember and becomes the absence of an
 * action.
 *
 * A PUBLISHED VERSION IS IMMUTABLE, and that is enforced here rather than
 * promised in a comment: the `updating` guard below refuses any change to the
 * plan, the version number, the limits, the composition hash or the
 * publication date, in the same shape `SubscriptionPayment` already refuses to
 * have its money rewritten. Only `retired_at` and `notes` move — retiring a
 * version stops it being sold and changes nothing for anyone already on it.
 *
 * Its module composition is equally frozen. Nothing calls `modules()->sync()`
 * on a version: `EntitlementsSeeder` attaches the modules once, at publication,
 * and `PlanVersionArchitectureTest` is what keeps it that way.
 *
 * @property int $id
 * @property int $plan_id
 * @property int $version
 * @property array<string, mixed>|null $limits
 * @property string $composition_hash
 * @property Carbon $published_at
 * @property Carbon|null $retired_at
 * @property string|null $notes
 */
#[Fillable(['plan_id', 'version', 'limits', 'composition_hash', 'published_at', 'retired_at', 'notes'])]
class PlanVersion extends Model
{
    /**
     * Everything a published version still allows to move.
     *
     * `updated_at` rides along because Eloquent stamps it on any save; it is
     * metadata about the row, not about the offer.
     *
     * @var list<string>
     */
    protected const MUTABLE_AFTER_PUBLICATION = ['retired_at', 'notes', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            $forbidden = array_diff(array_keys($version->getDirty()), self::MUTABLE_AFTER_PUBLICATION);

            if ($forbidden !== []) {
                throw new LogicException(
                    'A published plan version is immutable: refused change to '
                    .implode(', ', $forbidden).'. Publish the next version instead.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'limits' => 'array',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The capabilities this version carries. THE only `modules()` in the
     * codebase — `Plan::modules()` was removed by ADR-0008 §3 rather than left
     * to coexist, because a caller reading the live composition of a plan gets
     * a plausible, wrong answer in silence.
     *
     * @return BelongsToMany<Module, $this>
     */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'module_plan_version');
    }

    /**
     * Everyone who contracted this version.
     *
     * The tenant scope is removed here rather than at every call site: a plan
     * version is platform-level data, and «who is on this version» is never a
     * question asked from inside one organization — it is asked by the
     * backoffice, by a migration and by the tests, all of which would
     * otherwise get a silent zero or an unresolved-context error (ADR-0002
     * keeps that removal explicit rather than implicit).
     *
     * @return HasMany<OrganizationSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(OrganizationSubscription::class)->withoutGlobalScope('organization');
    }

    /**
     * Still sellable — i.e. not withdrawn from the catalogue.
     *
     * There is no «published?» half to check: `published_at` is NOT NULL, so a
     * row existing IS the publication. A draft version is not a state this
     * model has, and adding one would mean deciding what an unpublished
     * version does to a subscriber that somehow pointed at it.
     */
    public function isCurrentlySellable(): bool
    {
        return $this->retired_at === null;
    }

    /**
     * What «the same offer» means, as one comparable value.
     *
     * The module keys are sorted and de-duplicated so the ORDER rows came back
     * in can never make an unchanged composition look changed, and the limits
     * are key-sorted for the same reason. Both halves are included because
     * either one moving is a different offer: a plan that keeps its modules and
     * halves its turma cap has changed what it sells.
     *
     * Used by `EntitlementsSeeder` to decide whether to publish at all, and by
     * the backfill migration so both sides of that comparison can never be
     * produced by two different functions.
     *
     * @param  list<string>  $moduleKeys
     * @param  array<string, mixed>|null  $limits
     */
    public static function compositionHash(array $moduleKeys, ?array $limits): string
    {
        $keys = array_values(array_unique($moduleKeys));
        sort($keys);

        $canonical = $limits ?? [];
        ksort($canonical);

        return hash('sha256', (string) json_encode(
            ['modules' => $keys, 'limits' => $canonical],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * This version's own hash, recomputed from what is actually stored — the
     * way a test asks «is the stored hash still telling the truth?».
     */
    public function recomputeCompositionHash(): string
    {
        $keys = [];

        foreach ($this->modules()->pluck('key') as $key) {
            $keys[] = (string) $key;
        }

        return self::compositionHash($keys, $this->limits);
    }
}
