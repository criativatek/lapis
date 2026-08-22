<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class VerificationNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::emailVerification());
    }

    public function test_sends_verification_notification(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect(route('home'));

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_does_not_send_verification_notification_if_email_is_verified(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect(route('dashboard', absolute: false));

        Notification::assertNothingSent();
    }

    /**
     * Clicking "Enviar novamente" against a broken relay must behave exactly
     * like the one at registration: no 500, no invented verification, the
     * failure reported for someone to notice, and the next render of
     * /email/verify told about it (see FortifyServiceProvider::verifyEmailView
     * and User::sendEmailVerificationNotification).
     */
    public function test_resend_with_a_failing_mail_transport_does_not_500(): void
    {
        Exceptions::fake();
        $this->useFailingMailTransport();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect(route('home'));

        $this->assertNull($user->fresh()->email_verified_at);
        Exceptions::assertReported(TransportException::class);

        $this->get(route('verification.notice'))->assertInertia(fn ($page) => $page
            ->component('auth/VerifyEmail')
            ->where('sendFailed', true));
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
