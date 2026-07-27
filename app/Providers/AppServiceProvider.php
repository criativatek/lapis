<?php

namespace App\Providers;

use App\Models\PlatformSetting;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentOrganization::class);
        $this->app->singleton(Entitlements::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->applyPlatformMailSettings();
    }

    /**
     * Let the platform's stored SMTP settings override the .env mail config, so
     * the operator configures system email from the backoffice. Guarded against a
     * missing table (fresh install / mid-migration): the .env config stays.
     * ponytail: one indexed-row read per boot — cache it if it ever shows up in profiles.
     */
    protected function applyPlatformMailSettings(): void
    {
        try {
            $settings = PlatformSetting::query()->first();
        } catch (\Throwable) {
            return; // table not there yet
        }

        if ($settings === null || ! $settings->mailConfigured()) {
            return;
        }

        config([
            'mail.default' => $settings->mail_mailer ?: 'smtp',
            'mail.mailers.smtp.host' => $settings->mail_host,
            'mail.mailers.smtp.port' => $settings->mail_port,
            'mail.mailers.smtp.username' => $settings->mail_username,
            'mail.mailers.smtp.password' => $settings->mail_password,
            'mail.mailers.smtp.scheme' => $settings->mail_encryption ?: null,
            'mail.from.address' => $settings->mail_from_address ?: config('mail.from.address'),
            'mail.from.name' => $settings->mail_from_name ?: config('mail.from.name'),
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Mass assignment is opt-in per model via #[Fillable]; an unguarded model
        // would let a request set organization_id and write into another tenant.
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
