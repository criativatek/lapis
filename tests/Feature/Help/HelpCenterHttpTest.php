<?php

namespace Tests\Feature\Help;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Centro de Ajuda's HTTP surface (A2, Onboarding & Help). Same shape as
 * ChangelogTest: `auth`+`verified` only, no `organization` — the article set
 * is not tenant data, so a user with no resolved organization must still be
 * able to reach it.
 */
class HelpCenterHttpTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_logged_in_user_can_view_the_help_index_without_a_resolved_organization(): void
    {
        $user = User::factory()->withoutOrganization()->create();

        $this->actingAs($user)
            ->get('/help')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('help/Index')->has('categories'));
    }

    #[Test]
    public function a_guest_is_redirected_to_login_from_every_help_route(): void
    {
        $this->get('/help')->assertRedirect('/login');
        $this->get('/help/search?q=turma')->assertRedirect('/login');
        $this->get('/help/classes.create')->assertRedirect('/login');
    }

    #[Test]
    public function show_renders_the_article_for_a_real_id(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/help/classes.create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('help/Show')
                ->where('article.id', 'classes.create')
                ->where('article.title', 'Criar uma turma'));
    }

    #[Test]
    public function show_404s_cleanly_for_a_non_existent_id_rather_than_erroring(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/help/does-not-exist')
            ->assertNotFound();
    }

    #[Test]
    public function search_renders_matching_results_for_a_real_query(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/help/search?q=turma')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('help/Search')
                ->where('query', 'turma')
                ->where('results.0.id', 'classes.create'));
    }

    #[Test]
    public function search_with_no_query_renders_no_results_rather_than_every_article(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/help/search')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('help/Search')
                ->where('query', '')
                ->where('results', []));
    }
}
