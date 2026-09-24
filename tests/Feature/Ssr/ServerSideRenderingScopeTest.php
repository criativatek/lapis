<?php

namespace Tests\Feature\Ssr;

use App\Models\User;
use App\Support\Ssr\ServerRenderedPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SSR is for crawlers, and crawlers only ever see the public pages.
 *
 * From 0.93.0 to 0.152.0 every Inertia response was sent to the Node SSR
 * server — including the authenticated shell, which was never written to run
 * there and died on `page.props.nav` / `selectableAcademicYears` exactly as
 * production's ssr.log recorded. These tests pin the correction: the SSR
 * gateway is asked to render `Welcome`, `marketing/*` and `legal/*`, and
 * NOTHING that carries the app shell. Asserted against the real HttpGateway
 * with the HTTP client faked, so the switch is proven where it is actually
 * consulted — not against a stub of the rule.
 */
class ServerSideRenderingScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml turns SSR off for the suite (a rendered <Head> would
        // replace the blade <title> the other tests read). It is turned back
        // on here, with the bundle check off (there is no bootstrap/ssr in
        // CI) and the Node server replaced by a fake that answers every
        // /render with a marker the page body can be checked for.
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.ensure_bundle_exists' => false,
        ]);

        Http::fake([
            '*/render' => Http::response(['head' => [], 'body' => '<div id="app" data-rendered-on="server"></div>']),
        ]);
    }

    protected function assertRenderedOnServer(string $component): void
    {
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/render')
            && $request['component'] === $component);
    }

    #[Test]
    public function the_landing_page_is_rendered_on_the_server(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-rendered-on="server"', false);

        $this->assertRenderedOnServer('Welcome');
    }

    #[Test]
    public function the_marketing_pages_are_rendered_on_the_server(): void
    {
        $this->get('/planos')->assertOk()->assertSee('data-rendered-on="server"', false);

        $this->assertRenderedOnServer('marketing/Plans');
    }

    #[Test]
    public function the_legal_documents_are_rendered_on_the_server(): void
    {
        $this->get('/termos')->assertOk()->assertSee('data-rendered-on="server"', false);

        $this->assertRenderedOnServer('legal/Document');
    }

    #[Test]
    public function an_authenticated_page_is_never_sent_to_the_ssr_server(): void
    {
        $teacher = User::factory()->create();

        $this->actingAs($teacher)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('data-rendered-on="server"', false)
            // The client-side bootstrap the browser hydrates from.
            ->assertSee('data-page=', false);

        Http::assertNothingSent();
    }

    #[Test]
    public function a_locked_module_page_is_never_sent_to_the_ssr_server(): void
    {
        // RequireModule renders ModuleUnavailable from inside the middleware
        // stack, with the shell — the one Inertia page not produced by a
        // controller, and therefore worth proving separately.
        $teacher = User::factory()->create();

        $this->actingAs($teacher)
            ->get('/activity/organization')
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page->component('ModuleUnavailable'));

        Http::assertNothingSent();
    }

    #[Test]
    public function the_global_off_switch_still_turns_ssr_off_for_the_public_pages_too(): void
    {
        // `HttpGateway` stops reading `inertia.ssr.enabled` the moment a
        // `disable()` condition is set, so the condition has to read it
        // itself — or INERTIA_SSR_ENABLED=false in production's .env (the
        // documented way to switch SSR off) would quietly stop working.
        config(['inertia.ssr.enabled' => false]);

        $this->get('/')->assertOk()->assertSee('data-page=', false);

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{string|null, bool}>
     */
    public static function components(): array
    {
        return [
            'landing' => ['Welcome', true],
            'marketing' => ['marketing/Plans', true],
            'legal' => ['legal/Document', true],
            'a report' => ['reports/Show', false],
            'the dashboard' => ['Dashboard', false],
            'a print view' => ['student-progress/Print', false],
            'the locked-module page' => ['ModuleUnavailable', false],
            'a settings page' => ['settings/Profile', false],
            'nothing' => ['', false],
            'unknown' => [null, false],
        ];
    }

    #[Test]
    #[DataProvider('components')]
    public function the_rule_mirrors_the_pages_without_an_app_shell(?string $component, bool $expected): void
    {
        $this->assertSame($expected, ServerRenderedPages::wants($component));
    }
}
