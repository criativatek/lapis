<?php

namespace Tests\Feature\Limits;

use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Limits\LimitKey;
use App\Support\Limits\Limits;
use App\Support\Limits\LimitValue;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\PublishesPlanVersions;
use Tests\TestCase;

/**
 * `Limits` in isolation — the resolver itself, independent of the two
 * enforcement call sites `ActiveClassesLimitTest`/`ActiveStudentsLimitTest`
 * exercise through HTTP. Mirrors `AccessStateTest`'s split for the same
 * reason: one file for the algorithm, separate files for each call site.
 */
class LimitsResolverTest extends TestCase
{
    use PublishesPlanVersions;
    use RefreshDatabase;

    protected function organization(): Organization
    {
        // Base plan, subscribed at creation — see CreatePersonalOrganization.
        return User::factory()->create()->personalOrganization();
    }

    protected function subscribeTo(Organization $organization, string $planKey): void
    {
        app(ChangeOrganizationPlan::class)->to($organization->fresh(), Plan::where('key', $planKey)->firstOrFail());
    }

    // --------------------------------------------------------- LimitValue

    #[Test]
    public function limit_value_distinguishes_finite_zero_finite_positive_and_unlimited(): void
    {
        $zero = LimitValue::finite(0);
        $eight = LimitValue::finite(8);
        $unlimited = LimitValue::unlimited();

        $this->assertFalse($zero->isUnlimited());
        $this->assertSame(0, $zero->value());

        $this->assertFalse($eight->isUnlimited());
        $this->assertSame(8, $eight->value());

        $this->assertTrue($unlimited->isUnlimited());
    }

    #[Test]
    public function limit_value_throws_when_value_is_read_on_an_unlimited_instance(): void
    {
        $this->expectException(LogicException::class);

        LimitValue::unlimited()->value();
    }

    #[Test]
    public function limit_value_rejects_a_negative_finite(): void
    {
        $this->expectException(LogicException::class);

        LimitValue::finite(-1);
    }

    #[Test]
    public function zero_blocks_any_increase_and_is_never_confused_with_unlimited(): void
    {
        // Proves finite(0) is its own real state, not null/false/unlimited in
        // disguise — the exact concern item 9 of the Turmas test list names.
        $zero = LimitValue::finite(0);

        $this->assertFalse($zero->isUnlimited());
        $this->assertFalse($zero->accommodates(0, 1));
        $this->assertTrue(LimitValue::unlimited()->accommodates(1_000_000, 1));
    }

    // ------------------------------------------------------ seeded plans

    #[Test]
    public function a_base_organization_has_the_seeded_finite_limits(): void
    {
        $organization = $this->organization();
        $limits = app(Limits::class);

        $this->assertSame(8, $limits->limitFor($organization->fresh(), LimitKey::ActiveClasses)->value());
        $this->assertSame(300, $limits->limitFor($organization->fresh(), LimitKey::ActiveStudents)->value());
    }

    #[Test]
    public function a_pro_organization_is_unlimited_on_both_keys(): void
    {
        $organization = $this->organization();
        $this->subscribeTo($organization, 'pro');
        $limits = app(Limits::class);

        $this->assertTrue($limits->limitFor($organization->fresh(), LimitKey::ActiveClasses)->isUnlimited());
        $this->assertTrue($limits->limitFor($organization->fresh(), LimitKey::ActiveStudents)->isUnlimited());
    }

    #[Test]
    public function an_institutional_organization_defaults_to_unlimited_on_both_keys(): void
    {
        // Temporary default (§Lote 3): the real institutional contractual cap
        // is out of scope here and left for a future Lote.
        $organization = $this->organization();
        $this->subscribeTo($organization, 'institutional');
        $limits = app(Limits::class);

        $this->assertTrue($limits->limitFor($organization->fresh(), LimitKey::ActiveClasses)->isUnlimited());
        $this->assertTrue($limits->limitFor($organization->fresh(), LimitKey::ActiveStudents)->isUnlimited());
    }

    // ------------------------------------------------------------ usage()

    #[Test]
    public function usage_for_classes_counts_only_preparation_and_active_statuses(): void
    {
        $organization = $this->organization();

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            SchoolClass::factory()->recycle($organization)->count(2)->create(['status' => 'preparation']);
            SchoolClass::factory()->recycle($organization)->count(3)->create(['status' => 'active']);
            SchoolClass::factory()->recycle($organization)->count(4)->create(['status' => 'closed']);
            SchoolClass::factory()->recycle($organization)->count(5)->create(['status' => 'archived']);
        });

        $this->assertSame(5, app(Limits::class)->usageFor($organization->fresh(), LimitKey::ActiveClasses));
    }

    #[Test]
    public function usage_for_students_counts_distinct_students_not_enrollment_rows(): void
    {
        // Item 4 of the Alunos test list: one student on two active turmas is
        // one unit, not two — asserted directly against the resolver.
        $organization = $this->organization();

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            $student = Student::factory()->recycle($organization)->create();
            $classA = SchoolClass::factory()->recycle($organization)->create(['status' => 'active']);
            $classB = SchoolClass::factory()->recycle($organization)->create(['status' => 'active']);

            Enrollment::factory()->recycle($organization)->create(['class_id' => $classA->id, 'student_id' => $student->id, 'status' => 'active']);
            Enrollment::factory()->recycle($organization)->create(['class_id' => $classB->id, 'student_id' => $student->id, 'status' => 'active']);
        });

        $this->assertSame(1, app(Limits::class)->usageFor($organization->fresh(), LimitKey::ActiveStudents));
    }

    #[Test]
    public function usage_for_students_ignores_purely_historical_enrollments(): void
    {
        $organization = $this->organization();

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            $class = SchoolClass::factory()->recycle($organization)->create(['status' => 'active']);

            Enrollment::factory()->recycle($organization)->create(['class_id' => $class->id, 'status' => 'transferred_out']);
            Enrollment::factory()->recycle($organization)->create(['class_id' => $class->id, 'status' => 'left']);
            Enrollment::factory()->recycle($organization)->create(['class_id' => $class->id, 'status' => 'concluded']);
        });

        $this->assertSame(0, app(Limits::class)->usageFor($organization->fresh(), LimitKey::ActiveStudents));
    }

    // -------------------------------------------------------- remaining()

    #[Test]
    public function remaining_for_a_finite_limit_is_the_limit_minus_usage(): void
    {
        $organization = $this->organization();

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            SchoolClass::factory()->recycle($organization)->count(3)->create(['status' => 'active']);
        });

        $remaining = app(Limits::class)->remainingFor($organization->fresh(), LimitKey::ActiveClasses);

        $this->assertFalse($remaining->isUnlimited());
        $this->assertSame(5, $remaining->value());
    }

    #[Test]
    public function remaining_never_goes_negative_when_usage_already_exceeds_the_limit(): void
    {
        $organization = $this->organization();

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            SchoolClass::factory()->recycle($organization)->count(12)->create(['status' => 'active']);
        });

        $remaining = app(Limits::class)->remainingFor($organization->fresh(), LimitKey::ActiveClasses);

        $this->assertFalse($remaining->isUnlimited());
        $this->assertSame(0, $remaining->value(), 'Above the limit reads as zero remaining, never a negative number.');
    }

    #[Test]
    public function remaining_for_unlimited_stays_the_explicit_unlimited_value_never_a_huge_number(): void
    {
        $organization = $this->organization();
        $this->subscribeTo($organization, 'pro');

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            SchoolClass::factory()->recycle($organization)->count(50)->create(['status' => 'active']);
        });

        $remaining = app(Limits::class)->remainingFor($organization->fresh(), LimitKey::ActiveClasses);

        $this->assertTrue($remaining->isUnlimited());
    }

    // ----------------------------------------------------- canIncreaseFor()

    #[Test]
    public function can_increase_for_is_false_exactly_at_the_limit_and_true_just_below_it(): void
    {
        $organization = $this->organization();
        $limits = app(Limits::class);

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            SchoolClass::factory()->recycle($organization)->count(7)->create(['status' => 'active']);
        });
        $this->assertTrue($limits->canIncreaseFor($organization->fresh(), LimitKey::ActiveClasses));

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            SchoolClass::factory()->recycle($organization)->create(['status' => 'active']);
        });
        $this->assertFalse($limits->canIncreaseFor($organization->fresh(), LimitKey::ActiveClasses));
    }

    #[Test]
    public function assert_can_increase_for_throws_a_validation_exception_quoting_the_real_configured_limit(): void
    {
        $organization = $this->organization();

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            SchoolClass::factory()->recycle($organization)->count(8)->create(['status' => 'active']);
        });

        try {
            app(Limits::class)->assertCanIncreaseFor($organization->fresh(), LimitKey::ActiveClasses);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            $message = $exception->validator->errors()->first('limit');
            $this->assertStringContainsString('8', $message);
            $this->assertNotSame('', trim($message));
        }
    }

    // --------------------------------------------------------- above limit

    #[Test]
    public function usage_above_the_limit_is_a_preserved_permanent_state_nothing_is_removed(): void
    {
        $organization = $this->organization();

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            SchoolClass::factory()->recycle($organization)->count(12)->create(['status' => 'active']);
        });

        $limits = app(Limits::class);

        $this->assertSame(12, $limits->usageFor($organization->fresh(), LimitKey::ActiveClasses));
        $this->assertFalse($limits->canIncreaseFor($organization->fresh(), LimitKey::ActiveClasses));
        $this->assertSame(
            12,
            SchoolClass::withoutGlobalScope('organization')->where('organization_id', $organization->getKey())->count(),
            'Nothing was deleted just by being over the limit.',
        );
    }

    #[Test]
    public function dropping_back_under_the_limit_allows_increasing_again(): void
    {
        $organization = $this->organization();
        $limits = app(Limits::class);

        $classes = app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => SchoolClass::factory()->recycle($organization)->count(8)->create(['status' => 'active']),
        );
        $this->assertFalse($limits->canIncreaseFor($organization->fresh(), LimitKey::ActiveClasses));

        // "Reducing" today only ever means the status changing away from the
        // counted cluster — there is no archive/delete UI yet (§Lote 3).
        $classes->first()->update(['status' => 'closed']);

        $this->assertSame(7, $limits->usageFor($organization->fresh(), LimitKey::ActiveClasses));
        $this->assertTrue($limits->canIncreaseFor($organization->fresh(), LimitKey::ActiveClasses));
    }

    // ------------------------------------------------------- plan changes

    #[Test]
    public function a_plan_change_is_reflected_on_the_very_next_call(): void
    {
        $organization = $this->organization();
        $limits = app(Limits::class);

        $this->assertSame(8, $limits->limitFor($organization->fresh(), LimitKey::ActiveClasses)->value());

        $this->subscribeTo($organization, 'pro');

        $this->assertTrue($limits->limitFor($organization->fresh(), LimitKey::ActiveClasses)->isUnlimited());
    }

    // ------------------------------------------------------ tenant safety

    #[Test]
    public function usage_and_limit_never_leak_between_two_organizations(): void
    {
        $organizationA = $this->organization();
        $organizationB = $this->organization();
        $this->subscribeTo($organizationB, 'pro');

        app(CurrentOrganization::class)->runFor($organizationA, function () use ($organizationA): void {
            SchoolClass::factory()->recycle($organizationA)->count(3)->create(['status' => 'active']);
        });
        app(CurrentOrganization::class)->runFor($organizationB, function () use ($organizationB): void {
            SchoolClass::factory()->recycle($organizationB)->count(20)->create(['status' => 'active']);
        });

        $limits = app(Limits::class);

        $this->assertSame(3, $limits->usageFor($organizationA->fresh(), LimitKey::ActiveClasses));
        $this->assertSame(8, $limits->limitFor($organizationA->fresh(), LimitKey::ActiveClasses)->value());

        $this->assertSame(20, $limits->usageFor($organizationB->fresh(), LimitKey::ActiveClasses));
        $this->assertTrue($limits->limitFor($organizationB->fresh(), LimitKey::ActiveClasses)->isUnlimited());
    }

    // ----------------------------------------------- 8/300 are not hardcoded

    #[Test]
    public function changing_the_persisted_base_limit_changes_behavior_without_touching_any_code(): void
    {
        // The explicit proof the brief asks for (§25): change ONLY the
        // persisted configuration and watch `Limits`' behaviour follow, with
        // zero production-code changes. Since ADR-0008 that configuration is
        // a PUBLISHED VERSION rather than a mutable column — so the version
        // is published FIRST and the organization created after it, which is
        // what makes it a customer of the new offer rather than a
        // grandfathered one.
        $this->publishNextVersionOf('base', limits: ['active_classes' => 2, 'active_students' => 300]);

        $organization = $this->organization();
        $limits = app(Limits::class);

        $this->assertSame(2, $limits->limitFor($organization->fresh(), LimitKey::ActiveClasses)->value());

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            SchoolClass::factory()->recycle($organization)->create(['status' => 'active']);
        });
        $this->assertTrue($limits->canIncreaseFor($organization->fresh(), LimitKey::ActiveClasses), '1 of 2 used — a 2nd is still allowed.');

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            SchoolClass::factory()->recycle($organization)->create(['status' => 'active']);
        });
        $this->assertFalse($limits->canIncreaseFor($organization->fresh(), LimitKey::ActiveClasses), '2 of 2 used — a 3rd must be blocked.');
    }

    // --------------------------------------------------- configuration errors

    #[Test]
    public function a_plan_missing_a_catalogued_limit_key_fails_loudly_instead_of_assuming_a_value(): void
    {
        $organization = $this->organization();
        $incomplete = $this->publishVersionOfNewPlan('incompleto', ['active_classes' => 5]);

        $this->subscribeToVersion($organization->fresh(), $incomplete);

        $this->expectException(RuntimeException::class);

        app(Limits::class)->limitFor($organization->fresh(), LimitKey::ActiveStudents);
    }

    #[Test]
    public function a_plan_with_an_invalid_limit_value_fails_loudly(): void
    {
        $organization = $this->organization();
        $invalid = $this->publishVersionOfNewPlan('invalido', ['active_classes' => 'muito', 'active_students' => 300]);

        $this->subscribeToVersion($organization->fresh(), $invalid);

        $this->expectException(RuntimeException::class);

        app(Limits::class)->limitFor($organization->fresh(), LimitKey::ActiveClasses);
    }

    /**
     * A misconfigured plan, published as its own v1.
     *
     * Written straight through the model rather than through
     * `PublishesPlanVersions` because the point here is a version whose
     * `limits` are WRONG, which the shared helper has no reason to make
     * convenient.
     *
     * @param  array<string, mixed>  $limits
     */
    private function publishVersionOfNewPlan(string $key, array $limits): PlanVersion
    {
        $plan = Plan::create(['key' => $key, 'name' => ucfirst($key)]);

        return $plan->versions()->create([
            'version' => 1,
            'limits' => $limits,
            'composition_hash' => PlanVersion::compositionHash([], $limits),
            'published_at' => Carbon::now(),
        ]);
    }

    private function subscribeToVersion(Organization $organization, PlanVersion $version): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => $version->plan_id,
            'plan_version_id' => $version->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now()->subDay(),
        ]);
    }

    #[Test]
    public function an_organization_with_no_subscription_at_all_gets_the_conservative_zero_floor(): void
    {
        // Never reachable in production (CreatePersonalOrganization always
        // subscribes on creation) — a defensive floor, tested directly by
        // bypassing that action, the same way Organization::factory() does
        // for every other "tenant-less" scenario in this suite.
        $organization = Organization::factory()->create();
        $limits = app(Limits::class);

        $this->assertSame(0, $limits->limitFor($organization, LimitKey::ActiveClasses)->value());
        $this->assertSame(0, $limits->usageFor($organization, LimitKey::ActiveClasses));
        $this->assertFalse($limits->canIncreaseFor($organization, LimitKey::ActiveClasses));
    }
}
