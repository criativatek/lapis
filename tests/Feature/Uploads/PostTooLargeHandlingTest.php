<?php

namespace Tests\Feature\Uploads;

use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Inertia\Support\SessionKey;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A request body that outgrows post_max_size never reaches Laravel's own
 * validation: ValidatePostSize throws this from the GLOBAL middleware group,
 * before the "web" group — and StartSession within it — ever runs. There is
 * no session already attached to the request at this point, only the cookie
 * that would let one resume; the handler has to start it by hand, exactly
 * the way StartSession itself does. Without that, the teacher would see a
 * raw Laravel error page (a stack trace under APP_DEBUG=true, a bare "Server
 * Error" otherwise) instead of the same friendly toast every other upload
 * failure on this app already uses (Fatia G).
 */
class PostTooLargeHandlingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_redirects_back_with_a_friendly_toast_instead_of_the_default_error_page(): void
    {
        $user = User::factory()->create();
        // A real request first, so there is a genuine session on disk and a
        // real cookie carrying its id — this is exactly what a browser still
        // has when ValidatePostSize rejects the next, oversized request.
        $this->actingAs($user)->get('/dashboard');
        $session = $this->app['session.store'];

        $request = Request::create('/classes/x/students/y/photo', 'POST');
        $request->headers->set('referer', 'http://lapis.test/classes/x');
        $request->cookies->set($session->getName(), $session->getId());

        $response = app(ExceptionHandler::class)->render($request, new PostTooLargeException);

        $this->assertTrue($response->isRedirect());
        $this->assertSame('http://lapis.test/classes/x', $response->headers->get('Location'));

        // Read the flash back from storage rather than the in-memory $session
        // object above: the handler resolves its own driver instance and
        // saves independently, so storage is the only thing both sides share.
        $reloaded = $this->app['session']->driver();
        $reloaded->setId($session->getId());
        $reloaded->start();
        $flashed = $reloaded->get(SessionKey::FLASH_DATA);
        $this->assertSame('error', $flashed['toast']['type']);
        $this->assertStringContainsString('demasiado grande', $flashed['toast']['message']);

        // Never a stack trace or the exception's own class name leaking through.
        $content = $response->getContent() ?? '';
        $this->assertStringNotContainsString('PostTooLargeException', $content);
        $this->assertStringNotContainsString('Stack trace', $content);
    }

    #[Test]
    public function it_falls_back_to_the_dashboard_when_the_request_carries_no_referer(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard');
        $session = $this->app['session.store'];

        $request = Request::create('/classes/x/students/y/photo', 'POST');
        $request->cookies->set($session->getName(), $session->getId());

        $response = app(ExceptionHandler::class)->render($request, new PostTooLargeException);

        $this->assertTrue($response->isRedirect());
        $this->assertSame(route('dashboard'), $response->headers->get('Location'));
    }
}
