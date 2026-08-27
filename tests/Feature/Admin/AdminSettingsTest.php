<?php

namespace Tests\Feature\Admin;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Platform SMTP settings: stored in the DB, applied over the .env at boot, with a
 * write-only password.
 */
class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    #[Test]
    public function the_stored_smtp_overrides_the_mail_config_at_boot(): void
    {
        PlatformSetting::current()->update([
            'mail_host' => 'smtp.example.com',
            'mail_port' => 2525,
            'mail_username' => 'user',
            'mail_password' => 'secret',
            'mail_encryption' => 'tls',
        ]);

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame(2525, config('mail.mailers.smtp.port'));
        $this->assertSame('secret', config('mail.mailers.smtp.password')); // decrypts
    }

    #[Test]
    public function a_blank_password_keeps_the_stored_one(): void
    {
        PlatformSetting::current()->update(['mail_host' => 'h1', 'mail_password' => 'keepme']);

        $this->actingAs($this->admin())->put('/admin/settings', [
            'mail_host' => 'h2',
            'mail_password' => '',
        ])->assertRedirect();

        $fresh = PlatformSetting::current();
        $this->assertSame('h2', $fresh->mail_host);
        $this->assertSame('keepme', $fresh->mail_password);
    }

    #[Test]
    public function the_password_is_never_sent_to_the_frontend(): void
    {
        PlatformSetting::current()->update(['mail_host' => 'h', 'mail_password' => 'secret']);

        $this->actingAs($this->admin())->get('/admin/settings')->assertInertia(
            fn ($page) => $page
                ->component('admin/Settings')
                ->where('settings.password_set', true)
                ->missing('settings.mail_password'),
        );
    }

    /**
     * The public contact address is edited here, not in the code: it is what
     * «Falar connosco» on the landing page opens, and it must be changeable
     * without a deploy. It is validated as an email so a typo cannot ship a
     * broken mailto to every visitor.
     */
    #[Test]
    public function the_operator_sets_the_public_contact_address(): void
    {
        $this->actingAs($this->admin())->put('/admin/settings', [
            'contact_email' => 'geral@exemplo.pt',
        ])->assertRedirect();

        $this->assertSame('geral@exemplo.pt', PlatformSetting::current()->publicContactEmail());

        $this->actingAs($this->admin())->put('/admin/settings', [
            'contact_email' => 'nao-e-um-email',
        ])->assertSessionHasErrors('contact_email');

        $this->assertSame('geral@exemplo.pt', PlatformSetting::current()->publicContactEmail());
    }

    #[Test]
    public function a_teacher_cannot_open_the_settings(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/settings')->assertForbidden();
    }
}
