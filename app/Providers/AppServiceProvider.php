<?php

namespace App\Providers;

use App\Models\PlatformSetting;
use App\Policies\OrganizationMembershipPolicy;
use App\Services\Ai\AiTextProviders;
use App\Services\Ai\Providers\ChatCompletionsProvider;
use App\Services\Ai\Providers\FakeAiTextProvider;
use App\Services\Import\Correction\CorrectionGridParserRegistry;
use App\Services\Import\Correction\GenericSpreadsheetParser;
use App\Services\Import\Correction\IntuitivoXlsxParser;
use App\Services\Import\Correction\PlickersCsvParser;
use App\Support\Entitlements\Entitlements;
use App\Support\Release\BuildStamp;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // Which correction-grid formats LÁPIS can read is decided in exactly one
        // place. A new parser is registered here and the interface, the upload
        // rules and the source list all widen together — nothing else branches
        // on a source (§11).
        $this->app->singleton(CorrectionGridParserRegistry::class, fn (Application $app): CorrectionGridParserRegistry => new CorrectionGridParserRegistry([
            $app->make(PlickersCsvParser::class),
            $app->make(IntuitivoXlsxParser::class),
            // Last on purpose: it is the fallback for a sheet nobody wrote an
            // adapter for, and a file that IS a known export should be read by
            // the parser that understands it rather than mapped by hand.
            $app->make(GenericSpreadsheetParser::class),
        ]));

        $this->registerWritingAssistant();
    }

    /**
     * The optional layer that rephrases text LÁPIS already wrote.
     *
     * Singletons, so a single request always talks to the same engine — which is
     * what lets a test script a sequence of answers and have them arrive in
     * order. Neither is CONSTRUCTED here: nothing is built until something asks
     * AiTextProviders for one, and on a fresh installation nothing ever does,
     * because no driver is configured (§8).
     *
     * The key is read at construction from config, which reads it from the
     * environment. It is never stored, never shared with the frontend, and the
     * provider that holds it never puts it in an exception (§47).
     */
    protected function registerWritingAssistant(): void
    {
        $this->app->singleton(AiTextProviders::class);

        $this->app->singleton(ChatCompletionsProvider::class, fn (): ChatCompletionsProvider => new ChatCompletionsProvider(
            endpoint: (string) config('lapis.ai.endpoint'),
            key: (string) config('lapis.ai.key'),
            model: (string) config('lapis.ai.model'),
            timeout: (int) config('lapis.ai.timeout'),
        ));

        $this->app->singleton(FakeAiTextProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerOrganizationMembershipAbilities();
        $this->configureDefaults();
        $this->applyPlatformMailSettings();
        $this->describeRelease();
        $this->limitWritingAssistant();
    }

    protected function registerOrganizationMembershipAbilities(): void
    {
        Gate::define('remove', [OrganizationMembershipPolicy::class, 'remove']);
        Gate::define('transferOwnership', [OrganizationMembershipPolicy::class, 'transferOwnership']);
        Gate::define('viewReassignments', [OrganizationMembershipPolicy::class, 'viewReassignments']);
    }

    /**
     * Two ceilings, and a request has to pass both (§28).
     *
     * PER USER, so one teacher holding down a button cannot spend the school's
     * budget. PER ORGANIZATION, so thirty teachers each within their own limit
     * still cannot. The second is the one that actually protects the bill; the
     * first is the one that catches the accident.
     *
     * A request with no resolved tenant gets no organization bucket rather than
     * sharing a global one — an unauthenticated caller cannot reach this route
     * at all, so there is nothing to fall back to.
     */
    protected function limitWritingAssistant(): void
    {
        RateLimiter::for('report-writing-assistant', function (Request $request): array {
            $organization = app(CurrentOrganization::class);

            $limits = [
                Limit::perMinute((int) config('lapis.ai.per_minute'))->by('user:'.$request->user()?->getKey()),
            ];

            if ($organization->isResolved()) {
                $limits[] = Limit::perMinute((int) config('lapis.ai.organization_per_minute'))
                    ->by('organization:'.$organization->get()->getKey());
            }

            return $limits;
        });
    }

    /**
     * Put the running version and commit into `php artisan about`, so the
     * standard Laravel question gets the answer without anyone having to know
     * that `lapis:release-check` exists. Same single source as the check itself.
     */
    protected function describeRelease(): void
    {
        AboutCommand::add('LÁPIS', fn (): array => [
            'Version' => config('app.version'),
            'Commit' => BuildStamp::read()?->shortCommit() ?? '(sem carimbo de build)',
        ]);
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

        // Bypass the model's 'encrypted' cast and decrypt explicitly: a magic
        // property read hides the throw from static analysis, and we need the
        // try/catch to actually guard against a ciphertext that no longer matches
        // APP_KEY (key rotated, or the row came from another environment's database).
        $rawPassword = $settings->getRawOriginal('mail_password');

        try {
            $password = $rawPassword === null ? null : Crypt::decryptString($rawPassword);
        } catch (DecryptException $exception) {
            // Fall back to .env mail config instead of 500ing every request in the
            // app; report() logs that the operator has to re-save the SMTP password
            // from /admin to restore system email.
            report($exception);

            return;
        }

        config([
            'mail.default' => 'smtp', // app-level system mail is always SMTP
            'mail.mailers.smtp.host' => $settings->mail_host,
            'mail.mailers.smtp.port' => $settings->mail_port,
            'mail.mailers.smtp.username' => $settings->mail_username,
            'mail.mailers.smtp.password' => $password,
            // Symfony wants the scheme, not "ssl"/"tls": SSL (port 465) = implicit
            // TLS = `smtps`; TLS/none uses STARTTLS, which is the null default.
            'mail.mailers.smtp.scheme' => $settings->mail_encryption === 'ssl' ? 'smtps' : null,
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
