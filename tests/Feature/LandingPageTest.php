<?php

namespace Tests\Feature;

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
     * The pricing cards say «tudo do Base, mais…», so the second and third plan
     * must carry only their own additions. A plan that repeated the whole list
     * would render three identical columns.
     */
    public function test_each_plan_after_the_first_lists_only_what_it_adds(): void
    {
        $plans = $this->get(route('home'))->viewData('page')['props']['plans'];

        $this->assertSame($plans[0]['modules'], $plans[0]['adds']);

        $this->assertContains('Perfis de Avaliação', $plans[0]['modules']);
        $this->assertNotContains('Perfis de Avaliação', $plans[1]['adds']);
        $this->assertContains('Apoio de IA', $plans[1]['adds']);
        $this->assertContains('Administração Institucional', $plans[2]['adds']);

        // Institucional still carries everything, even though it only ADDS five.
        $this->assertContains('Perfis de Avaliação', $plans[2]['modules']);
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
