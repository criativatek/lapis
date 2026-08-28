<?php

namespace App\Services\Ai;

use App\Services\Ai\Providers\ChatCompletionsProvider;
use App\Services\Ai\Providers\FakeAiTextProvider;
use App\Services\Ai\Providers\GeminiProvider;
use Illuminate\Contracts\Foundation\Application;

/**
 * Whether there is an engine at all, and if so which one.
 *
 * «NOT CONFIGURED» IS A FIRST-CLASS ANSWER, not an exception waiting to happen.
 * Lapispro ships with no vendor chosen, so the ordinary state of this class on a
 * fresh installation is `isConfigured() === false` — and every feature that uses
 * an engine has to keep working in exactly that state (§8). Screens ask this
 * before they offer anything, so nobody is shown a button that can only fail
 * (§41).
 *
 * A HALF-CONFIGURED INSTALLATION IS NOT CONFIGURED. An endpoint without a key,
 * a key without a model, a driver nobody implemented: all of them resolve to
 * unavailable rather than to a request that will 401 in front of a teacher.
 * `unavailableReason()` says WHICH of those it is, so the backoffice can tell an
 * operator what is missing instead of «não configurada» (§4 of the AI Core
 * brief).
 *
 * ONE MATCH, AND IT IS THE ONLY PLACE A DRIVER NAME IS COMPARED TO ANYTHING.
 * There is no `if ($provider === 'gemini')` anywhere else in the application; a
 * new engine is a case here, a binding in AppServiceProvider and a class in
 * Providers/, and nothing above this line changes (§2).
 *
 * Resolved fresh each time rather than memoized: config can be overridden inside
 * a request (tests, and the platform backoffice pattern already used for SMTP
 * and now for the AI settings themselves), and a cached «no» that outlived its
 * config would be a support ticket nobody could reproduce.
 */
class AiTextProviders
{
    public function __construct(protected Application $app) {}

    public function isConfigured(): bool
    {
        return $this->driver() !== null;
    }

    /**
     * The configured driver name, whether or not it resolves to anything —
     * what the operator chose, as opposed to what works.
     */
    public function configuredDriver(): ?string
    {
        $configured = config('lapis.ai.driver');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
    }

    /**
     * Why there is no engine, as a slug a screen turns into a sentence — or
     * null when there is one.
     *
     * `off` is not a fault, and the backoffice says so differently: it is the
     * intended state of a fresh installation, not something an operator broke.
     */
    public function unavailableReason(): ?string
    {
        $configured = $this->configuredDriver();

        if ($configured === null) {
            return 'off';
        }

        return match ($configured) {
            'gemini' => $this->missingSetting(['key', 'model']),
            'chat-completions' => $this->missingSetting(['endpoint', 'key', 'model']),
            'fake' => $this->app->isProduction() ? 'fake_in_production' : null,
            default => 'unknown_driver',
        };
    }

    /**
     * @throws AiUnavailable when nothing is configured. Callers that can avoid
     *                       this ask isConfigured() first; the throw is the
     *                       backstop for a request that arrived anyway.
     */
    public function make(): AiTextProvider
    {
        $driver = $this->driver();

        if ($driver === null) {
            throw AiUnavailable::notConfigured();
        }

        // The drivers are registered as singletons in AppServiceProvider, so
        // two calls in one request see the same engine — which is what lets a
        // test script a sequence of answers and have them arrive in order.
        return $this->app->make($driver);
    }

    /**
     * The container binding key for the configured driver, or null.
     *
     * The bindings themselves are registered in AppServiceProvider — this only
     * decides which one applies, so that «is it available?» and «build it» can
     * never disagree.
     */
    protected function driver(): ?string
    {
        if ($this->unavailableReason() !== null) {
            return null;
        }

        return match ($this->configuredDriver()) {
            'gemini' => GeminiProvider::class,
            'chat-completions' => ChatCompletionsProvider::class,
            // Never in production — already refused by unavailableReason()
            // above; a fake that echoes text back on a live installation looks
            // exactly like a feature that works.
            'fake' => FakeAiTextProvider::class,
            default => null,
        };
    }

    /**
     * The first required setting this driver has not been given, as a slug —
     * `credential_missing` for the key, `<setting>_missing` for the rest.
     *
     * The key gets its own wording because it is the one an operator is
     * expected to be missing (there is no credential on a fresh installation)
     * and the one the backoffice offers a button for.
     *
     * @param  list<string>  $settings
     */
    protected function missingSetting(array $settings): ?string
    {
        foreach ($settings as $setting) {
            $value = config('lapis.ai.'.$setting);

            if (! is_string($value) || trim($value) === '') {
                return $setting === 'key' ? 'credential_missing' : $setting.'_missing';
            }
        }

        return null;
    }
}
