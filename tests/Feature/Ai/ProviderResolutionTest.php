<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiTextProviders;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\Providers\ChatCompletionsProvider;
use App\Services\Ai\Providers\FakeAiTextProvider;
use App\Services\Ai\Providers\GeminiProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which engine answers, and — when none does — why.
 *
 * THE ONE PLACE A DRIVER NAME IS COMPARED TO ANYTHING is
 * `AiTextProviders::driver()`. These tests exist so that stays true: everything
 * below drives the resolver through config alone, and nothing anywhere names a
 * vendor in an `if`.
 *
 * «NOT CONFIGURED» IS A FIRST-CLASS ANSWER and most of this file is about the
 * ways an installation can be in it. A fresh install, a half-filled form, a
 * driver nobody implemented, a credential that was never pasted — all of them
 * resolve to unavailable rather than to a request that 401s in front of a
 * teacher.
 */
class ProviderResolutionTest extends TestCase
{
    private function providers(): AiTextProviders
    {
        return app(AiTextProviders::class);
    }

    private function configureGemini(?string $key = 'AIza-FICTICIA-PARA-TESTES-0000', ?string $model = 'gemini-2.5-flash'): void
    {
        config([
            'lapis.ai.driver' => 'gemini',
            'lapis.ai.key' => $key,
            'lapis.ai.model' => $model,
        ]);
    }

    #[Test]
    public function a_fresh_installation_has_no_engine_and_says_so_without_throwing(): void
    {
        config(['lapis.ai.driver' => null]);

        $this->assertFalse($this->providers()->isConfigured());
        $this->assertSame('off', $this->providers()->unavailableReason());
    }

    #[Test]
    public function the_fake_driver_resolves_outside_production(): void
    {
        config(['lapis.ai.driver' => 'fake']);

        $this->assertTrue($this->providers()->isConfigured());
        $this->assertNull($this->providers()->unavailableReason());
        $this->assertInstanceOf(FakeAiTextProvider::class, $this->providers()->make());
    }

    /**
     * A fake that echoed text back on a live installation would look exactly
     * like a feature that works.
     */
    #[Test]
    public function the_fake_driver_never_resolves_in_production(): void
    {
        config(['lapis.ai.driver' => 'fake']);
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->assertFalse($this->providers()->isConfigured());
        $this->assertSame('fake_in_production', $this->providers()->unavailableReason());
    }

    #[Test]
    public function gemini_resolves_when_it_has_a_key_and_a_model(): void
    {
        $this->configureGemini();

        $this->assertTrue($this->providers()->isConfigured());
        $this->assertNull($this->providers()->unavailableReason());

        $provider = $this->providers()->make();

        $this->assertInstanceOf(GeminiProvider::class, $provider);
        $this->assertSame('gemini', $provider->name());
        $this->assertSame('gemini-2.5-flash', $provider->model());
    }

    /**
     * THE STATE THIS INSTALLATION IS ACTUALLY IN. There is no Gemini credential
     * yet, so «provider chosen, key absent» is not an edge case — it is
     * production today, and it has to be a calm, named answer rather than a 500.
     */
    #[Test]
    public function gemini_without_a_credential_is_unavailable_and_names_the_reason(): void
    {
        $this->configureGemini(key: null);

        $this->assertFalse($this->providers()->isConfigured());
        $this->assertSame('credential_missing', $this->providers()->unavailableReason());
        $this->assertSame('gemini', $this->providers()->configuredDriver());
    }

    #[Test]
    public function a_blank_credential_counts_as_absent_not_as_a_credential(): void
    {
        $this->configureGemini(key: '   ');

        $this->assertFalse($this->providers()->isConfigured());
        $this->assertSame('credential_missing', $this->providers()->unavailableReason());
    }

    #[Test]
    public function gemini_without_a_model_is_unavailable_and_names_that_instead(): void
    {
        $this->configureGemini(model: null);

        $this->assertFalse($this->providers()->isConfigured());
        $this->assertSame('model_missing', $this->providers()->unavailableReason());
    }

    #[Test]
    public function an_unknown_driver_is_unavailable_rather_than_an_error(): void
    {
        config(['lapis.ai.driver' => 'nao-existe']);

        $this->assertFalse($this->providers()->isConfigured());
        $this->assertSame('unknown_driver', $this->providers()->unavailableReason());
    }

    #[Test]
    public function asking_for_a_provider_when_none_is_configured_throws_the_unavailable_exception(): void
    {
        config(['lapis.ai.driver' => null]);

        $this->expectException(AiUnavailable::class);

        $this->providers()->make();
    }

    /**
     * The pre-existing driver is untouched by the Gemini work. Adding an engine
     * was meant to be a class, a binding and a case — this asserts the other
     * two did not move.
     */
    #[Test]
    public function the_chat_completions_driver_still_resolves_exactly_as_before(): void
    {
        config([
            'lapis.ai.driver' => 'chat-completions',
            'lapis.ai.endpoint' => 'https://exemplo.invalid/v1/chat/completions',
            'lapis.ai.key' => 'chave-ficticia-de-teste',
            'lapis.ai.model' => 'modelo-de-teste',
        ]);

        $this->assertInstanceOf(ChatCompletionsProvider::class, $this->providers()->make());
    }

    #[Test]
    public function chat_completions_without_an_endpoint_is_unavailable(): void
    {
        config([
            'lapis.ai.driver' => 'chat-completions',
            'lapis.ai.endpoint' => null,
            'lapis.ai.key' => 'chave-ficticia-de-teste',
            'lapis.ai.model' => 'modelo-de-teste',
        ]);

        $this->assertSame('endpoint_missing', $this->providers()->unavailableReason());
    }

    /**
     * Resolved fresh each time, never memoized: the backoffice writes config
     * inside a request, and a cached «no» that outlived its config would be a
     * support ticket nobody could reproduce.
     */
    #[Test]
    public function the_answer_follows_config_within_a_single_request(): void
    {
        config(['lapis.ai.driver' => null]);
        $providers = $this->providers();

        $this->assertFalse($providers->isConfigured());

        config(['lapis.ai.driver' => 'fake']);

        $this->assertTrue($providers->isConfigured());
    }
}
