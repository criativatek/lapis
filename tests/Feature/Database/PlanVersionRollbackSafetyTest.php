<?php

namespace Tests\Feature\Database;

use App\Models\BillingPeriod;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\PublishesPlanVersions;
use Tests\Concerns\RollsBackPlanVersions;
use Tests\TestCase;
use Throwable;

/**
 * A ROLLBACK THAT WOULD DESTROY CONTRACTUAL HISTORY REFUSES INSTEAD.
 *
 * The pre-ADR-0008 schema holds exactly ONE composition per plan — that
 * limitation is the reason this lot exists. So these migrations are reversible
 * in precisely one state: the one they leave behind when they first run, with a
 * single version per plan and no commercial condition recorded yet. Rolling
 * back there is the exact inverse of the backfill and loses nothing.
 *
 * After a v2 is published it stops being true, and not for want of care in the
 * `down()`. `organization_subscriptions.plan_id` cannot say «on Pro v1 while
 * others are on Pro v2», so a rollback would have to pick a composition, throw
 * the rest away, and drop the column that records which offer each customer
 * bought. That is evidence no later `migrate` can reconstruct, and nobody would
 * see it go.
 *
 * So the `down()`s refuse, loudly, and this file is what holds them to it. THE
 * SECOND HALF OF EVERY ASSERTION IS THAT NOTHING WAS DESTROYED ON THE WAY TO
 * REFUSING — a guard that throws after already dropping a table would be worse
 * than no guard at all.
 */
class PlanVersionRollbackSafetyTest extends TestCase
{
    use PublishesPlanVersions;
    use RefreshDatabase;
    use RollsBackPlanVersions;

    // ------------------------------------------- the state that IS reversible

    #[Test]
    public function rolling_back_immediately_after_the_migration_is_supported(): void
    {
        // One version per plan, no commercial condition recorded: exactly what
        // the migrations leave behind, and the only state they promise to undo.
        $this->assertSame(3, PlanVersion::count());

        $this->rollback();

        $this->assertTrue(Schema::hasTable('module_plan'), 'the legacy composition comes back');
        $this->assertTrue(Schema::hasColumn('plans', 'limits'));
        $this->assertFalse(Schema::hasTable('plan_versions'));
        $this->assertFalse(Schema::hasColumn('organization_subscriptions', 'plan_version_id'));

        // And the composition really is back, not merely the empty tables.
        $this->assertSame(13, DB::table('module_plan')->where('plan_id', $this->planId('base'))->count());
        $this->assertSame(28, DB::table('module_plan')->where('plan_id', $this->planId('pro'))->count());

        // Re-applicable, which is what makes it a rollback rather than a
        // one-way door.
        $this->artisan('migrate')->run();

        $this->assertTrue(Schema::hasTable('plan_versions'));
        $this->assertSame(3, PlanVersion::count());
        $this->assertSame(0, OrganizationSubscription::withoutGlobalScope('organization')->whereNull('plan_version_id')->count());
    }

    // ---------------------------------------- the states that are NOT

    #[Test]
    public function rolling_back_after_a_second_version_is_refused_and_destroys_nothing(): void
    {
        $this->publishNextVersionOf('pro');

        $message = $this->refusedRollback();

        $this->assertStringContainsString('Refusing to roll back', $message);
        $this->assertStringContainsString('pro', $message);
        $this->assertStringContainsString('more than one version', $message);

        $this->assertNothingWasDestroyed();

        // WHAT «NOTHING WAS DESTROYED» MEANS PRECISELY, because the rollback
        // does stop half-way: `migrate:rollback` reverses the migrations
        // newest-first, so the commercial-snapshot one goes before the version
        // one and succeeds — its columns are all NULL here, so there is nothing
        // in them to lose — and the version one then refuses. The database is left
        // consistent and one migration short, not corrupt; the next `migrate`
        // puts that migration back, which
        // `a_refused_rollback_leaves_a_database_that_still_works` asserts.
        $this->assertFalse(Schema::hasColumn('organization_subscriptions', 'contracted_price_cents'));
        $this->assertSame(
            2,
            DB::table('migrations')->where('migration', 'like', '2026_09_11%')->count(),
            'two of the three stay applied: the refusal stopped the rollback, it did not half-apply it',
        );
    }

    #[Test]
    public function rolling_back_with_subscribers_spread_across_versions_is_refused(): void
    {
        // The condition that names real customers rather than catalogue rows:
        // one organization grandfathered on v1, another sold v2.
        $grandfathered = $this->organization();
        app(ChangeOrganizationPlan::class)->to($grandfathered, Plan::where('key', 'pro')->firstOrFail());

        $this->publishNextVersionOf('pro');

        $newcomer = $this->organization();
        app(ChangeOrganizationPlan::class)->to($newcomer, Plan::where('key', 'pro')->firstOrFail());

        $this->assertSame(
            2,
            OrganizationSubscription::withoutGlobalScope('organization')
                ->where('plan_id', $this->planId('pro'))
                ->distinct()
                ->count('plan_version_id'),
        );

        $this->assertStringContainsString('Refusing to roll back', $this->refusedRollback());

        $this->assertNothingWasDestroyed();
    }

    #[Test]
    public function rolling_back_an_account_on_the_promotional_condition_is_refused(): void
    {
        // «promotional» is a value THIS lot added to the CHECK. Narrowing the
        // constraint back with such a row present made MySQL refuse the ALTER
        // itself, with a message that named the constraint and not the account
        // — and that is what had CI red. The guard turns it into a refusal that
        // says which accounts are in the way, before anything is dropped.
        $organization = $this->organization();

        // O instantâneo comercial primeiro, senão é a OUTRA guarda que responde
        // — a adesão ao Base grava preço e período, e esta afirmação passaria
        // por uma recusa que nada tem a ver com a condição.
        $this->clearCommercialSnapshots();

        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->update(['commercial_condition' => 'promotional']);

        $message = $this->refusedRollback();

        $this->assertStringContainsString('Refusing to roll back', $message);
        $this->assertStringContainsString('promotional', $message);

        $this->assertSame(
            1,
            OrganizationSubscription::withoutGlobalScope('organization')
                ->where('commercial_condition', 'promotional')
                ->count(),
            'the condition survived the refusal',
        );
        $this->assertNothingWasDestroyed();
    }

    #[Test]
    public function rolling_back_a_recorded_commercial_condition_is_refused(): void
    {
        // The snapshot columns are immutable proof of what was agreed. Dropping
        // them is the same class of loss as dropping the contracted version,
        // and it would happen FIRST in a three-step rollback — before the
        // version guard ever ran — so it needs its own.
        $organization = $this->organization();

        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => $this->planId('base'),
            'status' => 'active',
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
            'contracted_price_cents' => 0,
            'contracted_currency' => 'EUR',
            'billing_period' => BillingPeriod::None,
            'commercial_term_ends_at' => now()->addYear(),
        ]);

        $message = $this->refusedRollback();

        $this->assertStringContainsString('Refusing to roll back', $message);
        $this->assertStringContainsString('recorded commercial condition', $message);

        // Refused BEFORE touching anything: the four columns are still there,
        // and so is the value in them.
        $this->assertTrue(Schema::hasColumn('organization_subscriptions', 'contracted_price_cents'));
        $this->assertSame(
            1,
            OrganizationSubscription::withoutGlobalScope('organization')->whereNotNull('contracted_currency')->count(),
        );
        $this->assertNothingWasDestroyed();
    }

    #[Test]
    public function a_refused_rollback_leaves_a_database_that_still_works(): void
    {
        $organization = $this->organization();
        app(ChangeOrganizationPlan::class)->to($organization, Plan::where('key', 'pro')->firstOrFail());

        $this->publishNextVersionOf('pro');
        $this->refusedRollback();

        // `migrate` is idempotent afterwards — whatever the failed rollback
        // undid before the refusal comes back, and the resolvers answer.
        $this->artisan('migrate')->run();

        $this->assertTrue(Schema::hasColumn('organization_subscriptions', 'contracted_price_cents'));
        $this->assertSame(
            1,
            app(ChangeOrganizationPlan::class)->inForce($organization->fresh())?->planVersion->version,
            'the grandfathered subscription still names the version it contracted',
        );
    }

    // ------------------------------------------------------------- helpers

    /**
     * DELIBERATELY NOT `rollBackPlanVersionLot()`. That helper clears the
     * commercial snapshot first, which is right for a test reconstructing the
     * pre-0.88 world — and exactly wrong here, where the refusals ARE the
     * subject. This file rolls back with the data as it stands and asserts what
     * the guards do about it, so only the step count is shared.
     */
    private function rollback(): void
    {
        $this->artisan('migrate:rollback', ['--step' => $this->stepsBackToPlanVersions()])->run();
    }

    /**
     * Runs the rollback expecting it to be refused, and returns the message.
     */
    private function refusedRollback(): string
    {
        try {
            $this->rollback();
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        } catch (Throwable $exception) {
            $this->fail('the rollback failed with an unexpected exception: '.$exception->getMessage());
        }

        $this->fail('the rollback was allowed to proceed and would have destroyed contractual history');
    }

    /**
     * The versioned schema and its rows survived the refusal intact — the half
     * of the guarantee a thrown exception alone does not give.
     */
    private function assertNothingWasDestroyed(): void
    {
        $this->assertTrue(Schema::hasTable('plan_versions'));
        $this->assertTrue(Schema::hasTable('module_plan_version'));
        $this->assertTrue(Schema::hasColumn('organization_subscriptions', 'plan_version_id'));
        $this->assertFalse(Schema::hasTable('module_plan'), 'the legacy table must not be half-rebuilt');

        $this->assertGreaterThan(0, PlanVersion::count());
        $this->assertSame(
            0,
            OrganizationSubscription::withoutGlobalScope('organization')->whereNull('plan_version_id')->count(),
        );
    }

    private function planId(string $key): int
    {
        return Plan::where('key', $key)->firstOrFail()->getKey();
    }

    private function organization(): Organization
    {
        return User::factory()->create()->personalOrganization()->fresh();
    }
}
