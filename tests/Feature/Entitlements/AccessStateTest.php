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
 * The three-state resolution algorithm (§Lote 2) that `RequireModuleTest`
 * then exercises through the HTTP middleware. This file stays at the
 * `Entitlements` API level: `accessState(For)`, `canRead(For)`,
 * `readOnlyModules(For)`, and the byte-identical `allows()`/`modules()`
 * contract those are built on top of.
 */
class AccessStateTest extends TestCase
{
    use RefreshDatabase;

    protected function organization(): Organization
    {
        return User::factory()->create()->personalOrganization();
    }

    /**
     * An ordinary active subscription — the `isInForce()` branch of
     * `Entitlements::resolve()`.
     */
    protected function subscribeActive(Organization $organization, string $planKey): void
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

    /**
     * The exact row shape `ChangeOrganizationPlan::suspend()` leaves: status
     * flipped to Suspended, `ends_at` untouched (open). This is the "most
     * recent subscription overall is Suspended" branch that resolves to
     * `ReadOnly`.
     */
    protected function subscribeSuspended(Organization $organization, string $planKey): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => Plan::where('key', $planKey)->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Suspended,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);

        app(Entitlements::class)->flush();
    }

    // ------------------------------------------------------------- items 1-3

    #[Test]
    public function an_active_subscription_makes_its_plan_modules_allowed(): void
    {
        $organization = $this->organization();
        $this->subscribeActive($organization, 'pro');

        $state = app(Entitlements::class)->accessStateFor($organization->fresh(), 'lessons');

        $this->assertSame(AccessState::Allowed, $state);
    }

    #[Test]
    public function a_module_outside_the_plan_is_locked(): void
    {
        $organization = $this->organization();
        $this->subscribeActive($organization, 'base');

        // `lessons` is Pro/Institucional only — a Base organization never had it.
        $state = app(Entitlements::class)->accessStateFor($organization->fresh(), 'lessons');

        $this->assertSame(AccessState::Locked, $state);
    }

    #[Test]
    public function a_suspended_subscription_makes_its_plan_modules_read_only(): void
    {
        $organization = $this->organization();
        $this->subscribeSuspended($organization, 'pro');

        $state = app(Entitlements::class)->accessStateFor($organization->fresh(), 'lessons');

        $this->assertSame(AccessState::ReadOnly, $state);
    }

    #[Test]
    public function a_naturally_lapsed_active_subscription_stays_locked_not_read_only(): void
    {
        // Deliberate scope discipline (§Lote 2 open question): only an EXPLICIT
        // suspend() produces ReadOnly. A subscription nobody suspended, whose
        // ends_at simply passed, is exactly as locked as it always was.
        $organization = $this->organization();
        $this->subscribeActive($organization, 'pro');

        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->update(['ends_at' => Carbon::now()->subHour()]);
        app(Entitlements::class)->flush();

        $this->assertSame(AccessState::Locked, app(Entitlements::class)->accessStateFor($organization->fresh(), 'lessons'));
    }

    #[Test]
    public function an_expired_subscription_is_locked_not_read_only(): void
    {
        $organization = $this->organization();
        $this->subscribeActive($organization, 'pro');

        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->update(['status' => SubscriptionStatus::Expired, 'ends_at' => Carbon::now()->subHour()]);
        app(Entitlements::class)->flush();

        $this->assertSame(AccessState::Locked, app(Entitlements::class)->accessStateFor($organization->fresh(), 'lessons'));
    }

    // ------------------------------------------------------------- overrides

    #[Test]
    public function a_disabled_override_locks_an_otherwise_allowed_module(): void
    {
        $organization = $this->organization();
        $this->subscribeActive($organization, 'pro');
        $this->overrideProModule($organization, enabled: false);

        $this->assertSame(AccessState::Locked, app(Entitlements::class)->accessStateFor($organization->fresh(), 'lessons'));
    }

    #[Test]
    public function a_disabled_override_locks_an_otherwise_read_only_module(): void
    {
        // §9's real correctness concern: overriding one key must not depend on
        // — or leak into — what state the module would otherwise have been in.
        $organization = $this->organization();
        $this->subscribeSuspended($organization, 'pro');
        $this->overrideProModule($organization, enabled: false);

        $this->assertSame(AccessState::Locked, app(Entitlements::class)->accessStateFor($organization->fresh(), 'lessons'));
    }

    #[Test]
    public function an_enabled_override_allows_a_module_the_plan_never_granted(): void
    {
        // The conservative, pre-existing behaviour (§9): enabled=true wins
        // unconditionally, even from a completely bare Base organization.
        $organization = $this->organization();
        $this->subscribeActive($organization, 'base');
        $this->overrideProModule($organization, enabled: true);

        $this->assertSame(AccessState::Allowed, app(Entitlements::class)->accessStateFor($organization->fresh(), 'lessons'));
    }

    #[Test]
    public function an_enabled_override_upgrades_a_read_only_module_to_allowed(): void
    {
        $organization = $this->organization();
        $this->subscribeSuspended($organization, 'pro');
        $this->overrideProModule($organization, enabled: true);

        $this->assertSame(AccessState::Allowed, app(Entitlements::class)->accessStateFor($organization->fresh(), 'lessons'));
    }

    #[Test]
    public function an_expired_override_has_no_influence_and_falls_back_to_the_base_state(): void
    {
        $organization = $this->organization();
        $this->subscribeSuspended($organization, 'pro');

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'module_id' => Module::where('key', 'lessons')->firstOrFail()->getKey(),
            'enabled' => false,
            'starts_at' => Carbon::now()->subMonth(),
            'ends_at' => Carbon::now()->subDay(),
            'reason' => 'Override já expirado — não deve pesar em nada.',
        ]);
        app(Entitlements::class)->flush();

        // Falls back to the SUSPENDED base state (ReadOnly), not to Locked and
        // not to Allowed — the expired override simply is not consulted.
        $this->assertSame(AccessState::ReadOnly, app(Entitlements::class)->accessStateFor($organization->fresh(), 'lessons'));
    }

    protected function overrideProModule(Organization $organization, bool $enabled): void
    {
        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'module_id' => Module::where('key', 'lessons')->firstOrFail()->getKey(),
            'enabled' => $enabled,
            'reason' => 'Teste do §Lote 2.',
        ]);
        app(Entitlements::class)->flush();
    }

    // -------------------------------------------------- canRead / readOnlyModules

    #[Test]
    public function can_read_is_true_for_allowed_and_read_only_and_false_for_locked(): void
    {
        $organization = $this->organization();
        $this->subscribeSuspended($organization, 'pro');
        $entitlements = app(Entitlements::class);

        // `lessons` is part of the suspended Pro plan → ReadOnly → readable.
        $this->assertTrue($entitlements->canReadFor($organization->fresh(), 'lessons'));
        // `institution_admin` was never part of Pro at all → Locked → not readable.
        $this->assertFalse($entitlements->canReadFor($organization->fresh(), 'institution_admin'));
    }

    #[Test]
    public function read_only_modules_lists_exactly_the_modules_in_read_only_state(): void
    {
        $organization = $this->organization();
        $this->subscribeSuspended($organization, 'pro');
        $entitlements = app(Entitlements::class);

        $readOnly = $entitlements->readOnlyModulesFor($organization->fresh());

        $this->assertContains('lessons', $readOnly);
        $this->assertContains('assessment_profiles', $readOnly, 'Base modules bundled into the suspended Pro plan go ReadOnly too, not just the Pro-exclusive ones.');
        $this->assertNotContains('institution_admin', $readOnly, 'Never sold to Pro at all — stays Locked, never ReadOnly.');
        $this->assertNotContains('lessons', $entitlements->modulesFor($organization->fresh()), 'A ReadOnly module is never also Allowed.');
    }

    // --------------------------------------------- item 15: byte-identical contract

    #[Test]
    public function allows_and_modules_are_unchanged_for_active_locked_and_suspended_organizations(): void
    {
        $organization = $this->organization();
        $this->subscribeActive($organization, 'pro');
        $entitlements = app(Entitlements::class);

        // Active: allowsFor()/modules() say yes, exactly as before this Lote.
        $this->assertTrue($entitlements->allowsFor($organization->fresh(), 'lessons'));
        $this->assertContains('lessons', $entitlements->modulesFor($organization->fresh()));

        // Locked (never sold to this plan): allowsFor()/modules() say no, exactly as before.
        $this->assertFalse($entitlements->allowsFor($organization->fresh(), 'institution_admin'));
        $this->assertNotContains('institution_admin', $entitlements->modulesFor($organization->fresh()));

        // Suspended: modules() is the empty list, byte-identical to the
        // pre-Lote-2 assertion in SubscriptionLifecycleTest — a ReadOnly
        // module is deliberately excluded from the Allowed-only list.
        $this->subscribeSuspended($organization, 'pro');
        $this->assertSame([], $entitlements->modulesFor($organization->fresh()));
        $this->assertFalse($entitlements->allowsFor($organization->fresh(), 'lessons'));
    }

    // ------------------------------------------------------------- item 10: reactivation

    #[Test]
    public function reactivation_returns_a_read_only_module_to_allowed_without_duplicating_the_subscription_or_touching_other_data(): void
    {
        $organization = $this->organization();
        $plans = app(ChangeOrganizationPlan::class);
        $plans->to($organization, Plan::where('key', 'pro')->firstOrFail());

        $entitlements = app(Entitlements::class);
        $this->assertSame(AccessState::Allowed, $entitlements->accessStateFor($organization->fresh(), 'lessons'));

        $plans->suspend($organization->fresh());
        $this->assertSame(AccessState::ReadOnly, $entitlements->accessStateFor($organization->fresh(), 'lessons'));

        $rowCountBefore = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())->count();
        $suspendedRowId = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->where('status', SubscriptionStatus::Suspended)->value('id');

        $resumed = $plans->reactivate($organization->fresh());

        $this->assertNotNull($resumed);
        $this->assertSame($suspendedRowId, $resumed->id, 'Reactivation must resume the SAME row, never create a new one.');
        $this->assertSame(
            $rowCountBefore,
            OrganizationSubscription::withoutGlobalScope('organization')->where('organization_id', $organization->getKey())->count(),
            'No duplicate subscription row.',
        );

        // Allowed again, on the very next call — no manual flush needed here:
        // ChangeOrganizationPlan::reactivate() already flushes Entitlements
        // itself, the same as every other writer of a subscription transition.
        $this->assertSame(AccessState::Allowed, $entitlements->accessStateFor($organization->fresh(), 'lessons'));
    }

    // ----------------------------------------------------------- item 20: flush()

    #[Test]
    public function flush_reflects_a_plan_change_suspend_reactivate_and_override_change_on_the_very_next_call(): void
    {
        $organization = $this->organization();
        $entitlements = app(Entitlements::class);
        $plans = app(ChangeOrganizationPlan::class);

        // Fresh organizations start on Base; `lessons` starts Locked.
        $this->assertSame(AccessState::Locked, $entitlements->accessStateFor($organization->fresh(), 'lessons'));

        // Plan change → Allowed.
        $plans->to($organization->fresh(), Plan::where('key', 'pro')->firstOrFail());
        $this->assertSame(AccessState::Allowed, $entitlements->accessStateFor($organization->fresh(), 'lessons'));

        // Suspend → ReadOnly.
        $plans->suspend($organization->fresh());
        $this->assertSame(AccessState::ReadOnly, $entitlements->accessStateFor($organization->fresh(), 'lessons'));

        // Reactivate → Allowed again.
        $plans->reactivate($organization->fresh());
        $this->assertSame(AccessState::Allowed, $entitlements->accessStateFor($organization->fresh(), 'lessons'));

        // An override, added directly (no service writes these yet) — needs
        // its own explicit flush(), same convention RequireModuleTest already
        // uses for every override it creates.
        $this->overrideProModule($organization->fresh(), enabled: false);
        $this->assertSame(AccessState::Locked, $entitlements->accessStateFor($organization->fresh(), 'lessons'));

        // Expiring that override (an update, not a delete) → falls back to Allowed again.
        OrganizationModuleOverride::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->update(['ends_at' => Carbon::now()->subMinute()]);
        $entitlements->flush();
        $this->assertSame(AccessState::Allowed, $entitlements->accessStateFor($organization->fresh(), 'lessons'));
    }
}
