<?php

namespace Tests\Feature\Ssr;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Ssr\SsrErrorType;
use Inertia\Ssr\SsrRenderFailed;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An SSR failure leaves a line in laravel.log — and never the page it was
 * rendering.
 *
 * `HttpGateway` fires `SsrRenderFailed` with the WHOLE page attached, props
 * included. For a report that is student names and pedagogical observations.
 * `LogSsrRenderFailure` writes where it failed (component, path, error,
 * type, source location, release) and nothing else; these tests feed it a
 * page full of fictitious sensitive text and check that none of it reaches
 * the log context.
 */
class SsrRenderFailureLoggingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    protected function pageFullOfSensitiveText(): array
    {
        return [
            'component' => 'reports/Show',
            'url' => '/reports/01JFICTICIO?rascunho=Observa%C3%A7%C3%A3o',
            'version' => 'abc',
            'props' => [
                'auth' => ['user' => ['name' => 'Docente Fictícia', 'email' => 'docente@exemplo.test']],
                'report' => ['title' => 'Relatório da aluna Ana Fictícia'],
                'sections' => [['body' => 'Observação pedagógica confidencial sobre a aluna.']],
            ],
        ];
    }

    #[Test]
    public function a_render_failure_is_logged_as_an_error_with_where_but_never_what(): void
    {
        Log::spy();

        event(new SsrRenderFailed(
            page: $this->pageFullOfSensitiveText(),
            error: "Cannot read properties of undefined (reading 'footer')",
            type: SsrErrorType::Render,
            hint: 'An error occurred while rendering the component.',
            stack: "TypeError: Cannot read properties of undefined (reading 'footer')\n    at Ana Fictícia",
            sourceLocation: 'resources/js/components/AppSidebar.vue:23:71',
        ));

        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            $this->assertSame('inertia.ssr.render_failed', $message);
            $this->assertSame('reports/Show', $context['component']);
            $this->assertSame('/reports/01JFICTICIO', $context['route'], 'A query string nunca chega ao log.');
            $this->assertSame("Cannot read properties of undefined (reading 'footer')", $context['error']);
            $this->assertSame('render', $context['type']);
            $this->assertSame('resources/js/components/AppSidebar.vue:23:71', $context['source_location']);
            $this->assertSame(config('app.version'), $context['release']);

            $this->assertArrayNotHasKey('props', $context);
            $this->assertArrayNotHasKey('page', $context);
            $this->assertArrayNotHasKey('stack', $context);
            $this->assertArrayNotHasKey('url', $context);

            foreach (['Fictícia', 'Docente', 'docente@exemplo.test', 'confidencial', 'Observa', 'rascunho'] as $secret) {
                $this->assertStringNotContainsString($secret, $encoded, "«{$secret}» não pode aparecer no log.");
            }

            return true;
        });
    }

    #[Test]
    public function an_unreachable_ssr_server_is_a_warning_not_an_error(): void
    {
        Log::spy();

        event(new SsrRenderFailed(
            page: $this->pageFullOfSensitiveText(),
            error: 'Connection refused',
            type: SsrErrorType::Connection,
        ));

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('inertia.ssr.unavailable', $message);
            $this->assertSame('connection', $context['type']);
            $this->assertStringNotContainsString('Fictícia', json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return true;
        });
        Log::shouldNotHaveReceived('error');
    }

    #[Test]
    public function a_page_whose_ssr_render_fails_still_opens_and_is_logged(): void
    {
        // End to end through the real HttpGateway: the Node server answers
        // /render with the 500 the new ssr.ts sends, the page falls back to
        // the client bootstrap, and one error line is written.
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.ensure_bundle_exists' => false,
        ]);

        Http::fake([
            '*/render' => Http::response(['error' => "Cannot read properties of undefined (reading 'sections')", 'type' => 'render'], 500),
        ]);

        Log::spy();

        $this->get('/')
            ->assertOk()
            ->assertSee('data-page=', false);

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $message === 'inertia.ssr.render_failed'
            && $context['component'] === 'Welcome'
            && $context['route'] === '/'
            && ! array_key_exists('props', $context));
    }
}
