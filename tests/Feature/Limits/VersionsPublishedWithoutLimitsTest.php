<?php

namespace Tests\Feature\Limits;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\PlanVersion;
use App\Models\SubscriptionStatus;
use App\Support\Limits\LimitKey;
use App\Support\Limits\Limits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\PublishesPlanVersions;
use Tests\TestCase;

/**
 * The defect no test could see, because no test database could reach the state.
 *
 * `2026_09_11_000100` froze each plan's composition into version 1 by copying
 * `plans.limits`. On a test database that migration runs against an EMPTY
 * `plans` table — the seeder comes after — so it writes no version at all and
 * `EntitlementsSeeder` then publishes a version 1 that states every cap. On a
 * real installation the plans were already there, and where the caps had not
 * yet reached `plans.limits` it froze a version stating none of them.
 *
 * From then on every organization pinned to that version got a 500 from every
 * write that counts towards a limit, because `Limits::parse()` treats a missing
 * key as a configuration error and says so loudly — correctly; the error was
 * upstream of it. So the state has to be built by hand here, exactly as the
 * migration left it, or the repair has nothing to prove itself against.
 */
class VersionsPublishedWithoutLimitsTest extends TestCase
{
    use PublishesPlanVersions;
    use RefreshDatabase;

    #[Test]
    public function an_organization_pinned_to_a_version_published_without_caps_cannot_be_read_at_all(): void
    {
        $organization = $this->organizationPinnedToACaplessVersion();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no configured limit for [active_classes]');

        app(Limits::class)->limitFor($organization, LimitKey::ActiveClasses);
    }

    #[Test]
    public function the_repair_fills_the_caps_the_plan_first_published_and_the_organization_works_again(): void
    {
        $organization = $this->organizationPinnedToACaplessVersion();

        $this->runTheRepair();

        $limits = app(Limits::class);

        $this->assertSame(8, $limits->limitFor($organization, LimitKey::ActiveClasses)->value());
        $this->assertSame(300, $limits->limitFor($organization, LimitKey::ActiveStudents)->value());
    }

    #[Test]
    public function the_repaired_version_still_states_the_truth_about_itself(): void
    {
        $this->organizationPinnedToACaplessVersion();

        $this->runTheRepair();

        $version = PlanVersion::query()->where('version', 1)->firstOrFail();

        // A stale hash would make `EntitlementsSeeder` publish a spurious next
        // version on its very next run, over a composition that did not change.
        $this->assertSame($version->recomputeCompositionHash(), $version->composition_hash);
    }

    #[Test]
    public function a_version_that_already_states_its_caps_is_left_exactly_as_it_is(): void
    {
        $this->organizationPinnedToACaplessVersion();

        $untouched = PlanVersion::query()->where('version', 2)->firstOrFail();
        $before = [$untouched->limits, $untouched->composition_hash, (string) $untouched->updated_at];

        $this->runTheRepair();

        $after = $untouched->fresh();

        $this->assertSame($before, [$after->limits, $after->composition_hash, (string) $after->updated_at]);
    }

    /**
     * The `base` plan as a real installation held it: a version 1 frozen with
     * no caps, a version 2 that states them, and an organization contracted on
     * the first — which is where everybody who signed up before the limits
     * release still is.
     */
    protected function organizationPinnedToACaplessVersion(): Organization
    {
        $versionOne = PlanVersion::query()
            ->whereRelation('plan', 'key', 'base')
            ->where('version', 1)
            ->firstOrFail();

        $this->publishNextVersionOf('base', limits: ['active_classes' => 8, 'active_students' => 300]);

        // Raw, because `PlanVersion` refuses to be written to once published —
        // and so did the migration that caused this, which used the query
        // builder for the same reason.
        DB::table('plan_versions')->where('id', $versionOne->id)->update(['limits' => null]);

        $organization = Organization::factory()->create();

        // Pinned to version 1 explicitly. The shared helper subscribes to
        // whatever a plan sells TODAY, which is the one state this test may
        // not be in.
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => $versionOne->plan_id,
            'plan_version_id' => $versionOne->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(),
        ]);

        return $organization;
    }

    protected function runTheRepair(): void
    {
        $migration = require database_path(
            'migrations/2026_09_23_000100_backfill_limits_on_versions_published_without_them.php'
        );

        $migration->up();
    }
}
