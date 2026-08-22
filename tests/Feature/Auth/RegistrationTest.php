<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Laravel\Fortify\Features;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_an_ordinary_visit_has_no_prefilled_email(): void
    {
        $this->get(route('register'))->assertInertia(fn ($page) => $page
            ->component('auth/Register')
            ->where('prefillEmail', null));
    }

    /**
     * `prefillEmail` becomes the email field's `:default-value` — a one-time seed
     * for the input, not a value Vue keeps re-imposing on every re-render. That
     * distinction is the whole point: binding it as a plain `:value` instead used
     * to make the field un-editable (every keystroke got wiped back to empty) the
     * moment ANY reactive state on the page changed. This only proves the prop
     * still reaches the page correctly; the editing behaviour itself is a client
     * concern, verified manually in the browser, not asserted here.
     */
    public function test_an_invited_email_is_prefilled_on_the_register_page(): void
    {
        session(['invitation_email' => 'convidada@escola.pt']);

        $this->get(route('register'))->assertInertia(fn ($page) => $page
            ->component('auth/Register')
            ->where('prefillEmail', 'convidada@escola.pt'));
    }

    /**
     * A bounce, a greylisted relay, SMTP momentarily down — none of that is
     * the new account's fault. This proves the account, its personal
     * organization, its membership and its subscription all survive a
     * transport failure on the verification email exactly as they would on a
     * normal registration; only the send itself fails, quietly, reported for
     * someone to notice, never surfaced as a 500 or an invented "verified".
     */
    public function test_registration_survives_a_mail_transport_failure(): void
    {
        Exceptions::fake();
        $this->useFailingMailTransport();

        $response = $this->post(route('register.store'), [
            'name' => 'Conta Resiliente',
            'email' => 'resiliente@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();

        $user = User::where('email', 'resiliente@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);

        $organization = Organization::where('owner_id', $user->id)->first();
        $this->assertNotNull($organization);
        $this->assertTrue($organization->members()->whereKey($user->getKey())->exists());
        $this->assertTrue(
            OrganizationSubscription::withoutGlobalScope('organization')
                ->where('organization_id', $organization->id)
                ->exists(),
        );

        Exceptions::assertReported(TransportException::class);
    }

    /**
     * Registers a fake "always fails" mail transport and points the default
     * mailer at it, so the real Notification -> Mail -> Transport pipeline
     * runs end to end and genuinely throws — not `Notification::fake()`,
     * which would short-circuit before ever reaching the code under test.
     */
    protected function useFailingMailTransport(): void
    {
        Mail::extend('always-fails', fn (): TransportInterface => new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('Simulated SMTP failure for tests.');
            }

            public function __toString(): string
            {
                return 'always-fails';
            }
        });

        config([
            'mail.mailers.always-fails' => ['transport' => 'always-fails'],
            'mail.default' => 'always-fails',
        ]);
    }
}
