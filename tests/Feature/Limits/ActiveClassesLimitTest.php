<?php

namespace Tests\Feature\Limits;

use App\Models\AcademicYear;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Limits\LimitKey;
use App\Support\Limits\Limits;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `active_classes` enforcement (§Lote 3) at the one place it can be grown —
 * `ClassService::create()`, reached in production through `classes.store`
 * (`ClassController::store`) — exercised here through the real HTTP route,
 * the same way `ClassTest`/`ActiveEnrollmentTest` already do for the rest of
 * the classes feature.
 */
class ActiveClassesLimitTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * @return array{year: int, subject: int}
     */
    protected function context(): array
    {
        return app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), fn (): array => [
            'year' => AcademicYear::factory()->recycle($this->user->personalOrganization())
                ->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30'])->id,
            'subject' => Subject::factory()->recycle($this->user->personalOrganization())->create()->id,
        ]);
    }

    protected function postClass(array $context, string $label): TestResponse
    {
        return $this->actingAs($this->user)->post('/classes', [
            'label' => $label,
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);
    }

    protected function seedActiveClasses(int $count): void
    {
        $organization = $this->user->personalOrganization();

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $count): void {
            SchoolClass::factory()->recycle($organization)->count($count)->create(['status' => 'active']);
        });
    }

    protected function activeClassCount(): int
    {
        return app(Limits::class)->usageFor($this->user->personalOrganization()->fresh(), LimitKey::ActiveClasses);
    }

    // ------------------------------------------------------- 1 / 2: o limite

    #[Test]
    public function base_with_seven_active_classes_can_create_the_eighth(): void
    {
        $this->seedActiveClasses(7);
        $context = $this->context();

        $this->postClass($context, '7.º A')->assertRedirect();

        $this->assertSame(8, $this->activeClassCount());
    }

    #[Test]
    public function base_with_eight_active_classes_is_blocked_from_a_ninth(): void
    {
        $this->seedActiveClasses(8);
        $context = $this->context();

        $this->postClass($context, '7.º A')
            ->assertRedirect()
            ->assertSessionHasErrors('limit');

        $this->assertSame(8, $this->activeClassCount(), 'the blocked attempt created nothing');
    }

    // ------------------------------------------------------- 3: o que conta

    #[Test]
    public function a_closed_or_archived_class_does_not_count_towards_usage(): void
    {
        $organization = $this->user->personalOrganization();

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            SchoolClass::factory()->recycle($organization)->count(3)->create(['status' => 'active']);
            SchoolClass::factory()->recycle($organization)->count(4)->create(['status' => 'closed']);
            SchoolClass::factory()->recycle($organization)->count(4)->create(['status' => 'archived']);
        });

        $this->assertSame(3, $this->activeClassCount());
    }

    // ------------------------------------------------- 4 / 5: acima do limite

    #[Test]
    public function an_organization_already_above_eight_keeps_every_class(): void
    {
        $this->seedActiveClasses(12);

        $this->assertSame(
            12,
            SchoolClass::withoutGlobalScope('organization')
                ->where('organization_id', $this->user->personalOrganization()->getKey())
                ->count(),
        );
    }

    #[Test]
    public function an_organization_already_above_eight_cannot_create_another_class(): void
    {
        $this->seedActiveClasses(12);
        $context = $this->context();

        $this->postClass($context, '7.º A')->assertSessionHasErrors('limit');

        $this->assertSame(12, $this->activeClassCount());
    }

    // ---------------------------------------------------- 6: reduzir liberta

    #[Test]
    public function dropping_back_to_seven_allows_creating_up_to_eight_again(): void
    {
        $this->seedActiveClasses(8);
        $organization = $this->user->personalOrganization();

        // No archive UI exists yet (§Lote 3) — this is the status transition
        // the resolver itself reacts to, applied directly as a stand-in.
        $oneClassId = SchoolClass::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->value('id');
        SchoolClass::withoutGlobalScope('organization')->whereKey($oneClassId)->update(['status' => 'closed']);

        $context = $this->context();
        $this->postClass($context, '7.º A')->assertRedirect();

        $this->assertSame(8, $this->activeClassCount());
    }

    // -------------------------------------------------- 7 / 8: ilimitado

    #[Test]
    public function a_pro_organization_is_unlimited_even_with_many_classes(): void
    {
        $organization = $this->user->personalOrganization();
        app(ChangeOrganizationPlan::class)->to($organization->fresh(), Plan::where('key', 'pro')->firstOrFail());
        $this->seedActiveClasses(50);

        $context = $this->context();
        $this->postClass($context, '7.º A')->assertRedirect();

        $this->assertSame(51, $this->activeClassCount());
    }

    #[Test]
    public function an_institutional_organization_defaults_to_unlimited(): void
    {
        $organization = $this->user->personalOrganization();
        app(ChangeOrganizationPlan::class)->to($organization->fresh(), Plan::where('key', 'institutional')->firstOrFail());
        $this->seedActiveClasses(50);

        $context = $this->context();
        $this->postClass($context, '7.º A')->assertRedirect();

        $this->assertSame(51, $this->activeClassCount());
    }

    // --------------------------------------------------------- 9: zero real

    #[Test]
    public function a_finite_zero_limit_blocks_the_very_first_class(): void
    {
        // Proves LimitValue::finite(0) is a real, distinct state — never
        // confused with null/false/unlimited — reached the same way any
        // other Base limit is: through the persisted plan configuration.
        Plan::where('key', 'base')->firstOrFail()->update([
            'limits' => ['active_classes' => 0, 'active_students' => 300],
        ]);

        $context = $this->context();
        $this->postClass($context, '7.º A')->assertSessionHasErrors('limit');

        $this->assertSame(0, $this->activeClassCount());
    }

    // ---------------------------------------------- 10: o boundary HTTP

    #[Test]
    public function the_http_route_itself_is_blocked_not_only_the_underlying_service(): void
    {
        // Classes are seeded directly (bypassing ClassService entirely) so
        // this test isolates the ROUTE's own enforcement: even though
        // nothing called assertCanIncreaseFor() to get to 8, classes.store
        // still refuses the 9th.
        $this->seedActiveClasses(8);
        $context = $this->context();

        $response = $this->postClass($context, '7.º A');

        $response->assertRedirect();
        $response->assertSessionHasErrors('limit');
        $this->assertSame(8, $this->activeClassCount());
    }
}
