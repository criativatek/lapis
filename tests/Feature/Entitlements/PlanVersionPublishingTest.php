<?php

namespace Tests\Feature\Entitlements;

use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PublishesPlanVersions;
use Tests\Concerns\RollsBackPlanVersions;
use Tests\TestCase;

/**
 * THE SEEDER PUBLISHES, AND A PUBLISHED VERSION NEVER MOVES AGAIN.
 *
 * ADR-0008 §4. `EntitlementsSeeder` is still the one place where the
 * composition of Base / Pro / Institucional is written down — that was never
 * the problem. The problem was that it `sync()`ed it over the live plan on
 * every run, so `db:seed` was a retroactive rewrite of everyone's entitlements
 * wearing the clothes of a reference-data refresh.
 *
 * What replaces it has to hold two properties at once, and they pull in
 * opposite directions: running it twice must create ONE version (or a routine
 * deploy would fill the table with identical rows and make every subscriber
 * look stale), and running it after a real change must create EXACTLY one more
 * (or the change would silently not ship).
 */
class PlanVersionPublishingTest extends TestCase
{
    use PublishesPlanVersions;
    use RefreshDatabase;
    use RollsBackPlanVersions;

    // ------------------------------------------------------ v1 of the 0.87

    #[Test]
    public function version_one_is_the_realigned_zero_eighty_seven_composition(): void
    {
        // The exact counts of the Base/Pro realignment (664c36f), not a
        // pre-Bloco-B composition. ADR-0008 is explicit that the order matters:
        // grandfathering the composition from BEFORE the realignment would have
        // frozen a defect — a Base organization unable to open the calendar it
        // had itself defined — into every existing subscription.
        $this->assertSame(13, $this->currentVersionOf('base')->modules()->count());
        $this->assertSame(28, $this->currentVersionOf('pro')->modules()->count());
        $this->assertSame(34, $this->currentVersionOf('institutional')->modules()->count());

        // Pro is Base plus 15; Institucional is Pro plus 6. Asserted as
        // containment rather than as arithmetic on the counts, because the
        // counts matching while the SETS diverged is the failure this is for.
        $base = $this->keysOf('base');
        $pro = $this->keysOf('pro');
        $institutional = $this->keysOf('institutional');

        $this->assertSame([], array_diff($base, $pro));
        $this->assertSame([], array_diff($pro, $institutional));
        $this->assertCount(15, array_diff($pro, $base));
        $this->assertCount(6, array_diff($institutional, $pro));

        // The realignment's own two moves, by name.
        $this->assertContains('calendar', $base, 'the calendar went to Base in the realignment');
        $this->assertNotContains('calendar_import', $base, 'only the advanced import stayed Pro');
        $this->assertContains('calendar_import', $pro);
    }

    #[Test]
    public function every_plan_has_exactly_one_version_after_a_fresh_seed(): void
    {
        $this->assertSame(3, PlanVersion::count());

        foreach (Plan::all() as $plan) {
            $this->assertSame(1, $plan->versions()->count());
            $this->assertSame(1, $plan->currentVersionOrFail()->version);
            $this->assertNotNull($plan->currentVersionOrFail()->published_at);
            $this->assertNull($plan->currentVersionOrFail()->retired_at);
        }
    }

    #[Test]
    public function the_stored_hash_describes_what_the_version_actually_carries(): void
    {
        foreach (PlanVersion::all() as $version) {
            $this->assertSame(
                $version->composition_hash,
                $version->recomputeCompositionHash(),
                'a stale hash would make the seeder publish a version nobody asked for',
            );
        }
    }

    // ------------------------------------------------------- idempotence

    #[Test]
    public function running_the_seeder_again_publishes_nothing(): void
    {
        $before = PlanVersion::orderBy('id')->get()->map->only(['id', 'plan_id', 'version', 'composition_hash'])->all();

        $this->seed(EntitlementsSeeder::class);
        $this->seed(EntitlementsSeeder::class);

        $this->assertSame(3, PlanVersion::count());
        $this->assertSame($before, PlanVersion::orderBy('id')->get()->map->only(['id', 'plan_id', 'version', 'composition_hash'])->all());
    }

    #[Test]
    public function the_first_seed_after_the_migration_finds_v1_equivalent_and_publishes_nothing(): void
    {
        // THE UPGRADE PATH, and the one place the two hash implementations meet:
        // the backfill migration computes v1's hash from the LEGACY tables, and
        // the seeder computes its own from the constants in its own file. If
        // those ever produced different values, the first `db:seed` after a
        // deploy would publish an identical v2 and make every existing
        // subscriber look stale overnight.
        //
        // Reproduced against real legacy data rather than asserted about: the
        // migrations are rolled back (restoring `module_plan` and
        // `plans.limits`), applied again so the backfill runs over them, and
        // only then is the seeder allowed to have an opinion.
        $organization = User::factory()->create()->personalOrganization()->fresh();
        app(ChangeOrganizationPlan::class)->to($organization, Plan::where('key', 'pro')->firstOrFail());
        $this->normalisePromotionalFixtures();

        $this->rollBackPlanVersionLot();
        $this->artisan('migrate')->run();

        $backfilled = PlanVersion::orderBy('id')->get()->map->only(['id', 'plan_id', 'version', 'composition_hash', 'limits'])->all();
        $subscriptions = OrganizationSubscription::withoutGlobalScope('organization')
            ->orderBy('id')->pluck('plan_version_id')->all();

        $this->seed(EntitlementsSeeder::class);

        $this->assertSame(3, PlanVersion::count(), 'the seeder recognised the backfilled v1 as its own composition');
        $this->assertSame(
            $backfilled,
            PlanVersion::orderBy('id')->get()->map->only(['id', 'plan_id', 'version', 'composition_hash', 'limits'])->all(),
            'v1 was not touched',
        );

        // Twice more, because idempotence that only holds on the first re-run
        // is not idempotence.
        $this->seed(EntitlementsSeeder::class);
        $this->seed(EntitlementsSeeder::class);

        $this->assertSame(3, PlanVersion::count());
        $this->assertSame(
            $subscriptions,
            OrganizationSubscription::withoutGlobalScope('organization')->orderBy('id')->pluck('plan_version_id')->all(),
            'no subscription was moved by seeding',
        );
    }

    #[Test]
    public function publishing_a_v2_leaves_v1_and_its_subscribers_exactly_where_they_were(): void
    {
        $organization = User::factory()->create()->personalOrganization()->fresh();
        app(ChangeOrganizationPlan::class)->to($organization, Plan::where('key', 'pro')->firstOrFail());

        $v1 = $this->currentVersionOf('pro');
        // The RAW stored attributes, every column of them — timestamps as the
        // strings the database holds, so this compares what is on disk rather
        // than two Carbon objects that are never identical.
        $v1Row = $v1->getAttributes();
        $v1Modules = $v1->modules()->pluck('key')->sort()->values()->all();
        $contracted = app(ChangeOrganizationPlan::class)->inForce($organization->fresh())?->plan_version_id;

        $this->publishNextVersionOf('pro', moduleKeys: ['classes']);

        $this->assertSame($v1Row, $v1->fresh()->getAttributes());
        $this->assertSame($v1Modules, $v1->fresh()->modules()->pluck('key')->sort()->values()->all());
        $this->assertSame($contracted, app(ChangeOrganizationPlan::class)->inForce($organization->fresh())?->plan_version_id);
    }

    #[Test]
    public function a_genuinely_different_composition_publishes_exactly_one_new_version(): void
    {
        $this->publishNextVersionOf('pro', moduleKeys: ['classes', 'students']);

        $this->assertSame(2, Plan::where('key', 'pro')->firstOrFail()->versions()->count());
        $this->assertSame(2, $this->currentVersionOf('pro')->version);

        // And the seeder, meeting a composition that differs from the last
        // published one, publishes v3 — once, not once per run.
        $this->seed(EntitlementsSeeder::class);
        $this->assertSame(3, $this->currentVersionOf('pro')->version);

        $this->seed(EntitlementsSeeder::class);
        $this->assertSame(3, $this->currentVersionOf('pro')->version);
    }

    #[Test]
    public function a_changed_limit_alone_is_a_new_version(): void
    {
        // Modules unchanged, caps changed. A version is what was SOLD, and a
        // plan that keeps its capabilities and halves its turma cap is selling
        // something else.
        $this->publishNextVersionOf('base', limits: ['active_classes' => 20, 'active_students' => 300]);

        $this->assertSame(2, $this->currentVersionOf('base')->version);
        $this->assertNotSame(
            Plan::where('key', 'base')->firstOrFail()->versions()->where('version', 1)->firstOrFail()->composition_hash,
            $this->currentVersionOf('base')->composition_hash,
        );
    }

    #[Test]
    public function the_hash_ignores_the_order_the_module_keys_arrive_in(): void
    {
        $keys = $this->keysOf('pro');
        $shuffled = $keys;
        shuffle($shuffled);

        $this->assertSame(
            PlanVersion::compositionHash($keys, ['a' => 1, 'b' => 2]),
            PlanVersion::compositionHash($shuffled, ['b' => 2, 'a' => 1]),
            'row order or key order must never look like a change of offer',
        );
    }

    // -------------------------------------------------------- immutability

    #[Test]
    public function a_published_version_refuses_to_have_its_composition_rewritten(): void
    {
        $version = $this->currentVersionOf('pro');
        $publishedHash = $version->composition_hash;

        foreach ([['limits' => ['active_classes' => 1]], ['version' => 9], ['composition_hash' => 'x'], ['plan_id' => 1]] as $change) {
            try {
                $version->update($change);
                $this->fail('a published version accepted a change to '.array_key_first($change));
            } catch (LogicException $exception) {
                $this->assertStringContainsString('immutable', $exception->getMessage());
            }
        }

        // Untouched ON DISK, which is the claim — a refused save leaves the
        // in-memory model dirty, and reading it back is what proves nothing
        // reached the database.
        $fresh = $this->currentVersionOf('pro');
        $this->assertSame(1, $fresh->version);
        $this->assertSame($publishedHash, $fresh->composition_hash);
        $this->assertSame(['active_classes' => 'unlimited', 'active_students' => 'unlimited'], $fresh->limits);
    }

    #[Test]
    public function retiring_a_version_is_allowed_and_changes_nothing_for_its_subscribers(): void
    {
        // The one field that may move after publication: a version can stop
        // being sellable without anyone on it being touched.
        $v1 = $this->currentVersionOf('pro');
        $v2 = $this->publishNextVersionOf('pro');

        $v1->update(['retired_at' => now(), 'notes' => 'superseded']);

        $this->assertNotNull($v1->fresh()->retired_at);
        $this->assertFalse($v1->fresh()->isCurrentlySellable());
        $this->assertSame($v2->getKey(), $this->currentVersionOf('pro')->getKey());
    }

    #[Test]
    public function a_version_with_subscribers_cannot_be_deleted(): void
    {
        // A real subscriber, because the restriction is exactly about there
        // being one: an empty version is deletable and that is fine.
        User::factory()->create();

        $version = $this->currentVersionOf('base');
        $this->assertGreaterThan(0, $version->subscriptions()->count());

        // `restrictOnDelete` — history is not tidied away, and the guarantee is
        // the database's rather than a convention.
        $this->expectException(QueryException::class);

        DB::table('plan_versions')->where('id', $version->getKey())->delete();
    }

    /**
     * @return list<string>
     */
    private function keysOf(string $planKey): array
    {
        return $this->currentVersionOf($planKey)->modules()->pluck('key')->sort()->values()->all();
    }
}
