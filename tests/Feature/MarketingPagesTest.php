<?php

namespace Tests\Feature;

use App\Support\Seo\PublicPages;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The marketing site is a set of public pages declared in PublicPages. This
 * asserts that every page declared there actually answers, canonicalises to
 * itself, fits a search result, and is listed for crawlers — so adding a
 * page to the list without a route, or a route without the list, fails here.
 */
class MarketingPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lapis.public_url' => 'https://lapispro.com']);
        $this->seed(EntitlementsSeeder::class);
    }

    /** @return array<string, array{string}> */
    public static function publicPaths(): array
    {
        $cases = [];

        foreach (PublicPages::all() as $page) {
            $cases[$page['path']] = [$page['path']];
        }

        return $cases;
    }

    #[DataProvider('publicPaths')]
    #[Test]
    public function every_declared_public_page_answers_without_an_account(string $path): void
    {
        $this->get($path)->assertOk();
    }

    #[DataProvider('publicPaths')]
    #[Test]
    public function every_public_page_is_indexable_and_canonical_to_itself(string $path): void
    {
        $response = $this->get($path)->assertOk();

        $response->assertSee('<link rel="canonical" href="'.PublicPages::url($path).'">', false);
        $response->assertSee('name="robots" content="index, follow', false);
        $response->assertSee('property="og:image"', false);
        $response->assertSee('data-theme-lock="light"', false);
    }

    #[Test]
    public function titles_and_descriptions_fit_a_search_result(): void
    {
        foreach (PublicPages::all() as $page) {
            $this->assertLessThanOrEqual(60, mb_strlen($page['title']), $page['path'].' title');
            $this->assertLessThanOrEqual(160, mb_strlen($page['description']), $page['path'].' description');
            $this->assertStringContainsString('Lapispro', $page['title'], $page['path'].' title names the brand');
        }
    }

    #[Test]
    public function each_page_renders_its_own_title(): void
    {
        foreach (PublicPages::all() as $page) {
            $this->get($page['path'])->assertSee('<title>'.htmlspecialchars($page['title']).'</title>', false);
        }
    }

    /**
     * With SSR on, the Vue <Head> title replaces the blade <title> in the
     * served HTML, so the prop the page reads must be the declared title.
     */
    #[Test]
    public function the_marketing_pages_pass_the_declared_title_to_their_head(): void
    {
        foreach (PublicPages::all() as $page) {
            if (! str_starts_with($page['component'], 'marketing/')) {
                continue;
            }

            $this->get($page['path'])->assertInertia(fn ($inertia) => $inertia
                ->component($page['component'])
                ->where('seoTitle', $page['title']));
        }
    }

    #[Test]
    public function the_sitemap_and_robots_list_every_public_page(): void
    {
        $sitemap = $this->get('/sitemap.xml')->assertOk();
        $robots = $this->get('/robots.txt')->assertOk();

        foreach (PublicPages::all() as $page) {
            $sitemap->assertSee('<loc>'.PublicPages::url($page['path']).'</loc>', false);

            if ($page['path'] !== '/') {
                $robots->assertSee('Allow: '.$page['path'], false);
            }
        }
    }

    #[Test]
    public function an_unknown_feature_is_a_404_not_a_page(): void
    {
        $this->get('/funcionalidades/inventada')->assertNotFound();
    }

    #[Test]
    public function the_plans_page_carries_the_plan_cards(): void
    {
        $this->get('/planos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('marketing/Plans')
                ->has('plans', 3));
    }

    #[Test]
    public function the_about_page_names_the_same_entity_as_the_legal_pages(): void
    {
        config([
            'lapis.legal.controller_name' => 'Entidade Exemplo, Lda.',
            'lapis.legal.controller_vat' => '999999990',
            'lapis.legal.controller_address' => 'Rua Exemplo 1, Leiria',
            'lapis.legal.privacy_email' => 'privacidade@exemplo.pt',
        ]);

        $this->get('/sobre')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('marketing/About')
                ->where('entity.name', 'Entidade Exemplo, Lda.')
                ->where('entity.privacyEmail', 'privacidade@exemplo.pt'));
    }
}
