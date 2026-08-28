<?php

namespace App\Providers;

use App\Models\PlatformSetting;
use App\Policies\OrganizationMembershipPolicy;
use App\Services\Ai\AiTextProviders;
use App\Services\Ai\Providers\ChatCompletionsProvider;
use App\Services\Ai\Providers\FakeAiTextProvider;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Import\Correction\CorrectionGridParserRegistry;
use App\Services\Import\Correction\GenericSpreadsheetParser;
use App\Services\Import\Correction\IntuitivoXlsxParser;
use App\Services\Import\Correction\PlickersCsvParser;
use App\Support\Entitlements\Entitlements;
use App\Support\Limits\Limits;
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
        $this->app->singleton(Limits::class);

        // Which correction-grid formats Lapispro can read is decided in exactly one
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
     * The optional layer that rephrases text Lapispro already wrote.
     *
     * Singletons, so a single request always talks to the same engine — which is
     * what lets a test script a sequence of answers and have them arrive in
     * order. Neither is CONSTRUCTED here: nothing is built until something asks
     * AiTextProviders for one, and on a fresh installation nothing ever does,
     * because no driver is configured (§8).
     *
     * The key is read at RESOLUTION time from config — which reads it from
     * `platform_settings` if the operator stored one there, and otherwise from
     * the environment. Resolution time, not registration time, is what makes
     * that ordering work: `boot()` writes the stored settings over the config
     * long before anything asks the container for a provider. The key is never
     * shared with the frontend, and the provider that holds it never puts it in
     * an exception (§47).
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

        // Registered exactly like the driver above, and that is the point:
        // adding an engine is a class, a binding, and a case in
        // AiTextProviders::driver(). Nothing else in the application learns
        // that Gemini exists (§2 of the AI Core brief).
        $this->app->singleton(GeminiProvider::class, fn (): GeminiProvider => new GeminiProvider(
            baseUrl: (string) config('lapis.ai.gemini.base_url'),
            key: (string) config('lapis.ai.key'),
            model: (string) config('lapis.ai.model'),
            timeout: (int) config('lapis.ai.timeout'),
            maxOutputTokens: (int) config('lapis.ai.max_output_tokens'),
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
        $this->applyPlatformAiSettings();
        $this->describeRelease();
        $this->limitWritingAssistant();
    }

    protected function registerOrganizationMembershipAbilities(): void
    {
        Gate::define('remove', [OrganizationMembershipPolicy::class, 'remove']);
        Gate::define('transferOwnership', [OrganizationMembershipPolicy::class, 'transferOwnership']);
        Gate::define('viewReassignments', [OrganizationMembershipPolicy::class, 'viewReassignments']);
        Gate::define('requestClosure', [OrganizationMembershipPolicy::class, 'requestClosure']);
        Gate::define('cancelClosure', [OrganizationMembershipPolicy::class, 'cancelClosure']);
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
     * Let the platform's stored AI settings override the config, so the operator
     * turns the engine on, changes the model and replaces the credential from
     * the backoffice instead of from a server console.
     *
     * THE SAME ARRANGEMENT THE SMTP BLOCK BELOW HAS USED SINCE IT EXISTED, and
     * deliberately so: one row, read once at boot, written over `config()`, with
     * the `.env` left underneath as the fallback. An installation that
     * configures the engine through the environment and never opens the screen
     * behaves exactly as it did before this existed.
     *
     * WHAT IS STORED WINS; WHAT IS NOT STORED FALLS THROUGH. Every AI column is
     * nullable and a null is «not decided here», so an operator who sets only
     * the model keeps the environment's timeout. `ai_enabled` is the exception
     * and it is a master switch: false forces the driver to null, which is the
     * one setting that overrides a configured `.env` rather than falling back to
     * it — «turn it off» has to mean off.
     *
     * THE CREDENTIAL IS DECRYPTED THROUGH `aiCredential()`, which reports and
     * returns null when the ciphertext no longer matches APP_KEY. An unreadable
     * credential turns the engine off — `AiTextProviders` then answers
     * `credential_missing`, the backoffice says so, and the operator re-enters
     * it. It does not 500 every request in the application.
     *
     * ponytail: one indexed-row read per boot, shared with applyPlatformMailSettings() —
     * cache the row if either ever shows up in a profile.
     */
    protected function applyPlatformAiSettings(): void
    {
        try {
            $settings = PlatformSetting::query()->first();
        } catch (\Throwable) {
            return; // table not there yet
        }

        if ($settings === null || ! $settings->aiConfigured()) {
            return;
        }

        if (! $settings->ai_enabled) {
            // The master switch, and the only override that beats a configured
            // environment rather than deferring to it.
            config(['lapis.ai.driver' => null]);

            return;
        }

        config(array_filter([
            'lapis.ai.driver' => $settings->ai_provider,
            'lapis.ai.model' => $settings->ai_model,
            'lapis.ai.key' => $settings->aiCredential(),
            'lapis.ai.timeout' => $settings->ai_timeout_seconds,
            'lapis.ai.max_output_tokens' => $settings->ai_max_output_tokens,
            'lapis.ai.per_minute' => $settings->ai_per_minute,
            'lapis.ai.organization_per_minute' => $settings->ai_organization_per_minute,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));

        // A stored null is a REAL INSTRUCTION here — «no ceiling of this kind»
        // — so unlike the block above, this one does not filter nulls out. A
        // capability or window the operator never touched simply has no key,
        // and config keeps whatever the environment gave it.
        //
        // Everything is re-checked rather than trusted: this is a JSON column,
        // and its contents are whatever was last written into it.
        foreach ($settings->ai_quotas ?? [] as $capability => $windows) {
            if (! is_string($capability) || ! is_array($windows)) {
                continue;
            }

            foreach ($windows as $window => $value) {
                if (! is_string($window)) {
                    continue;
                }

                config(['lapis.ai.quotas.'.$capability.'.'.$window => is_int($value) ? $value : null]);
            }
        }
    }

    /**
     * Put the running version and commit into `php artisan about`, so the
     * standard Laravel question gets the answer without anyone having to know
     * that `lapis:release-check` exists. Same single source as the check itself.
     */
    protected function describeRelease(): void
    {
        AboutCommand::add('Lapispro', fn (): array => [
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
