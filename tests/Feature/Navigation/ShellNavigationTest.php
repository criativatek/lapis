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

class ShellNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function upgrade(User $user, string $planKey): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $user->personalOrganization()->getKey())
            ->update(['plan_id' => Plan::where('key', $planKey)->firstOrFail()->getKey(), 'status' => SubscriptionStatus::Active, 'starts_at' => Carbon::now()->subDay()]);
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

    #[Test]
    public function a_base_teacher_sees_the_core_menu_but_not_the_pro_or_institutional_items(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $keys = $this->navKeys($page);

            // The 13 Base items from the navigation doc are all present...
            $this->assertContains('classes', $keys);
            $this->assertContains('assessment-profiles', $keys);
            $this->assertContains('reports', $keys);

            // ...and the Pro / institutional ones are filtered out entirely.
            $this->assertNotContains('calendar', $keys);
            $this->assertNotContains('lessons', $keys);
            $this->assertNotContains('institution', $keys);
        });
    }

    #[Test]
    public function a_pro_teacher_gains_the_organization_of_the_year_items(): void
    {
        $user = User::factory()->create();
        $this->upgrade($user, 'pro');

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $keys = $this->navKeys($page);

            $this->assertContains('calendar', $keys);
            $this->assertContains('lessons', $keys);
            $this->assertNotContains('institution', $keys);
        });
    }

    #[Test]
    public function an_institutional_teacher_gains_the_administration_item(): void
    {
        $user = User::factory()->create();
        $this->upgrade($user, 'institutional');

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $this->assertContains('institution', $this->navKeys($page));
        });
    }

    #[Test]
    public function the_footer_always_carries_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $footerKeys = array_column($page->toArray()['props']['nav']['footer'], 'key');
            $this->assertContains('settings', $footerKeys);
        });
    }

    #[Test]
    public function a_placeholder_route_renders_the_placeholder_page_with_its_phase(): void
    {
        $user = User::factory()->create();

        // Instruments is still a placeholder (Fase 2); classes and profiles are built.
        $this->actingAs($user)->get('/instruments')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Placeholder')
                ->where('title', 'Instrumentos')
                ->where('phase', 2)
        );
    }

    #[Test]
    public function the_scope_selectors_are_shared_but_empty_until_the_academic_model_exists(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('scope.academicYear', null)
                ->where('scope.class', null)
                ->where('scope.period', null)
        );
    }
}
