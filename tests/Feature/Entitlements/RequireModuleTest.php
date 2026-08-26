<?php

namespace Tests\Feature\Entitlements;

use App\Models\Module;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
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

        // Stands in for the Agenda routes until the Pro phase builds them. One
        // URI, every HTTP verb `RequireModule`'s ReadOnly branch cares about
        // (§Lote 2, items 4-9): GET/HEAD must pass a ReadOnly module through,
        // POST/PUT/PATCH/DELETE must not. HEAD needs no route of its own —
        // Laravel answers it for any registered GET automatically.
        Route::middleware(['web', 'auth', 'organization', 'module:calendar'])->group(function (): void {
            Route::get('/_test/agenda', fn () => response()->noContent());
            Route::post('/_test/agenda', fn () => response()->noContent());
            Route::put('/_test/agenda', fn () => response()->noContent());
            Route::patch('/_test/agenda', fn () => response()->noContent());
            Route::delete('/_test/agenda', fn () => response()->noContent());
        });
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

    /**
     * The exact shape `ChangeOrganizationPlan::suspend()` leaves behind: the
     * status flips to Suspended, `ends_at` stays untouched (open), and
     * `starts_at` stays in the past — so this is the "most recent subscription
     * overall is Suspended" case `Entitlements::resolve()` turns into
     * `ReadOnly`, without going through the admin endpoints SubscriptionLifecycleTest
     * already covers for the suspend/reactivate mechanics themselves.
     */
    protected function suspendOn(User $user, string $planKey): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $user->personalOrganization()->getKey())
            ->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $user->personalOrganization()->getKey(),
            'plan_id' => Plan::where('key', $planKey)->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Suspended,
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

    // ------------------------------------------------------ §Lote 2: ReadOnly

    #[Test]
    public function read_only_module_allows_a_get_request(): void
    {
        $user = User::factory()->create();
        $this->suspendOn($user, 'pro');

        $this->actingAs($user)->get('/_test/agenda')->assertNoContent();
    }

    #[Test]
    public function read_only_module_allows_a_head_request(): void
    {
        $user = User::factory()->create();
        $this->suspendOn($user, 'pro');

        $this->actingAs($user)->head('/_test/agenda')->assertStatus(204);
    }

    #[Test]
    public function read_only_module_blocks_a_post_request(): void
    {
        $user = User::factory()->create();
        $this->suspendOn($user, 'pro');

        $this->actingAs($user)->post('/_test/agenda')->assertForbidden();
    }

    #[Test]
    public function read_only_module_blocks_a_put_and_a_patch_request(): void
    {
        $user = User::factory()->create();
        $this->suspendOn($user, 'pro');

        $this->actingAs($user)->put('/_test/agenda')->assertForbidden();
        $this->actingAs($user)->patch('/_test/agenda')->assertForbidden();
    }

    #[Test]
    public function read_only_module_blocks_a_delete_request(): void
    {
        $user = User::factory()->create();
        $this->suspendOn($user, 'pro');

        $this->actingAs($user)->delete('/_test/agenda')->assertForbidden();
    }

    #[Test]
    public function locked_module_still_blocks_a_get_request(): void
    {
        // Unlike ReadOnly, Locked never lets even a GET through — this is the
        // unchanged pre-Lote-2 behaviour (a_base_organization_is_blocked_from_a_pro_module
        // above covers the same thing at the boolean level; this one names the state).
        $user = User::factory()->create();

        $this->actingAs($user)->get('/_test/agenda')->assertForbidden();
    }

    #[Test]
    public function tenancy_still_hides_another_organizations_class_regardless_of_access_state(): void
    {
        // The requesting organization is ReadOnly (a suspended Pro subscription
        // that used to carry `classes`) — but that must not leak a class
        // belonging to an entirely different organization. ReadOnly widens
        // WHAT a request may do to its own tenant's data; it never widens WHICH
        // tenant's data a request can reach.
        $teacher = User::factory()->create();
        $this->suspendOn($teacher, 'pro');

        $otherTeacher = User::factory()->create();
        $this->subscribe($otherTeacher, 'pro');
        $otherOrganization = $otherTeacher->personalOrganization();
        $otherClass = app(CurrentOrganization::class)->runFor(
            $otherOrganization,
            fn (): SchoolClass => SchoolClass::factory()->recycle($otherOrganization)->create(),
        );

        $this->actingAs($teacher)
            ->withSession(['organization_id' => $teacher->personalOrganization()->id])
            ->get("/classes/{$otherClass->ulid}")
            ->assertNotFound();
    }

    #[Test]
    public function a_class_policy_still_applies_on_top_of_a_read_only_module(): void
    {
        // ReadOnly only ever widens what `RequireModule` itself lets through.
        // SchoolClassPolicy::view (§23: "only my classes") runs independently,
        // underneath it, and must still refuse a teacher who is not assigned
        // to this particular class — even though the module gate above it
        // just said yes to the GET.
        $teacher = User::factory()->create();
        $this->suspendOn($teacher, 'pro');
        $organization = $teacher->personalOrganization();

        $class = app(CurrentOrganization::class)->runFor(
            $organization,
            fn (): SchoolClass => SchoolClass::factory()->recycle($organization)->create(),
        );
        $class->teachers()->attach($teacher, ['role' => 'owner']);

        $stranger = User::factory()->create();
        $organization->members()->attach($stranger, ['joined_at' => now()]);

        $session = ['organization_id' => $organization->id];

        $this->actingAs($teacher)->withSession($session)
            ->get("/classes/{$class->ulid}")
            ->assertOk();

        $this->actingAs($stranger)->withSession($session)
            ->get("/classes/{$class->ulid}")
            ->assertForbidden();
    }
}
