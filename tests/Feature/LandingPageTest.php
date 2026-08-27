<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_landing_page_shows_the_plans_that_actually_exist(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->has('plans', 3)
                ->where('plans.0.key', 'base')
                ->where('plans.1.key', 'pro')
                ->where('plans.2.key', 'institutional')
            );
    }

    /**
     * Every ✓ in the comparison table is derived from these keys, so they are
     * the payload's whole job. A plan that sent display names instead would
     * render as missing capabilities it has the moment somebody renames a
     * module in the seeder.
     */
    public function test_each_plan_carries_the_entitlement_keys_the_comparison_reads(): void
    {
        $plans = $this->get(route('home'))->viewData('page')['props']['plans'];

        $keys = array_column($plans, 'moduleKeys', 'key');

        // The three rows the table's Base / Pro / Institucional split hangs on.
        $this->assertContains('assessment_profiles', $keys['base']);
        $this->assertNotContains('advanced_analytics', $keys['base']);
        $this->assertContains('advanced_analytics', $keys['pro']);
        $this->assertNotContains('institution_admin', $keys['pro']);
        $this->assertContains('institution_admin', $keys['institutional']);

        // Each plan still contains everything the one below it does: the cards
        // say «tudo do Base, mais…», and that has to be true.
        $this->assertEmpty(array_diff($keys['base'], $keys['pro']));
        $this->assertEmpty(array_diff($keys['pro'], $keys['institutional']));
    }

    /**
     * «Falar connosco» must have somewhere to go. With no address configured
     * the page is told so and drops the button, rather than inventing one or
     * falling back to the system no-reply sender.
     */
    public function test_the_contact_address_comes_from_the_platform_settings(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('contactEmail', null));

        PlatformSetting::current()->update(['contact_email' => 'geral@exemplo.pt']);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('contactEmail', 'geral@exemplo.pt'));
    }

    /**
     * Inertia SSR is off, so a crawler only ever sees what the Blade layout
     * writes. If these move back into the Vue <Head>, the page silently stops
     * being indexable and nothing else fails.
     */
    public function test_the_seo_tags_are_rendered_by_the_server(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<meta name="description"', false)
            ->assertSee('<link rel="canonical" href="'.url('/').'"', false)
            ->assertSee('og:title', false)
            ->assertSee('application/ld+json', false);
    }

    /**
     * §32: a signed-in visitor sees the landing page, not a redirect. The page
     * swaps its buttons instead — a redirect here is how you get a loop with a
     * dashboard that redirects guests back to the landing.
     */
    public function test_a_signed_in_visitor_still_gets_the_landing_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Welcome'));
    }
}
