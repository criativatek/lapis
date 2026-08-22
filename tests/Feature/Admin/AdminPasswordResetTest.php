<?php

namespace Tests\Feature\Admin;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class AdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    /** @return array{User, Organization} */
    private function targetAccount(): array
    {
        $owner = User::factory()->create();

        return [$owner, $owner->personalOrganization()];
    }

    private function auditEvent(Organization $organization, string $event): AuditEvent
    {
        return app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => AuditEvent::where('event', $event)->sole(),
        );
    }

    #[Test]
    public function a_platform_admin_can_request_a_reset_link_from_the_account_page(): void
    {
        Notification::fake();
        [$owner, $organization] = $this->targetAccount();

        $this->actingAs($this->admin())
            ->get("/admin/accounts/{$organization->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/AccountShow')->where('account.owner.email', $owner->email));

        $originalPassword = $owner->password;

        $this->post("/admin/accounts/{$organization->ulid}/reset-password")
            ->assertRedirect()
            ->assertSessionHas('inertia.flash_data', fn ($flash) => ($flash['toast']['message'] ?? null) === 'Email de redefinição enviado.');

        Notification::assertSentTo($owner, ResetPassword::class);
        $this->assertSame($originalPassword, $owner->fresh()->password);
    }

    #[Test]
    public function the_account_page_component_contains_both_password_dialogs_and_the_one_time_panel(): void
    {
        $component = file_get_contents(resource_path('js/pages/admin/AccountShow.vue'));

        $this->assertIsString($component);
        $this->assertStringContainsString('<DialogTitle>Repor palavra-passe</DialogTitle>', $component);
        $this->assertStringContainsString('Este utilizador está desativado — repor a palavra-passe não reativa o acesso.', $component);
        $this->assertStringContainsString('>Enviar link</Button>', $component);
        $this->assertStringContainsString('<DialogTitle>Gerar palavra-passe temporária</DialogTitle>', $component);
        $this->assertStringContainsString('e mostrada uma única vez.', $component);
        $this->assertStringContainsString('>Copiar</Button>', $component);
    }

    #[Test]
    public function a_non_admin_cannot_use_either_password_action(): void
    {
        [, $organization] = $this->targetAccount();

        $this->actingAs(User::factory()->create())
            ->post("/admin/accounts/{$organization->ulid}/reset-password")
            ->assertForbidden();
        $this->post("/admin/accounts/{$organization->ulid}/temporary-password")->assertForbidden();
    }

    #[Test]
    public function both_password_routes_inherit_the_web_middleware_group(): void
    {
        foreach (['admin.accounts.reset-password', 'admin.accounts.temporary-password'] as $routeName) {
            $route = app('router')->getRoutes()->getByName($routeName);

            $this->assertNotNull($route);
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('platform-admin', $route->gatherMiddleware());
        }
    }

    #[Test]
    public function a_reset_link_targets_only_the_owner_from_the_bound_organization(): void
    {
        Notification::fake();
        [$ownerA, $organizationA] = $this->targetAccount();
        [$ownerB] = $this->targetAccount();

        $this->actingAs($this->admin())->post("/admin/accounts/{$organizationA->ulid}/reset-password")->assertRedirect();

        Notification::assertSentTo($ownerA, ResetPassword::class);
        Notification::assertNotSentTo($ownerB, ResetPassword::class);
    }

    #[Test]
    public function an_impersonated_session_cannot_use_either_password_action(): void
    {
        [$target, $targetOrganization] = $this->targetAccount();
        [, $otherOrganization] = $this->targetAccount();

        $this->actingAs($this->admin())
            ->post("/admin/accounts/{$targetOrganization->ulid}/impersonate")
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($target);

        $this->post("/admin/accounts/{$otherOrganization->ulid}/reset-password")->assertForbidden();
        $this->post("/admin/accounts/{$otherOrganization->ulid}/temporary-password")->assertForbidden();
    }

    #[Test]
    public function reset_link_audit_contains_no_token_or_password(): void
    {
        Notification::fake();
        [$owner, $organization] = $this->targetAccount();
        $token = null;

        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/reset-password");

        Notification::assertSentTo($owner, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $audit = $this->auditEvent($organization, 'admin.password_reset_requested');
        $serializedAudit = json_encode(['summary' => $audit->summary, 'properties' => $audit->properties], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString((string) $token, $serializedAudit);
        $this->assertStringNotContainsString('password', strtolower($serializedAudit));
        $this->assertStringNotContainsString('token', strtolower($serializedAudit));
    }

    #[Test]
    public function broker_throttling_is_reported_as_an_error_and_not_a_second_success(): void
    {
        Notification::fake();
        [$owner, $organization] = $this->targetAccount();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/accounts/{$organization->ulid}/reset-password")->assertRedirect();
        $this->post("/admin/accounts/{$organization->ulid}/reset-password")
            ->assertSessionHasErrors('account');

        Notification::assertSentToTimes($owner, ResetPassword::class, 1);
        $auditCount = app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => AuditEvent::where('event', 'admin.password_reset_requested')->count(),
        );
        $this->assertSame(1, $auditCount);
    }

    #[Test]
    public function a_deactivated_owner_can_reset_the_password_but_still_cannot_log_in(): void
    {
        Notification::fake();
        [$owner, $organization] = $this->targetAccount();
        $owner->forceFill(['deactivated_at' => now()])->save();
        $token = null;

        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/reset-password");
        Notification::assertSentTo($owner, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        auth()->logout();
        $this->flushSession();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $owner->email,
            'password' => 'New-secure-password-123!',
            'password_confirmation' => 'New-secure-password-123!',
        ])->assertSessionHasNoErrors();

        $this->post('/login', ['email' => $owner->email, 'password' => 'New-secure-password-123!'])
            ->assertSessionHasErrors(['email' => 'A sua conta foi desativada. Contacte o suporte do LÁPIS.']);
        $this->assertGuest();
    }

    /**
     * `Password::sendResetLink()` calls the owner's notification send directly,
     * unguarded (Illuminate\Auth\Passwords\PasswordBroker) — a relay bounce
     * throws, it does not return a status string. Proves the controller catches
     * that too, not just the broker's own INVALID_USER/RESET_THROTTLED statuses.
     */
    #[Test]
    public function a_mail_transport_failure_is_reported_as_an_error_and_never_a_500(): void
    {
        Exceptions::fake();
        $this->useFailingMailTransport();
        [$owner, $organization] = $this->targetAccount();
        $originalPassword = $owner->password;

        $this->actingAs($this->admin())
            ->post("/admin/accounts/{$organization->ulid}/reset-password")
            ->assertSessionHasErrors('account');

        Exceptions::assertReported(TransportException::class);
        $this->assertSame($originalPassword, $owner->fresh()->password);

        $auditCount = app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => AuditEvent::where('event', 'admin.password_reset_requested')->count(),
        );
        $this->assertSame(0, $auditCount);
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

    #[Test]
    public function a_temporary_password_works_is_shown_once_and_is_never_audited(): void
    {
        [$owner, $organization] = $this->targetAccount();
        $originalPassword = $owner->password;

        $response = $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/temporary-password");
        $response->assertRedirect()->assertSessionHas('inertia.flash_data');

        $flash = session('inertia.flash_data');
        $generated = $flash['temporary_password']['value'] ?? null;

        $this->assertIsString($generated);
        $this->assertSame(14, strlen($generated));
        $this->assertNotSame($originalPassword, $owner->fresh()->password);
        $this->assertTrue(Hash::check($generated, $owner->fresh()->password));

        $audit = $this->auditEvent($organization, 'admin.password_temporary_generated');
        $serializedAudit = json_encode(['summary' => $audit->summary, 'properties' => $audit->properties], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($generated, $serializedAudit);
        $this->assertStringNotContainsString('password', strtolower($serializedAudit));
        $this->assertStringNotContainsString('token', strtolower($serializedAudit));

        $this->get("/admin/accounts/{$organization->ulid}")->assertOk();
        $this->assertNull(session('inertia.flash_data'));

        auth()->logout();
        $this->flushSession();
        $this->post('/login', ['email' => $owner->email, 'password' => $generated]);
        $this->assertAuthenticatedAs($owner->fresh());
    }
}
