<?php

namespace App\Services\Ai;

use App\Services\Ai\Providers\ChatCompletionsProvider;
use App\Services\Ai\Providers\FakeAiTextProvider;
use Illuminate\Contracts\Foundation\Application;

/**
 * Whether there is an engine at all, and if so which one.
 *
 * «NOT CONFIGURED» IS A FIRST-CLASS ANSWER, not an exception waiting to happen.
 * Lapispro ships with no vendor chosen, so the ordinary state of this class on a
 * fresh installation is `isConfigured() === false` — and the whole Relatórios
 * module has to keep working in exactly that state (§8). Screens ask this before
 * they offer anything, so nobody is shown a button that can only fail (§41).
 *
 * A HALF-CONFIGURED INSTALLATION IS NOT CONFIGURED. An endpoint without a key,
 * or a key without a model, resolves to unavailable rather than to a request
 * that will 401 in front of a teacher.
 *
 * Resolved fresh each time rather than memoized: config can be overridden inside
 * a request (tests, and the platform backoffice pattern already used for SMTP),
 * and a cached «no» that outlived its config would be a support ticket nobody
 * could reproduce.
 */
class AiTextProviders
{
    public function __construct(protected Application $app) {}

    public function isConfigured(): bool
    {
        return $this->driver() !== null;
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

        // Both drivers are registered as singletons in AppServiceProvider, so
        // two rewrites in one request see the same engine — which is what lets a
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
        $configured = config('lapis.ai.driver');

        return match ($configured) {
            'chat-completions' => $this->chatCompletionsIsComplete() ? ChatCompletionsProvider::class : null,
            // Never in production: a fake that echoes text back on a live
            // installation looks exactly like a feature that works.
            'fake' => $this->app->isProduction() ? null : FakeAiTextProvider::class,
            default => null,
        };
    }

    protected function chatCompletionsIsComplete(): bool
    {
        foreach (['endpoint', 'key', 'model'] as $setting) {
            $value = config('lapis.ai.'.$setting);

            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }
}
