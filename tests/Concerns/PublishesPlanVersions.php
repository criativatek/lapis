<?php

namespace Tests\Concerns;

use App\Models\Module;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Entitlements\Entitlements;

/**
 * Publish the next version of a plan, the way `EntitlementsSeeder` does.
 *
 * Since ADR-0008 a test cannot change what a plan sells by writing to it —
 * that is the whole point, and `PlanVersion`'s own guard refuses it. Changing
 * the offer means publishing the version after the current one, which is what
 * this does: same shape as the seeder's `publish()`, minus the
 * did-anything-actually-change check, because a test that asks for a new
 * version wants one.
 *
 * Omitting `$moduleKeys` or `$limits` carries that half over from the current
 * version unchanged, so a test about a limit moving does not have to restate a
 * composition it does not care about.
 */
trait PublishesPlanVersions
{
    protected function publishNextVersionOf(string $planKey, ?array $moduleKeys = null, ?array $limits = null): PlanVersion
    {
        $plan = Plan::where('key', $planKey)->firstOrFail();
        $latest = $plan->versions()->orderByDesc('version')->first();

        $keys = $moduleKeys ?? ($latest?->modules()->pluck('key')->all() ?? []);
        $caps = $limits ?? $latest?->limits ?? [];

        $version = $plan->versions()->create([
            'version' => ($latest?->version ?? 0) + 1,
            'limits' => $caps,
            'composition_hash' => PlanVersion::compositionHash($keys, $caps),
            'published_at' => now(),
        ]);

        $version->modules()->attach(Module::whereIn('key', $keys)->pluck('id'));

        app(Entitlements::class)->flush();

        return $version->fresh();
    }

    /** The version a plan sells right now — what a sale made today contracts. */
    protected function currentVersionOf(string $planKey): PlanVersion
    {
        return Plan::where('key', $planKey)->firstOrFail()->currentVersionOrFail();
    }

    /**
     * Publish a plan version with these limits merged over the current ones,
     * AND move this organization onto it.
     *
     * Both halves, because since ADR-0008 a new cap reaches nobody on its own:
     * grandfathering is the default, so an existing subscriber that is meant
     * to feel the change has to be moved. Tests about a contracted cap
     * changing for a specific organization want exactly this pair.
     *
     * @param  array<string, mixed>  $limits
     */
    protected function contractNewLimitsFor(Organization $organization, string $planKey, array $limits): PlanVersion
    {
        $current = $this->currentVersionOf($planKey);

        $version = $this->publishNextVersionOf($planKey, limits: [...($current->limits ?? []), ...$limits]);

        app(ChangeOrganizationPlan::class)->to($organization->fresh(), $version);
        app(Entitlements::class)->flush();

        return $version;
    }
}
