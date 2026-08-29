<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

/**
 * A commercial plan: Base, Pro or Institucional.
 *
 * IDENTITY ONLY, since ADR-0008. What a plan CARRIES — its modules and its
 * contracted limits — belongs to a `PlanVersion`, because a plan that IS its
 * composition cannot change without changing, retroactively and silently, what
 * everyone who ever bought it was entitled to. What stays here is the stable
 * half: the key, the name and the order — what the landing, the checkout, the
 * backoffice filters and the mails actually name.
 *
 * `modules()` IS GONE, NOT DEPRECATED (§3 of the ADR). Left to coexist with the
 * version's, every unmigrated caller would have kept answering — plausibly,
 * wrongly, in silence. Removed, each one breaks and gets migrated.
 * `plans.limits` went with it, for the same reason and in the same migration:
 * versioned modules beside a live limits column would recreate the identical
 * defect one dimension over.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property int $sort_order
 * @property-read PlanVersion|null $currentVersion NULL for a plan with nothing published — a state
 *                a seeded installation never has, and one a public page must survive rather than 500 on.
 */
#[Fillable(['key', 'name', 'sort_order'])]
class Plan extends Model
{
    /**
     * @return HasMany<PlanVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class);
    }

    /**
     * What this plan sells TODAY: the highest published version that has not
     * been retired.
     *
     * A relation rather than a plain method so callers can eager-load it — the
     * landing renders three plans and their capabilities in one extra query,
     * not four.
     *
     * @return HasOne<PlanVersion, $this>
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(PlanVersion::class)->ofMany(
            ['version' => 'max'],
            fn ($query) => $query->whereNotNull('published_at')->whereNull('retired_at'),
        );
    }

    /**
     * The current version, or null when this plan has published nothing.
     *
     * The eager-loaded relation when it is loaded, a query when it is not, so
     * the landing page still renders three plans in four queries rather than
     * six. The `instanceof` is what makes the absence expressible at all:
     * `$plan->currentVersion` is typed as always present, and «a plan with
     * nothing published» is a state a public page has to survive rather than
     * 500 on.
     */
    public function currentVersionOrNull(): ?PlanVersion
    {
        $version = $this->relationLoaded('currentVersion')
            ? $this->getRelation('currentVersion')
            : $this->currentVersion()->getResults();

        return $version instanceof PlanVersion ? $version : null;
    }

    /**
     * The current version, or a loud failure.
     *
     * Every path that puts an organization on a plan resolves through here, so
     * «this plan has nothing published» has to stop the sale rather than
     * produce a subscription entitled to nothing. In a seeded installation it
     * cannot happen: `EntitlementsSeeder` publishes v1 for all three plans.
     */
    public function currentVersionOrFail(): PlanVersion
    {
        $version = $this->currentVersionOrNull();

        if ($version === null) {
            throw new RuntimeException(
                "Plan [{$this->key}] has no published version to sell. Run the EntitlementsSeeder."
            );
        }

        return $version;
    }
}
