<?php

namespace Tests\Feature;

use App\Support\Seo\LandingSeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a crawler gets.
 *
 * Inertia SSR is off, so every one of these tags is written by Blade and
 * nothing here can be checked by looking at a Vue component. These assertions
 * exist because SEO regressions are silent: nothing breaks, no test fails, the
 * page just quietly stops saying what it is.
 */
class LandingSeoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A result page truncates around sixty characters, and a title that gets
     * cut mid-word is a title nobody reads to the end of.
     */
    #[Test]
    public function the_title_fits_a_result_page_and_says_what_the_product_is(): void
    {
        $this->assertLessThanOrEqual(60, mb_strlen(LandingSeo::TITLE));
        $this->assertStringContainsString('LÁPIS', LandingSeo::TITLE);
        $this->assertStringContainsString('Professores', LandingSeo::TITLE);
    }

    /** Long enough to be worth reading, short enough not to be cut. */
    #[Test]
    public function the_description_fits_a_snippet(): void
    {
        $length = mb_strlen(LandingSeo::DESCRIPTION);

        $this->assertGreaterThanOrEqual(120, $length);
        $this->assertLessThanOrEqual(165, $length);
    }

    /**
     * The server-rendered title is the only one a robot that does not run
     * JavaScript will ever see. If it stops matching the constant, the Vue
     * `<Head>` and the document have drifted apart and nothing else notices.
     */
    #[Test]
    public function the_landing_page_carries_its_seo_tags_in_the_response(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<title>'.LandingSeo::TITLE.'</title>', false)
            ->assertSee('<meta name="description" content="'.LandingSeo::DESCRIPTION.'">', false)
            ->assertSee('<link rel="canonical" href="'.url('/').'">', false)
            ->assertSee('og:title', false)
            ->assertSee('twitter:card', false)
            ->assertSee('application/ld+json', false);
    }

    /** The homepage is the one page that must be indexable. */
    #[Test]
    public function the_landing_page_is_indexable(): void
    {
        $response = $this->get(route('home'))->assertOk();

        $this->assertStringContainsString('name="robots" content="index, follow', $response->getContent());
    }

    /**
     * Everything else is not. The teacher-facing app has nothing to rank for,
     * and the two routes a guest can reach without a login — a student's
     * signed self-assessment and an institutional invitation — must never be
     * in an index at all.
     */
    #[Test]
    public function every_other_page_is_kept_out_of_the_index(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        $this->get('/register')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    /**
     * The single easiest structured-data property to invent, and the one that
     * earns a manual action when it is invented. Nobody has collected a rating,
     * so none may appear.
     */
    #[Test]
    public function the_structured_data_invents_no_social_proof(): void
    {
        $encoded = json_encode(LandingSeo::structuredData());

        foreach (['aggregateRating', 'ratingValue', 'reviewCount', 'review', 'userInteractionCount', 'award'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $encoded);
        }
    }

    /**
     * A price in a search index outlives the page it came from, so only the
     * two standing figures are published — never the Fundador launch price,
     * which ends on a date or on a seat count.
     */
    #[Test]
    public function the_structured_data_publishes_only_the_approved_standing_prices(): void
    {
        $offers = LandingSeo::structuredData()['offers'];

        $this->assertSame(['0', '44.90'], array_column($offers, 'price'));
        $this->assertSame(['EUR', 'EUR'], array_column($offers, 'priceCurrency'));

        // The Fundador condition and «sob consulta» are page copy, not schema.
        $encoded = (string) json_encode($offers);
        $this->assertStringNotContainsString('29', $encoded);
        $this->assertStringNotContainsString('Institucional', $encoded);
    }

    /**
     * Both are routes and not files in public/, so that they name THIS
     * installation's address. A committed robots.txt would have announced
     * production's sitemap from every local copy.
     */
    #[Test]
    public function robots_txt_points_at_this_installations_sitemap(): void
    {
        $response = $this->get('/robots.txt')->assertOk();

        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $response->assertSee('Sitemap: '.url('/sitemap.xml'), false);
        $response->assertSee('Disallow: /auto/', false);
    }

    #[Test]
    public function the_sitemap_lists_the_one_public_page(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();

        $this->assertStringStartsWith('application/xml', (string) $response->headers->get('Content-Type'));
        $response->assertSee('<loc>'.url('/').'</loc>', false);

        // Nothing the meta robots tells a crawler to skip may be advertised here.
        $response->assertDontSee('/login', false);
        $response->assertDontSee('/register', false);
    }
}
