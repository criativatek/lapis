<?php

namespace Tests\Feature\Entitlements;

use App\Models\Module;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RequireModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stands in for the Agenda routes until the Pro phase builds them.
        Route::middleware(['web', 'auth', 'organization', 'module:calendar'])
            ->get('/_test/agenda', fn () => response()->noContent());
    }

    protected function subscribe(User $user, string $planKey): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $user->personalOrganization()->getKey())
            ->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $user->personalOrganization()->getKey(),
            'plan_id' => Plan::where('key', $planKey)->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now()->subDay(),
        ]);

        app(Entitlements::class)->flush();
    }

    #[Test]
    public function a_base_organization_is_blocked_from_a_pro_module(): void
    {
        // Scenario A8: the block comes from the entitlement system on the server.
        $user = User::factory()->create();

        $this->actingAs($user)->get('/_test/agenda')->assertForbidden();
    }

    #[Test]
    public function a_pro_organization_reaches_the_module(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pro');

        $this->actingAs($user)->get('/_test/agenda')->assertNoContent();
    }

    #[Test]
    public function an_override_can_sell_a_single_pro_module_to_a_base_organization(): void
    {
        $user = User::factory()->create();

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $user->personalOrganization()->getKey(),
            'module_id' => Module::where('key', 'calendar')->firstOrFail()->getKey(),
            'enabled' => true,
            'reason' => 'Módulo opcional adquirido em separado.',
        ]);
        app(Entitlements::class)->flush();

        $this->actingAs($user)->get('/_test/agenda')->assertNoContent();
    }

    #[Test]
    public function an_override_can_withdraw_a_module_the_plan_includes(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pro');

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $user->personalOrganization()->getKey(),
            'module_id' => Module::where('key', 'calendar')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Desativado a pedido da instituição.',
        ]);
        app(Entitlements::class)->flush();

        $this->actingAs($user)->get('/_test/agenda')->assertForbidden();
    }

    #[Test]
    public function an_expired_override_does_not_grant_the_module(): void
    {
        $user = User::factory()->create();

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $user->personalOrganization()->getKey(),
            'module_id' => Module::where('key', 'calendar')->firstOrFail()->getKey(),
            'enabled' => true,
            'starts_at' => Carbon::now()->subMonth(),
            'ends_at' => Carbon::now()->subDay(),
        ]);
        app(Entitlements::class)->flush();

        $this->actingAs($user)->get('/_test/agenda')->assertForbidden();
    }

    #[Test]
    public function a_suspended_subscription_grants_nothing(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pro');

        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $user->personalOrganization()->getKey())
            ->update(['status' => SubscriptionStatus::Suspended]);
        app(Entitlements::class)->flush();

        $this->assertSame([], app(Entitlements::class)->modulesFor($user->personalOrganization()));
    }

    #[Test]
    public function an_expired_subscription_grants_nothing(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pro');

        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $user->personalOrganization()->getKey())
            ->update(['ends_at' => Carbon::now()->subHour()]);
        app(Entitlements::class)->flush();

        $this->assertSame([], app(Entitlements::class)->modulesFor($user->personalOrganization()));
    }

    #[Test]
    public function a_base_organization_still_reaches_its_own_modules(): void
    {
        $user = User::factory()->create();

        $modules = app(Entitlements::class)->modulesFor($user->personalOrganization());

        $this->assertContains('assessment_profiles', $modules);
        $this->assertContains('reports', $modules);
        $this->assertNotContains('calendar', $modules);
        $this->assertNotContains('ai_assistance', $modules);
    }
}
