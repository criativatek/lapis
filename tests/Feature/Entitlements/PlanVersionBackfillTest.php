<?php

namespace Tests\Feature\Entitlements;

use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Entitlements\AccessState;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\RetainedOnDowngrade;
use App\Support\Limits\LimitKey;
use App\Support\Limits\Limits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\RollsBackPlanVersions;
use Tests\TestCase;

/**
 * THE MIGRATION CHANGES NOBODY'S ACCESS. THIS IS WHERE THAT IS PROVED.
 *
 * ADR-0008's acceptance criterion for step 1, and the one gate the whole lot
 * hangs on: version 1 of each plan is a byte-for-byte copy of the composition
 * every resolver already read, and every subscription — including the closed
 * ones — points at version 1 of its OWN plan. No organization may gain or lose
 * a single capability or a single unit of limit by the migration alone.
 *
 * HOW THE «BEFORE» IS OBTAINED, and why it is a real before rather than a
 * restatement of the after. The suite runs with the migration already applied,
 * so the test builds its fixtures, ROLLS THE THREE MIGRATIONS BACK — which
 * restores `module_plan` and `plans.limits` from the published versions — and
 * reads the access map through a deliberate replica of the PRE-ADR-0008
 * algorithm, one that knows nothing about versions and reads
 * `subscription.plan` exactly as the old `Entitlements` and `Limits` did. Then
 * it migrates forward again, running the real backfill over the real rows, and
 * reads the same map through the REAL resolvers. The two must be identical.
 * Not equivalent: identical.
 *
 * THE REPLICA IS THE POINT, not an inconvenience. Comparing the new resolver
 * against itself would prove nothing at all; comparing it against a
 * transcription of the algorithm this lot replaced is what makes «nothing
 * changed» a measurement.
 */
class PlanVersionBackfillTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackPlanVersions;

    #[Test]
    public function the_effective_access_map_is_identical_before_and_after_the_backfill(): void
    {
        $organizations = $this->fixtures();

        $before = $this->inTheOldWorld(fn (): array => $this->mapWithTheOldAlgorithm($organizations));

        $after = $this->mapWithTheRealResolvers($organizations);

        $this->assertSame(
            $before,
            $after,
            'The backfill changed the effective access map of at least one organization.',
        );

        // A map of empty maps would satisfy assertSame and prove nothing.
        $this->assertNotEmpty($before);
        $this->assertGreaterThan(0, collect($before)->flatten(1)->flatten()->filter()->count());
    }

    #[Test]
    public function every_subscription_including_the_closed_ones_points_at_version_one_of_its_own_plan(): void
    {
        $this->fixtures();

        $subscriptions = OrganizationSubscription::withoutGlobalScope('organization')->get();

        $this->assertGreaterThan(5, $subscriptions->count(), 'the fixtures must produce real history to check');

        foreach ($subscriptions as $subscription) {
            $version = PlanVersion::findOrFail($subscription->plan_version_id);

            $this->assertSame(
                $subscription->plan_id,
                $version->plan_id,
                "subscription {$subscription->id} names a version of another plan",
            );
            $this->assertSame(1, $version->version, 'the backfill assigns version 1 and never another');
        }

        // Historical rows are the ones `retainReadOnlyAfterDowngrade()` reads.
        // Leaving them NULL would have forced a fallback that read the present
        // again — the defect ADR-0008 exists to close.
        $this->assertSame(
            0,
            OrganizationSubscription::withoutGlobalScope('organization')
                ->whereNull('plan_version_id')
                ->count(),
        );
    }

    #[Test]
    public function the_backfill_preserves_every_dated_and_commercial_field_untouched(): void
    {
        $this->fixtures();

        $columns = ['id', 'organization_id', 'plan_id', 'status', 'commercial_condition', 'commercial_condition_note', 'starts_at', 'ends_at'];

        $snapshot = fn (): array => DB::table('organization_subscriptions')
            ->orderBy('id')
            ->get($columns)
            ->map(fn ($row): array => (array) $row)
            ->all();

        $after = $snapshot();
        $before = $this->inTheOldWorld($snapshot);

        $this->assertSame($before, $after, 'the backfill rewrote a status, a window or a commercial condition');
    }

    #[Test]
    public function version_one_carries_exactly_the_composition_the_legacy_tables_held(): void
    {
        $after = [];

        foreach (Plan::orderBy('id')->get() as $plan) {
            $version = $plan->versions()->where('version', 1)->firstOrFail();
            $keys = $version->modules()->pluck('key')->sort()->values()->all();

            $after[$plan->key] = ['modules' => $keys, 'limits' => $version->limits];
        }

        $before = $this->inTheOldWorld(function (): array {
            $legacy = [];

            foreach (DB::table('plans')->orderBy('id')->get() as $plan) {
                $keys = DB::table('module_plan')
                    ->join('modules', 'modules.id', '=', 'module_plan.module_id')
                    ->where('module_plan.plan_id', $plan->id)
                    ->pluck('modules.key')
                    ->sort()
                    ->values()
                    ->all();

                $legacy[$plan->key] = [
                    'modules' => $keys,
                    'limits' => $plan->limits === null ? null : json_decode((string) $plan->limits, true),
                ];
            }

            return $legacy;
        });

        $this->assertSame($before, $after);
    }

    #[Test]
    public function the_legacy_composition_tables_are_gone_rather_than_left_to_answer_wrongly(): void
    {
        // A live composition beside a versioned one is the defect, not a safety
        // net: any unmigrated reader would keep answering, plausibly and
        // wrongly, in silence.
        $this->assertFalse(Schema::hasTable('module_plan'));
        $this->assertFalse(Schema::hasColumn('plans', 'limits'));
    }

    // ------------------------------------------------------------- fixtures

    /**
     * Every shape the resolvers treat differently, as ADR-0008 enumerates
     * them: Base, Pro, Institucional, a running trial, an expired trial with
     * its dormant fallback, a suspended account, an `enabled = true` override,
     * an `enabled = false` override, an already-completed Pro → Base
     * downgrade, and an organization with no subscription at all.
     *
     * @return array<string, Organization>
     */
    private function fixtures(): array
    {
        $plans = app(ChangeOrganizationPlan::class);

        $base = $this->organization();

        $pro = $this->organization();
        $plans->to($pro, Plan::where('key', 'pro')->firstOrFail());

        $institutional = $this->organization();
        $plans->to($institutional, Plan::where('key', 'institutional')->firstOrFail());

        $downgraded = $this->organization();
        $plans->to($downgraded, Plan::where('key', 'pro')->firstOrFail());
        $plans->to($downgraded, Plan::where('key', 'base')->firstOrFail());

        $suspended = $this->organization();
        $plans->to($suspended, Plan::where('key', 'pro')->firstOrFail());
        $plans->suspend($suspended);

        $trialing = $this->organization();
        $this->trialFor($trialing, endsAt: Carbon::now()->addDays(7));

        $trialExpired = $this->organization();
        $this->trialFor($trialExpired, endsAt: Carbon::now()->subDay());

        $grantedOverride = $this->organization();
        $this->override($grantedOverride, 'lessons', enabled: true);

        $withdrawnOverride = $this->organization();
        $this->override($withdrawnOverride, 'classes', enabled: false);

        // No subscription at all: the conservative floor, and the one shape
        // where a fallback path reading the present would have been invisible.
        $unsubscribed = $this->organization();
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $unsubscribed->getKey())
            ->delete();

        $this->normalisePromotionalFixtures();

        app(Entitlements::class)->flush();

        return compact(
            'base', 'pro', 'institutional', 'downgraded', 'suspended',
            'trialing', 'trialExpired', 'grantedOverride', 'withdrawnOverride', 'unsubscribed',
        );
    }

    private function organization(): Organization
    {
        return User::factory()->create()->personalOrganization()->fresh();
    }

    /**
     * A trial plus its dormant Base fallback, written directly rather than
     * through `startProTrial()` so the fixture can place the window in the
     * past as well as the future.
     */
    private function trialFor(Organization $organization, Carbon $endsAt): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => Plan::where('key', 'pro')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Trial,
            'starts_at' => $endsAt->copy()->subDays(14),
            'ends_at' => $endsAt,
        ]);

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => Plan::where('key', 'base')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => $endsAt,
        ]);
    }

    private function override(Organization $organization, string $moduleKey, bool $enabled): void
    {
        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'module_id' => DB::table('modules')->where('key', $moduleKey)->value('id'),
            'enabled' => $enabled,
            'reason' => 'fixture',
        ]);
    }

    // ------------------------------------------------------- the two worlds

    /**
     * Runs $read against the schema as it was BEFORE this lot, then restores
     * the current schema.
     *
     * The rollback is what makes the reading honest: `module_plan` and
     * `plans.limits` come back, populated from the published versions, and the
     * replica below reads them the way the old code did. Migrating forward
     * again re-runs the real backfill over the real rows.
     *
     * How the lot is taken off — the step count and the commercial snapshot
     * that has to be cleared first — is `RollsBackPlanVersions`, shared with
     * the two other files that roll the same lot back.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function inTheOldWorld(callable $read)
    {
        $this->rollBackPlanVersionLot();

        $this->assertTrue(Schema::hasTable('module_plan'), 'the rollback must restore the legacy composition');
        $this->assertFalse(Schema::hasColumn('organization_subscriptions', 'plan_version_id'));

        try {
            return $read();
        } finally {
            $this->artisan('migrate')->run();
            app(Entitlements::class)->flush();
        }
    }

    /**
     * The PRE-ADR-0008 resolver, transcribed.
     *
     * Deliberately a copy rather than a call: the production algorithm now
     * reads versions, and the whole question is whether reading versions
     * answers what reading the live plan used to. It follows the old code
     * exactly — in-force wins, else a suspended most-recent row is read-only,
     * else nothing; the downgrade rule runs only when something is in force;
     * overrides apply last, in both directions.
     *
     * @param  array<string, Organization>  $organizations
     * @return array<string, array{states: array<string, string>, limits: array<string, string>}>
     */
    private function mapWithTheOldAlgorithm(array $organizations): array
    {
        $map = [];

        foreach ($organizations as $name => $organization) {
            $map[$name] = [
                'states' => $this->oldStates($organization),
                'limits' => $this->oldLimits($organization),
            ];
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function oldStates(Organization $organization): array
    {
        $now = Carbon::now();

        $subscriptions = DB::table('organization_subscriptions')
            ->where('organization_id', $organization->getKey())
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();

        $inForce = $subscriptions->first(fn ($subscription): bool => $this->grantsAccess($subscription->status)
            && Carbon::parse($subscription->starts_at)->lessThanOrEqualTo($now)
            && ($subscription->ends_at === null || Carbon::parse($subscription->ends_at)->greaterThan($now)));

        $states = [];

        if ($inForce !== null) {
            foreach ($this->legacyModuleKeysOfPlan($inForce->plan_id) as $key) {
                $states[$key] = AccessState::Allowed->value;
            }

            foreach ($subscriptions as $subscription) {
                if ($subscription->id === $inForce->id || Carbon::parse($subscription->starts_at)->greaterThan($now)) {
                    continue;
                }

                foreach ($this->legacyModuleKeysOfPlan($subscription->plan_id) as $key) {
                    if (isset($states[$key]) || ! RetainedOnDowngrade::includes($key)) {
                        continue;
                    }

                    $states[$key] = AccessState::ReadOnly->value;
                }
            }
        } else {
            $mostRecent = $subscriptions->first();

            if ($mostRecent !== null && $mostRecent->status === SubscriptionStatus::Suspended->value) {
                foreach ($this->legacyModuleKeysOfPlan($mostRecent->plan_id) as $key) {
                    $states[$key] = AccessState::ReadOnly->value;
                }
            }
        }

        $overrides = DB::table('organization_module_overrides')
            ->join('modules', 'modules.id', '=', 'organization_module_overrides.module_id')
            ->where('organization_module_overrides.organization_id', $organization->getKey())
            ->get(['modules.key', 'organization_module_overrides.enabled', 'organization_module_overrides.starts_at', 'organization_module_overrides.ends_at']);

        foreach ($overrides as $override) {
            $started = $override->starts_at === null || Carbon::parse($override->starts_at)->lessThanOrEqualTo($now);
            $open = $override->ends_at === null || Carbon::parse($override->ends_at)->greaterThan($now);

            if (! $started || ! $open) {
                continue;
            }

            $states[$override->key] = $override->enabled
                ? AccessState::Allowed->value
                : AccessState::Locked->value;
        }

        ksort($states);

        return $states;
    }

    /**
     * @return array<string, string>
     */
    private function oldLimits(Organization $organization): array
    {
        $now = Carbon::now();

        $inForce = DB::table('organization_subscriptions')
            ->where('organization_id', $organization->getKey())
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get()
            ->first(fn ($subscription): bool => $this->grantsAccess($subscription->status)
                && Carbon::parse($subscription->starts_at)->lessThanOrEqualTo($now)
                && ($subscription->ends_at === null || Carbon::parse($subscription->ends_at)->greaterThan($now)));

        $limits = [];

        foreach (LimitKey::cases() as $key) {
            if ($inForce === null) {
                // The old resolver's conservative floor for an organization
                // with nothing in force.
                $limits[$key->value] = '0';

                continue;
            }

            $raw = json_decode((string) DB::table('plans')->where('id', $inForce->plan_id)->value('limits'), true) ?? [];

            $limits[$key->value] = (string) ($raw[$key->value] ?? 'MISSING');
        }

        return $limits;
    }

    private function grantsAccess(string $status): bool
    {
        return SubscriptionStatus::from($status)->grantsAccess();
    }

    /**
     * @return list<string>
     */
    private function legacyModuleKeysOfPlan(int $planId): array
    {
        return DB::table('module_plan')
            ->join('modules', 'modules.id', '=', 'module_plan.module_id')
            ->where('module_plan.plan_id', $planId)
            ->pluck('modules.key')
            ->all();
    }

    /**
     * @param  array<string, Organization>  $organizations
     * @return array<string, array{states: array<string, string>, limits: array<string, string>}>
     */
    private function mapWithTheRealResolvers(array $organizations): array
    {
        $entitlements = app(Entitlements::class);
        $entitlements->flush();
        $limits = app(Limits::class);

        $map = [];

        foreach ($organizations as $name => $organization) {
            $states = array_map(
                fn (AccessState $state): string => $state->value,
                $entitlements->accessStatesFor($organization->fresh()),
            );
            ksort($states);

            $caps = [];

            foreach (LimitKey::cases() as $key) {
                $value = $limits->limitFor($organization->fresh(), $key);
                $caps[$key->value] = $value->isUnlimited() ? 'unlimited' : (string) $value->value();
            }

            $map[$name] = ['states' => $states, 'limits' => $caps];
        }

        return $map;
    }
}
