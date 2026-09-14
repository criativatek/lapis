<?php

namespace Tests\Feature\Activity;

use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 0.145.1 — «Registo de atividade» abria, numa conta Pro, um modal com
 * «403 | O seu plano não inclui este módulo.».
 *
 * NÃO ERA o Pro sem um direito que devia ter: `audit_log` é Institucional por
 * composição. Era o Painel a apontar toda a gente para a auditoria
 * transversal, e o bloqueio a chegar como HTML de erro, que o Inertia desenha
 * no seu modal técnico. A correção separa as duas leituras:
 *
 *   /activity               «Minha atividade» — todos os planos, sem chave de
 *                           módulo (propriedade da plataforma), só causer = eu.
 *   /activity/organization  «Auditoria da organização» — `module:audit_log`.
 */
class ActivityEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private function subscribe(Organization $organization, string $planKey): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => Plan::where('key', $planKey)->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);

        app(Entitlements::class)->flush();
    }

    private function teacherOn(string $planKey): User
    {
        $teacher = User::factory()->create();
        $this->subscribe($teacher->personalOrganization(), $planKey);

        return $teacher;
    }

    private function override(Organization $organization, bool $enabled): void
    {
        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'module_id' => Module::where('key', 'audit_log')->firstOrFail()->getKey(),
            'enabled' => $enabled,
            'reason' => 'Teste de override.',
        ]);

        app(Entitlements::class)->flush();
    }

    private function recordEvent(Organization $organization, ?User $causer, string $event): void
    {
        app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => app(AuditLog::class)->record($event, causer: $causer, summary: $event),
        );
    }

    /**
     * @return array{Organization, User, User}
     */
    private function institutionalOrganizationWithMember(): array
    {
        $owner = User::factory()->withoutOrganization()->create();
        $member = User::factory()->withoutOrganization()->create();

        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach([$owner->id, $member->id], ['joined_at' => now()]);
        $this->subscribe($organization, 'institutional');

        return [$organization, $owner, $member];
    }

    // ------------------------------------------------ A/B: Pro e Base — Minha atividade

    #[Test]
    public function a_pro_teacher_opens_my_activity_without_any_403(): void
    {
        $teacher = $this->teacherOn('pro');
        $this->recordEvent($teacher->personalOrganization(), $teacher, 'lesson.taught');

        $this->actingAs($teacher)
            ->get('/activity')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Activity')
                ->where('scope', 'mine')
                ->has('events', 1)
                ->where('events.0.event', 'lesson.taught'));
    }

    #[Test]
    public function a_base_teacher_opens_my_activity_too(): void
    {
        $teacher = User::factory()->create();

        $this->actingAs($teacher)
            ->get('/activity')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Activity')->where('scope', 'mine'));
    }

    #[Test]
    public function a_pro_teacher_gets_a_friendly_page_for_the_organization_audit(): void
    {
        $teacher = $this->teacherOn('pro');

        $this->actingAs($teacher)
            ->get('/activity/organization')
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page
                ->component('ModuleUnavailable')
                ->where('canManagePlan', true));
    }

    #[Test]
    public function an_inertia_visit_to_a_locked_module_receives_an_inertia_page_so_no_modal_opens(): void
    {
        $teacher = User::factory()->create();

        $version = $this->actingAs($teacher)->get('/dashboard')->viewData('page')['version'];

        $this->actingAs($teacher)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version])
            ->get('/activity/organization')
            ->assertForbidden()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'ModuleUnavailable');
    }

    #[Test]
    public function a_json_request_or_a_write_to_a_locked_module_stays_a_bare_403(): void
    {
        $teacher = $this->teacherOn('pro');

        $this->actingAs($teacher)
            ->getJson('/activity/organization')
            ->assertForbidden()
            ->assertJsonMissingPath('component');
    }

    // ------------------------------------------------------------ C: Institucional

    #[Test]
    public function the_institutional_owner_has_both_readings_and_my_activity_is_only_theirs(): void
    {
        [$organization, $owner, $member] = $this->institutionalOrganizationWithMember();
        $this->recordEvent($organization, $owner, 'owner.action');
        $this->recordEvent($organization, $member, 'member.action');

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->get('/activity')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'mine')
                ->has('events', 1)
                ->where('events.0.event', 'owner.action'));

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->get('/activity/organization')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'organization')
                ->has('events', 2));
    }

    #[Test]
    public function a_member_never_sees_a_colleagues_actions_in_either_reading(): void
    {
        [$organization, $owner, $member] = $this->institutionalOrganizationWithMember();
        $this->recordEvent($organization, $owner, 'owner.action');
        $this->recordEvent($organization, $member, 'member.action');

        foreach (['/activity', '/activity/organization'] as $uri) {
            $this->actingAs($member)->withSession(['organization_id' => $organization->id])
                ->get($uri)
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('events', 1)
                    ->where('events.0.event', 'member.action'));
        }
    }

    // ---------------------------------------------------------------- D: overrides

    #[Test]
    public function an_override_granting_audit_log_opens_the_organization_audit_on_pro(): void
    {
        $teacher = $this->teacherOn('pro');
        $this->override($teacher->personalOrganization(), enabled: true);

        $this->actingAs($teacher)->get('/activity/organization')->assertOk();
    }

    #[Test]
    public function an_override_withdrawing_audit_log_blocks_only_the_organization_audit(): void
    {
        $teacher = $this->teacherOn('institutional');
        $this->override($teacher->personalOrganization(), enabled: false);

        $this->actingAs($teacher)
            ->get('/activity/organization')
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page->component('ModuleUnavailable'));

        $this->actingAs($teacher)->get('/activity')->assertOk();
    }

    // ------------------------------------------------------- F: outra organização

    #[Test]
    public function my_activity_never_shows_another_organizations_events_even_my_own(): void
    {
        $teacher = $this->teacherOn('pro');
        [$otherOrganization] = $this->institutionalOrganizationWithMember();
        $otherOrganization->members()->attach($teacher->id, ['joined_at' => now()]);

        $this->recordEvent($teacher->personalOrganization(), $teacher, 'here.action');
        $this->recordEvent($otherOrganization, $teacher, 'elsewhere.action');

        $this->actingAs($teacher)
            ->withSession(['organization_id' => $teacher->personalOrganization()->id])
            ->get('/activity')
            ->assertInertia(fn ($page) => $page
                ->has('events', 1)
                ->where('events.0.event', 'here.action'));
    }

    #[Test]
    public function guests_are_sent_to_login(): void
    {
        $this->get('/activity')->assertRedirect('/login');
    }

    // ---------------------------------------------------------- G/H: coerência

    #[Test]
    public function the_shared_modules_prop_drives_the_dashboard_links_consistently(): void
    {
        $this->actingAs($this->teacherOn('pro'))->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('modules', fn ($modules) => ! collect($modules)->contains('audit_log')));

        $this->actingAs($this->teacherOn('institutional'))->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('modules', fn ($modules) => collect($modules)->contains('audit_log')));
    }

    #[Test]
    public function the_plan_compositions_are_unchanged_by_this_fix(): void
    {
        $pro = $this->teacherOn('pro');
        $institutional = $this->teacherOn('institutional');

        $proModules = app(Entitlements::class)->modulesFor($pro->personalOrganization());
        $institutionalModules = app(Entitlements::class)->modulesFor($institutional->personalOrganization());

        foreach (['lessons', 'calendar_import', 'data_backup_restore', 'ai_reports', 'advanced_analytics', 'reports'] as $key) {
            $this->assertContains($key, $proModules);
        }

        $this->assertNotContains('audit_log', $proModules);
        $this->assertContains('audit_log', $institutionalModules);
        // No new catalogue key: «Minha atividade» is not sold, so no plan gains a version.
        $this->assertFalse(Module::where('key', 'own_activity')->exists());
        $this->assertSame(1, (int) Plan::where('key', 'pro')->firstOrFail()->versions()->max('version'));
    }
}
