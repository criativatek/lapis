<?php

namespace Tests\Feature\Trial;

use App\Actions\Organizations\ActivateProTrial;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Entitlements\Entitlements;
use App\Support\Limits\LimitKey;
use App\Support\Limits\Limits;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Trial\TrialException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The trial's date mechanics: the exact isInForce() boundary between the
 * Trial and its dormant Base fallback, what Entitlements/Limits resolve on
 * each side of it, that pedagogical data created during the Pro window
 * survives untouched, and what happens when a paid upgrade lands DURING an
 * active trial (§Trial, building on SupersedingSubscriptionsTest's already-
 * tested supersede() rules).
 */
class ProTrialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function plan(string $key): Plan
    {
        return Plan::where('key', $key)->firstOrFail();
    }

    /**
     * @return Collection<int, OrganizationSubscription>
     */
    protected function subscriptions(Organization $organization): Collection
    {
        return OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->with('plan')
            ->orderBy('id')
            ->get();
    }

    protected function inForceCount(Organization $organization): int
    {
        return $this->subscriptions($organization)
            ->filter(fn (OrganizationSubscription $s): bool => $s->isInForce())
            ->count();
    }

    protected function freeze(CarbonInterface $at): CarbonInterface
    {
        $this->travelTo($at);

        return $at;
    }

    /**
     * @return array{year: int, subject: int}
     */
    protected function classContext(Organization $organization): array
    {
        return app(CurrentOrganization::class)->runFor($organization, fn (): array => [
            'year' => AcademicYear::factory()->recycle($organization)
                ->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30'])->id,
            'subject' => Subject::factory()->recycle($organization)->create()->id,
        ]);
    }

    // -------------------------------------------------------- the boundary

    #[Test]
    public function is_in_force_transitions_exactly_at_the_boundary_with_no_gap_and_no_overlap(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        $trial = app(ActivateProTrial::class)->activate($user, $organization->fresh());
        $fallback = $this->subscriptions($organization)->last();

        $this->travelTo($trial->ends_at->subSecond());
        $this->assertTrue($trial->fresh()->isInForce(), 'one second before expiry: Trial still in force');
        $this->assertFalse($fallback->fresh()->isInForce());
        $this->assertSame(1, $this->inForceCount($organization));

        $this->travelTo($trial->ends_at);
        $this->assertFalse($trial->fresh()->isInForce(), 'exactly at ends_at: exclusive, Trial no longer in force');
        $this->assertTrue($fallback->fresh()->isInForce(), 'exactly at ends_at: inclusive starts_at, Base fallback now in force');
        $this->assertSame(1, $this->inForceCount($organization));

        $this->travelTo($trial->ends_at->addSecond());
        $this->assertFalse($trial->fresh()->isInForce());
        $this->assertTrue($fallback->fresh()->isInForce());
        $this->assertSame(1, $this->inForceCount($organization));
    }

    #[Test]
    public function entitlements_and_limits_resolve_pro_during_the_trial_and_base_at_the_exact_expiry_instant(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        $trial = app(ActivateProTrial::class)->activate($user, $organization->fresh());

        $entitlements = app(Entitlements::class);
        $limits = app(Limits::class);

        $proModules = $this->plan('pro')->currentVersionOrFail()->modules->pluck('key')->sort()->values()->all();
        $baseModules = $this->plan('base')->currentVersionOrFail()->modules->pluck('key')->sort()->values()->all();

        $this->travelTo($trial->ends_at->subSecond());
        $entitlements->flush();
        $this->assertSame($proModules, collect($entitlements->modulesFor($organization->fresh()))->sort()->values()->all());
        $this->assertTrue($limits->limitFor($organization->fresh(), LimitKey::ActiveClasses)->isUnlimited());
        $this->assertTrue($limits->limitFor($organization->fresh(), LimitKey::ActiveStudents)->isUnlimited());

        $this->travelTo($trial->ends_at);
        $entitlements->flush();
        $this->assertSame($baseModules, collect($entitlements->modulesFor($organization->fresh()))->sort()->values()->all());
        $this->assertFalse($limits->limitFor($organization->fresh(), LimitKey::ActiveClasses)->isUnlimited());
        $this->assertSame(8, $limits->limitFor($organization->fresh(), LimitKey::ActiveClasses)->value());
        $this->assertFalse($limits->limitFor($organization->fresh(), LimitKey::ActiveStudents)->isUnlimited());
        $this->assertSame(300, $limits->limitFor($organization->fresh(), LimitKey::ActiveStudents)->value());
    }

    #[Test]
    public function pedagogical_data_created_during_the_trial_survives_expiry_and_further_growth_is_blocked_on_base(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        $trial = app(ActivateProTrial::class)->activate($user, $organization->fresh());

        // Exploit the Pro unlimited window: well past Base's 8-turma cap.
        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            SchoolClass::factory()->recycle($organization)->count(20)->create(['status' => 'active']);
        });

        $this->assertSame(20, app(Limits::class)->usageFor($organization->fresh(), LimitKey::ActiveClasses));

        $this->travelTo($trial->ends_at);

        // The clock alone touches nothing that already exists.
        $this->assertSame(20, app(Limits::class)->usageFor($organization->fresh(), LimitKey::ActiveClasses));
        $this->assertSame(
            20,
            SchoolClass::withoutGlobalScope('organization')->where('organization_id', $organization->getKey())->count(),
        );

        // And now blocked from a 21st, back on Base.
        $context = $this->classContext($organization);
        $this->actingAs($user)->post('/classes', [
            'label' => 'Nova Turma',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ])->assertSessionHasErrors('limit');

        $this->assertSame(20, app(Limits::class)->usageFor($organization->fresh(), LimitKey::ActiveClasses));
    }

    // ------------------------------------------- upgrading DURING the trial

    #[Test]
    public function upgrading_to_paid_pro_during_an_active_trial_takes_over_immediately_and_survives_past_the_original_trial_end(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        $startedAt = $this->freeze(now());
        app(ActivateProTrial::class)->activate($user, $organization->fresh());

        [, $trial, $fallback] = $this->subscriptions($organization)->all();
        $originalEndsAt = $trial->ends_at;

        $upgradedAt = $this->freeze($startedAt->copy()->addDays(10));
        app(ChangeOrganizationPlan::class)->to($organization->fresh(), $this->plan('pro'));

        [, , , $paidPro] = $this->subscriptions($organization)->all();

        $this->assertSame(SubscriptionStatus::Trial, $trial->fresh()->status, 'a Trial is never relabelled away from Trial');
        $this->assertSame($upgradedAt->toDateTimeString(), $trial->fresh()->ends_at->toDateTimeString(), 'cut short at the moment of upgrade');

        $this->assertSame(SubscriptionStatus::Expired, $fallback->fresh()->status, 'the dormant Base fallback is voided, never allowed to take effect');
        $this->assertSame(
            $fallback->fresh()->starts_at->toDateTimeString(),
            $fallback->fresh()->ends_at->toDateTimeString(),
            'collapsed to a zero-width window',
        );

        $this->assertSame('pro', $paidPro->plan->key);
        $this->assertSame(SubscriptionStatus::Active, $paidPro->status);
        $this->assertSame($upgradedAt->toDateTimeString(), $paidPro->starts_at->toDateTimeString());
        $this->assertNull($paidPro->ends_at);

        $this->assertSame(1, $this->inForceCount($organization));

        // When the clock reaches what WOULD have been the original trial
        // end, still on paid Pro — never bumped back to Base.
        $this->travelTo($originalEndsAt);
        $this->assertSame(1, $this->inForceCount($organization));
        $this->assertTrue($paidPro->fresh()->isInForce());
        $this->assertFalse($trial->fresh()->isInForce());
        $this->assertFalse($fallback->fresh()->isInForce(), 'the voided fallback can never come into force');

        // A new trial is still blocked — history persists regardless of the
        // upgrade that cut the original one short.
        try {
            app(ActivateProTrial::class)->activate($user, $organization->fresh());
            $this->fail('Expected TrialException.');
        } catch (TrialException $exception) {
            $this->assertSame('Esta conta já utilizou o período experimental Pro.', $exception->getMessage());
        }

        $this->assertCount(4, $this->subscriptions($organization), 'the blocked attempt created nothing');
    }

    #[Test]
    public function upgrading_via_the_real_admin_http_route_during_an_active_trial_behaves_the_same_way(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        app(ActivateProTrial::class)->activate($user, $organization->fresh());

        $this->actingAs($admin)
            ->post("/admin/accounts/{$organization->ulid}/plan", ['plan_key' => 'pro'])
            ->assertRedirect();

        [, $trial, $fallback, $paidPro] = $this->subscriptions($organization)->all();

        $this->assertSame(SubscriptionStatus::Trial, $trial->status);
        $this->assertSame(SubscriptionStatus::Expired, $fallback->status, 'dormant Base fallback voided');
        $this->assertSame($fallback->starts_at->toDateTimeString(), $fallback->ends_at->toDateTimeString());
        $this->assertSame('pro', $paidPro->plan->key);
        $this->assertSame(SubscriptionStatus::Active, $paidPro->status);
        $this->assertNull($paidPro->ends_at);

        $this->assertSame(1, $this->inForceCount($organization));
    }

    // ------------------------------------------------------------ timezone

    #[Test]
    public function trial_duration_survives_a_europe_lisbon_dst_transition_using_calendar_days_not_manual_hour_math(): void
    {
        config(['trial.pro_days' => 30]);
        $user = User::factory()->create();
        $organization = $user->personalOrganization();

        // Portugal's 2026 fall-back: clocks go back one hour on the last
        // Sunday of October. Starting the trial a couple of weeks earlier
        // means its 30-day window genuinely crosses the transition — exactly
        // the scenario PersonalAccountClosureTest's known DST flake got
        // wrong by comparing a computed literal timestamp instead of
        // calendar days and preserved wall-clock time.
        $startedAt = $this->freeze(Carbon::parse('2026-10-10 09:00:00', 'Europe/Lisbon'));

        $trial = app(ActivateProTrial::class)->activate($user, $organization->fresh());

        $this->assertSame(30, (int) $startedAt->diffInDays($trial->ends_at));
        $this->assertSame('2026-11-09', $trial->ends_at->toDateString());
        $this->assertSame('09:00:00', $trial->ends_at->toTimeString(), 'addDays() preserves wall-clock time across the DST boundary');
    }
}
