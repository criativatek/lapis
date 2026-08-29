<?php

namespace Tests\Feature\Entitlements;

use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Entitlements\AccessState;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PRO → BASE: WHAT SURVIVES, WHAT LOCKS, AND WHAT NOBODY MAY DELETE.
 *
 * §19 of the Matriz Mestre makes four promises about a downgrade and this file
 * asserts each of them separately, because they fail independently:
 *
 *   1. Base keeps working.
 *   2. The interpretive capabilities lock.
 *   3. Some Pro workflows holding historical data go READ-ONLY, not locked —
 *      §15 writes the expected states out by name (`lessons_workspace` and
 *      `teacher_timetable` = read_only).
 *   4. Nothing legitimately created is deleted, and a later upgrade finds it
 *      all still there.
 *
 * WHY THIS MATTERS MORE THAN A NORMAL GATE TEST. Before this slice, a plan
 * change produced `Locked` for everything the new plan did not sell, so a
 * teacher who came down from Pro could no longer open a single sumário they
 * had written. Nothing was deleted — but nothing was reachable either, which
 * is the same thing from where the teacher is standing. The distinction
 * between «no longer sold» and «no longer yours» is the whole subject here.
 *
 * The read-only list itself lives in `RetainedOnDowngrade`, with the reason
 * for every key on it and every key deliberately off it.
 */
class PlanDowngradeTest extends TestCase
{
    use RefreshDatabase;

    private function organization(): Organization
    {
        return User::factory()->create()->personalOrganization();
    }

    private function plan(string $key): Plan
    {
        return Plan::where('key', $key)->firstOrFail();
    }

    private function state(Organization $organization, string $moduleKey): AccessState
    {
        return app(Entitlements::class)->accessStateFor($organization->fresh(), $moduleKey);
    }

    /** Pro first, then Base — the transition every assertion below is about. */
    private function downgrade(Organization $organization): void
    {
        $plans = app(ChangeOrganizationPlan::class);

        $plans->to($organization->fresh(), $this->plan('pro'));
        $plans->to($organization->fresh(), $this->plan('base'));
    }

    // ------------------------------------------------------- §19, promise 1

    #[Test]
    public function the_base_capabilities_stay_fully_usable(): void
    {
        $organization = $this->organization();
        $this->downgrade($organization);

        foreach (['classes', 'results', 'records', 'self_assessments', 'interventions', 'student_progress', 'reports', 'calendar'] as $key) {
            $this->assertSame(
                AccessState::Allowed,
                $this->state($organization, $key),
                "«{$key}» é Base e devia continuar plenamente utilizável depois do downgrade.",
            );
        }
    }

    // ------------------------------------------------------- §19, promise 2

    #[Test]
    public function the_interpretive_and_ai_capabilities_lock(): void
    {
        $organization = $this->organization();
        $this->downgrade($organization);

        // §19: «capacidades interpretativas ficam locked» e «IA deixa de
        // executar». Nada destas guarda dados próprios — cada leitura é
        // recalculada — pelo que preservá-las significaria continuar a
        // PRODUZI-LAS, que é exatamente o que a §19 proíbe.
        foreach ([
            'advanced_analytics',
            'report_pedagogical_analysis',
            'ai_pedagogical_analysis',
            'ai_assessment',
            'ai_followup',
            'ai_strategies',
            'ai_reports',
            'calendar_import',
            'data_backup_restore',
        ] as $key) {
            $this->assertSame(
                AccessState::Locked,
                $this->state($organization, $key),
                "«{$key}» não devia sobreviver a um downgrade em estado nenhum.",
            );
        }
    }

    // ------------------------------------------------------- §19, promise 3

    /** The literal example §15 writes out, asserted as it is written. */
    #[Test]
    public function the_lessons_workspace_becomes_read_only_rather_than_locked(): void
    {
        $organization = $this->organization();

        $this->assertSame(AccessState::Locked, $this->state($organization, 'lessons'), 'Uma organização que nunca teve Pro não ganha consulta.');

        $this->downgrade($organization);

        $this->assertSame(AccessState::ReadOnly, $this->state($organization, 'lessons'));
        $this->assertTrue(app(Entitlements::class)->canReadFor($organization->fresh(), 'lessons'));
        $this->assertFalse(app(Entitlements::class)->allowsFor($organization->fresh(), 'lessons'), 'ReadOnly nunca é também Allowed.');
    }

    #[Test]
    public function a_read_only_workspace_answers_a_get_and_refuses_a_write(): void
    {
        $user = User::factory()->create();
        $this->downgrade($user->personalOrganization());

        // §15: «consulta preservada, sem criação/alteração». The pages the
        // teacher wrote in are still openable; the endpoints that write are not.
        $this->actingAs($user)->get('/lessons')->assertOk();
        $this->actingAs($user)->get('/timetable')->assertOk();
        $this->actingAs($user)->post('/lessons/materialize-week')->assertForbidden();
    }

    #[Test]
    public function a_read_only_workspace_stays_in_the_navigation(): void
    {
        $user = User::factory()->create();
        $this->downgrade($user->personalOrganization());

        // A menu that hid the entry would leave the page reachable only by a
        // typed URL, which is not what «os dados não desaparecem» means.
        $this->actingAs($user)->get('/dashboard')->assertInertia(function ($page): void {
            $keys = collect($page->toArray()['props']['nav']['sections'])
                ->flatMap(fn (array $section): array => array_column($section['items'], 'key'))
                ->all();

            $this->assertContains('lessons', $keys);
            $this->assertContains('teacher-timetable', $keys);

            // And the two lists stay apart on the wire: `modules` means
            // ALLOWED, so a read-only key must not appear in it.
            $this->assertNotContains('lessons', $page->toArray()['props']['modules']);
            $this->assertContains('lessons', $page->toArray()['props']['readOnlyModules']);
        });
    }

    // ------------------------------------------------------- §19, promise 4

    #[Test]
    public function a_downgrade_deletes_no_subscription_history_and_an_upgrade_finds_everything(): void
    {
        $organization = $this->organization();
        $plans = app(ChangeOrganizationPlan::class);

        $before = $this->rowCount($organization);

        $plans->to($organization->fresh(), $this->plan('pro'));
        $plans->to($organization->fresh(), $this->plan('base'));

        $this->assertGreaterThan($before, $this->rowCount($organization), 'O histórico cresce; nunca encolhe.');

        // Back up again: the Pro capabilities return in full, not as read-only
        // leftovers of the previous cycle.
        $plans->to($organization->fresh(), $this->plan('pro'));

        $this->assertSame(AccessState::Allowed, $this->state($organization, 'lessons'));
        $this->assertSame(AccessState::Allowed, $this->state($organization, 'advanced_analytics'));
    }

    /**
     * The other direction of the same promise: the Pro capability a downgrade
     * left read-only is the one an operator can still take away entirely, and
     * an override still wins over the retention rule.
     */
    #[Test]
    public function a_disabled_override_still_locks_a_capability_the_downgrade_left_read_only(): void
    {
        $organization = $this->organization();
        $this->downgrade($organization);

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'module_id' => Module::where('key', 'lessons')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'teste',
            'starts_at' => Carbon::now()->subDay(),
        ]);
        app(Entitlements::class)->flush();

        $this->assertSame(AccessState::Locked, $this->state($organization, 'lessons'));
    }

    // ------------------------------------------------- what must NOT change

    /**
     * The retention rule is scoped to an actual plan CHANGE. An organization
     * whose subscription simply lapsed with nothing in force behind it has no
     * plan at all, and keeps exactly the behaviour it always had — this is the
     * deliberate scope discipline `Entitlements::resolve()` documents, and the
     * assertion that would catch it widening by accident.
     */
    #[Test]
    public function a_lapsed_subscription_with_nothing_in_force_is_still_locked(): void
    {
        $organization = $this->organization();
        app(ChangeOrganizationPlan::class)->to($organization->fresh(), $this->plan('pro'));

        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->update(['ends_at' => Carbon::now()->subHour(), 'status' => SubscriptionStatus::Expired]);
        app(Entitlements::class)->flush();

        $this->assertSame(AccessState::Locked, $this->state($organization, 'lessons'));
    }

    /**
     * A trial that ends IS a downgrade, and behaves like one. This is the case
     * a teacher is most likely to meet: thirty days of Pro, sumários written
     * during them, and then the dormant Base row takes over on its own.
     */
    #[Test]
    public function a_finished_pro_trial_leaves_the_lessons_workspace_consultable(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();

        app(ChangeOrganizationPlan::class)->startProTrial(
            $organization->fresh(),
            $user,
            $this->plan('pro'),
            $this->plan('base'),
            30,
        );

        $this->assertSame(AccessState::Allowed, $this->state($organization, 'lessons'), 'Durante o trial é utilização normal.');

        // The trial's own window closes and the dormant Base row starts
        // mattering — no job, no scheduler, exactly as startProTrial() built it.
        $this->travel(31)->days();
        app(Entitlements::class)->flush();

        $this->assertSame(AccessState::Allowed, $this->state($organization, 'student_progress'));
        $this->assertSame(AccessState::ReadOnly, $this->state($organization, 'lessons'));
        $this->assertSame(AccessState::Locked, $this->state($organization, 'advanced_analytics'));
    }

    private function rowCount(Organization $organization): int
    {
        return OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->count();
    }
}
