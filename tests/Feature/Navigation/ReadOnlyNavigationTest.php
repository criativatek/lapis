<?php

namespace Tests\Feature\Navigation;

use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The consequence §Lote 2 left behind: a suspended organization's modules
 * resolve to `ReadOnly` and `RequireModule` serves their GETs — but the menu
 * was still filtered by `allows()`, so those pages became reachable only by
 * typing the URL. READ_ONLY means "consultar sim, alterar não"; a page nobody
 * can navigate to is not consultable in any sense that matters.
 *
 * The rule this file holds: ALLOWED and READ_ONLY are both VISIBLE, LOCKED is
 * not — while `modules` (Allowed, writes included) and `readOnlyModules` stay
 * two separate lists on the wire, so no component mistakes one for the other.
 */
class ReadOnlyNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function subscribe(User $user, string $planKey, SubscriptionStatus $status): void
    {
        // The VERSION goes with the plan. This is a bulk `update()` on the
        // query builder, which bypasses the model's own reconciliation — and
        // the composite foreign key then refuses a row whose two columns name
        // different plans, exactly as it should. Naming both is what a fixture
        // owes that guarantee.
        $version = Plan::where('key', $planKey)->firstOrFail()->currentVersionOrFail();

        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $user->personalOrganization()->getKey())
            ->update([
                'plan_id' => $version->plan_id,
                'plan_version_id' => $version->getKey(),
                'status' => $status,
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
            ]);

        app(Entitlements::class)->flush();
    }

    /**
     * @return list<string>
     */
    protected function navKeys(AssertableInertia $page): array
    {
        $keys = [];

        foreach ($page->toArray()['props']['nav']['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $keys[] = $item['key'];
            }
        }

        return $keys;
    }

    // ------------------------------------------------- 1-3: the three states

    #[Test]
    public function an_allowed_capability_is_visible_in_the_navigation(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pro', SubscriptionStatus::Active);

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page): void {
            $this->assertContains('calendar', $this->navKeys($page));
        });
    }

    #[Test]
    public function a_read_only_capability_stays_visible_in_the_navigation(): void
    {
        // The regression this whole micro-lote exists to prevent.
        $user = User::factory()->create();
        $this->subscribe($user, 'pro', SubscriptionStatus::Suspended);

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page): void {
            $this->assertContains('calendar', $this->navKeys($page));
        });
    }

    #[Test]
    public function a_locked_capability_is_absent_from_the_navigation(): void
    {
        // Base never had `lessons` at all — Locked, and it stays hidden. This
        // is the half of the rule that must NOT have loosened.
        //
        // The example used to be `calendar`; the Base/Pro realignment moved
        // that key to Base (Matriz §2), so it can no longer stand for
        // "something Base does not have". `lessons` is the Pro workspace that
        // still does, and the assertion is unchanged in what it checks.
        $user = User::factory()->create();
        $this->subscribe($user, 'base', SubscriptionStatus::Active);

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page): void {
            $this->assertNotContains('lessons', $this->navKeys($page));
            $this->assertNotContains('teacher-timetable', $this->navKeys($page));
        });
    }

    /**
     * The other half, new with the realignment: a capability Base DOES have is
     * present, and its page answers. A menu that hid the calendar from the
     * plan the Matriz gives it to would be the same kind of lie in reverse.
     */
    #[Test]
    public function a_base_capability_is_present_in_the_navigation_and_its_page_answers(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'base', SubscriptionStatus::Active);

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page): void {
            $this->assertContains('calendar', $this->navKeys($page));
        });

        $this->actingAs($user)->get('/calendar')->assertOk();
        $this->actingAs($user)->get('/calendar/ano')->assertOk();
    }

    // ------------------------------------- 4: a suspended Pro is still usable

    #[Test]
    public function a_suspended_pro_organization_keeps_every_entry_it_had_while_active(): void
    {
        // Not just "calendar survives" — the menu is IDENTICAL to the active
        // one. Suspension pauses writing; it does not quietly shrink the
        // product to Base.
        $active = User::factory()->create();
        $this->subscribe($active, 'pro', SubscriptionStatus::Active);

        $activeKeys = [];
        $this->actingAs($active)->get('/dashboard')->assertInertia(function (AssertableInertia $page) use (&$activeKeys): void {
            $activeKeys = $this->navKeys($page);
        });

        $suspended = User::factory()->create();
        $this->subscribe($suspended, 'pro', SubscriptionStatus::Suspended);

        $this->actingAs($suspended)->get('/dashboard')->assertInertia(function (AssertableInertia $page) use ($activeKeys): void {
            $this->assertSame($activeKeys, $this->navKeys($page));
        });

        // And the pages themselves still answer a GET, which is what makes the
        // menu entry honest rather than a link into a 403.
        $this->actingAs($suspended)->get('/calendar')->assertOk();
        $this->actingAs($suspended)->get('/lessons')->assertOk();
    }

    // ------------------------------- 5-6: the two lists stay separate on the wire

    #[Test]
    public function modules_never_contains_a_read_only_capability(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pro', SubscriptionStatus::Suspended);

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];

            // `modules` means ALLOWED — full use, writes included. A suspended
            // organization may write nothing, so the list is empty, exactly as
            // it was before this micro-lote.
            $this->assertSame([], $props['modules']);
        });
    }

    #[Test]
    public function read_only_modules_contains_exactly_the_read_only_capabilities(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pro', SubscriptionStatus::Suspended);

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page): void {
            $readOnly = $page->toArray()['props']['readOnlyModules'];

            $this->assertContains('calendar', $readOnly);
            $this->assertContains('lessons', $readOnly);
            // A Base module bundled into the suspended Pro plan is read-only too.
            $this->assertContains('assessment_profiles', $readOnly);
            // Never sold to Pro at all — Locked, so it belongs to neither list.
            $this->assertNotContains('institution_admin', $readOnly);
        });
    }

    // ---------------------------------- 7-8: nothing changed for a normal org

    #[Test]
    public function an_active_organization_sends_every_capability_as_allowed_and_none_as_read_only(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pro', SubscriptionStatus::Active);

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];

            $this->assertContains('calendar', $props['modules']);
            // The new prop is present and empty — never absent, so a component
            // can read it unconditionally without guarding for undefined.
            $this->assertSame([], $props['readOnlyModules']);
        });
    }

    #[Test]
    public function the_three_plans_still_reach_exactly_what_they_always_did(): void
    {
        // ShellNavigationTest already spells out each plan's menu key by key;
        // this asserts the boundaries BETWEEN them are where they were, which
        // is the shape a visibility change could plausibly have moved.
        $menus = [];

        foreach (['base', 'pro', 'institutional'] as $planKey) {
            $user = User::factory()->create();
            $this->subscribe($user, $planKey, SubscriptionStatus::Active);

            $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) use (&$menus, $planKey): void {
                $menus[$planKey] = $this->navKeys($page);
            });
        }

        // «calendar» left this diff in the Base/Pro realignment: Base already
        // reaches it, so Pro cannot gain it.
        $this->assertSame(
            ['teacher-timetable', 'lessons', 'configuration-sharing', 'configuration-import'],
            array_values(array_diff($menus['pro'], $menus['base'])),
        );
        $this->assertSame(
            ['team', 'class-reassignment', 'institution'],
            array_values(array_diff($menus['institutional'], $menus['pro'])),
        );
    }

    // ------------------------------- 9: the Lote 2 gate itself is untouched

    #[Test]
    public function a_visible_read_only_entry_still_refuses_a_write(): void
    {
        // Visibility was the ONLY thing this micro-lote changed. The menu now
        // shows the entry, the GET behind it answers — and the writes behind it
        // are still refused by RequireModule, exactly as §Lote 2 left it. If
        // these ever pass, showing the entry became a lie.
        //
        // `lessons.materialize-week` is the pointed one: §Lote 2 stopped the
        // GET from materializing as a side effect, and this is the explicit
        // POST that does the same thing on purpose. Both must refuse.
        $user = User::factory()->create();
        $this->subscribe($user, 'pro', SubscriptionStatus::Suspended);

        $this->actingAs($user)->post('/lessons/materialize-week')->assertForbidden();
        $this->actingAs($user)->post('/calendar/acontecimentos')->assertForbidden();
    }
}
