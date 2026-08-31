<?php

namespace Tests\Feature\Admin;

use App\Models\AiUsageEvent;
use App\Models\AuditEvent;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\Gateway\AiCapabilityProbe;
use App\Services\Ai\Gateway\AiUseCase;
use App\Services\Ai\Providers\FakeAiTextProvider;
use App\Services\Progress\Ai\FollowupSynthesisPrompt;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Admin → Inteligência Artificial.
 *
 * THE CANARY. `FICTITIOUS_KEY` is stored, and then hunted for in every place it
 * could plausibly leak: the Inertia payload, the audit trail, the usage meter,
 * the raw database column. It is an obviously-fake string and no real credential
 * appears anywhere in this file — there is none to appear (§3).
 */
class AdminAiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const FICTITIOUS_KEY = 'AIza-CHAVE-FICTICIA-DE-TESTE-1234ABCD';

    /**
     * What a model that can do the work would answer the capability probe:
     * six labelled sections, in the shape `FollowupSynthesisParser` accepts.
     * Invented prose about the invented record in `AiCapabilityProbe`.
     */
    private const USABLE_SYNTHESIS = <<<'ANSWER'
    SINTESE: Os registos mostram um percurso estável, com um domínio mais consolidado do que os restantes.
    POSITIVOS:
    - Os registos mostram um resultado consolidado em Números e operações.
    ATENCAO:
    - Os registos mostram dois trabalhos de casa por realizar.
    MUDOU:
    - O resultado do período mantém-se face ao período anterior.
    PROXIMO:
    - Pode ser útil verificar com o aluno o que torna Geometria e medida mais difícil.
    CAUTELAS:
    - Um domínio ficou sem resultado no período, pelo que a cobertura é parcial.
    ANSWER;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    // ---------------------------------------------------------------- access

    #[Test]
    public function a_teacher_cannot_open_the_ai_settings(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/ai')->assertForbidden();
    }

    #[Test]
    public function a_teacher_cannot_write_any_of_it(): void
    {
        $teacher = User::factory()->create();

        $this->actingAs($teacher)->put('/admin/ai', ['ai_enabled' => true])->assertForbidden();
        $this->actingAs($teacher)->post('/admin/ai/credential', ['ai_credential' => self::FICTITIOUS_KEY])->assertForbidden();
        $this->actingAs($teacher)->delete('/admin/ai/credential')->assertForbidden();
        $this->actingAs($teacher)->post('/admin/ai/test')->assertForbidden();

        $this->assertFalse(PlatformSetting::current()->aiCredentialConfigured());
    }

    #[Test]
    public function a_platform_admin_opens_it(): void
    {
        $this->actingAs($this->admin())->get('/admin/ai')->assertOk()->assertInertia(
            fn ($page) => $page->component('admin/Ai'),
        );
    }

    // ---------------------------------------------------------------- initial state

    /**
     * The state the brief describes for a fresh installation, asserted end to
     * end: a provider may be chosen, no credential exists, and the AI is off.
     */
    #[Test]
    public function a_fresh_installation_reports_no_credential_and_an_inactive_engine(): void
    {
        config(['lapis.ai.driver' => null, 'lapis.ai.key' => null]);

        $this->actingAs($this->admin())->get('/admin/ai')->assertInertia(
            fn ($page) => $page
                ->where('settings.ai_enabled', false)
                ->where('settings.credential_set', false)
                ->where('settings.credential_hint', null)
                ->where('status.available', false)
                ->where('status.unavailable_reason', 'off'),
        );
    }

    // ---------------------------------------------------------------- configuration

    #[Test]
    public function the_operator_configures_the_engine(): void
    {
        $this->actingAs($this->admin())->put('/admin/ai', [
            'ai_enabled' => true,
            'ai_provider' => 'gemini',
            'ai_model' => 'gemini-2.5-flash',
            'ai_timeout_seconds' => 30,
            'ai_max_output_tokens' => 1024,
            'ai_per_minute' => 5,
            'ai_organization_per_minute' => 25,
        ])->assertRedirect();

        $settings = PlatformSetting::current();

        $this->assertTrue($settings->ai_enabled);
        $this->assertSame('gemini', $settings->ai_provider);
        $this->assertSame('gemini-2.5-flash', $settings->ai_model);
        $this->assertSame(30, $settings->ai_timeout_seconds);
        $this->assertSame(1024, $settings->ai_max_output_tokens);
    }

    #[Test]
    public function an_unknown_provider_is_refused_with_a_field_error(): void
    {
        $this->actingAs($this->admin())->put('/admin/ai', [
            'ai_enabled' => true,
            'ai_provider' => 'nao-existe',
        ])->assertSessionHasErrors('ai_provider');

        $this->assertNull(PlatformSetting::current()->ai_provider);
    }

    /**
     * The stored settings win over the environment, the same way the SMTP block
     * already does — and a null column falls through rather than blanking it.
     */
    #[Test]
    public function the_stored_settings_override_the_config_at_boot(): void
    {
        config(['lapis.ai.driver' => null, 'lapis.ai.model' => null, 'lapis.ai.timeout' => 20]);

        $settings = PlatformSetting::current();
        $settings->update([
            'ai_enabled' => true,
            'ai_provider' => 'gemini',
            'ai_model' => 'gemini-2.5-flash',
            // ai_timeout_seconds left null on purpose.
        ]);
        $settings->storeAiCredential(self::FICTITIOUS_KEY);

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('gemini', config('lapis.ai.driver'));
        $this->assertSame('gemini-2.5-flash', config('lapis.ai.model'));
        $this->assertSame(self::FICTITIOUS_KEY, config('lapis.ai.key'));
        // Not stored, so the environment's value survives.
        $this->assertSame(20, config('lapis.ai.timeout'));
    }

    /** The master switch has to mean off, even over a fully configured .env. */
    #[Test]
    public function disabling_the_ai_beats_a_configured_environment(): void
    {
        config(['lapis.ai.driver' => 'gemini', 'lapis.ai.key' => 'do-ambiente', 'lapis.ai.model' => 'm']);

        PlatformSetting::current()->update(['ai_enabled' => false, 'ai_provider' => 'gemini']);

        (new AppServiceProvider($this->app))->boot();

        $this->assertNull(config('lapis.ai.driver'));
    }

    // ---------------------------------------------------------------- the credential

    #[Test]
    public function the_credential_is_stored_encrypted_and_never_in_the_clear(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/ai/credential', ['ai_credential' => self::FICTITIOUS_KEY])
            ->assertRedirect();

        $settings = PlatformSetting::current();

        $this->assertTrue($settings->aiCredentialConfigured());
        $this->assertNotNull($settings->ai_credential_set_at);

        // The raw column is ciphertext, not the value.
        $raw = DB::table('platform_settings')->value('ai_api_key');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString(self::FICTITIOUS_KEY, $raw);

        // And it still decrypts to what was stored.
        $this->assertSame(self::FICTITIOUS_KEY, $settings->aiCredential());
    }

    /**
     * §4, the flat rule: the key never returns to the frontend, and the payload
     * carries only the three facts about it that may be known outside the
     * process.
     */
    #[Test]
    public function the_credential_never_returns_to_the_frontend(): void
    {
        PlatformSetting::current()->storeAiCredential(self::FICTITIOUS_KEY);

        $response = $this->actingAs($this->admin())->get('/admin/ai');

        $response->assertInertia(fn ($page) => $page
            ->where('settings.credential_set', true)
            // The last four characters, and nothing more of it.
            ->where('settings.credential_hint', 'ABCD')
            ->missing('settings.ai_api_key')
            ->missing('status.effective.key'));

        // Belt and braces over the whole rendered response, not just the props
        // this test thought to name.
        $response->assertDontSee(self::FICTITIOUS_KEY, escape: false);
        $response->assertDontSee('CHAVE-FICTICIA', escape: false);
    }

    /** A key too short to reveal any part of safely shows nothing at all. */
    #[Test]
    public function a_short_credential_gets_no_hint(): void
    {
        PlatformSetting::current()->storeAiCredential('curta-1234567');

        $this->actingAs($this->admin())->get('/admin/ai')->assertInertia(
            fn ($page) => $page->where('settings.credential_set', true)->where('settings.credential_hint', null),
        );
    }

    #[Test]
    public function replacing_the_credential_replaces_it(): void
    {
        PlatformSetting::current()->storeAiCredential(self::FICTITIOUS_KEY);

        $this->actingAs($this->admin())
            ->post('/admin/ai/credential', ['ai_credential' => 'AIza-OUTRA-CHAVE-FICTICIA-WXYZ'])
            ->assertRedirect();

        $this->assertSame('AIza-OUTRA-CHAVE-FICTICIA-WXYZ', PlatformSetting::current()->aiCredential());
    }

    #[Test]
    public function a_half_pasted_credential_is_refused_before_it_replaces_a_working_one(): void
    {
        PlatformSetting::current()->storeAiCredential(self::FICTITIOUS_KEY);

        $this->actingAs($this->admin())
            ->post('/admin/ai/credential', ['ai_credential' => 'AIza-curt'])
            ->assertSessionHasErrors('ai_credential');

        $this->assertSame(self::FICTITIOUS_KEY, PlatformSetting::current()->aiCredential());
    }

    /**
     * A failed submit must not leave the credential sitting in the session.
     * This is what `StoreAiCredentialRequest::$dontFlash` is for.
     */
    #[Test]
    public function a_rejected_credential_is_not_flashed_back_into_the_session(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/ai/credential', ['ai_credential' => 'curta'])
            ->assertSessionHasErrors('ai_credential');

        $this->assertNull(session('_old_input.ai_credential'));
        $this->assertStringNotContainsString('curta', json_encode(session('_old_input') ?? []) ?: '');
    }

    #[Test]
    public function removing_the_credential_makes_the_engine_unavailable_again(): void
    {
        config(['lapis.ai.driver' => null, 'lapis.ai.key' => null, 'lapis.ai.model' => null]);

        $settings = PlatformSetting::current();
        $settings->update(['ai_enabled' => true, 'ai_provider' => 'gemini', 'ai_model' => 'gemini-2.5-flash']);
        $settings->storeAiCredential(self::FICTITIOUS_KEY);

        $this->actingAs($this->admin())->delete('/admin/ai/credential')->assertRedirect();

        $fresh = PlatformSetting::current();
        $this->assertFalse($fresh->aiCredentialConfigured());
        $this->assertNull($fresh->ai_credential_set_at);

        (new AppServiceProvider($this->app))->boot();

        $this->actingAs($this->admin())->get('/admin/ai')->assertInertia(
            fn ($page) => $page
                ->where('status.available', false)
                ->where('status.unavailable_reason', 'credential_missing'),
        );
    }

    /**
     * There is no route that returns the credential. Asserted, not assumed —
     * «substituir credencial» is an action, «mostrar chave» is not a route that
     * exists.
     *
     * The credential endpoint answers 405 (it accepts POST and DELETE, not
     * GET); the invented ones answer 404. Either is fine; what is asserted is
     * that none of them is 200 and none of them contains the key.
     */
    #[Test]
    public function there_is_no_endpoint_that_reveals_the_credential(): void
    {
        PlatformSetting::current()->storeAiCredential(self::FICTITIOUS_KEY);

        foreach (['/admin/ai/credential', '/admin/ai/credential/show', '/admin/ai/key'] as $url) {
            $response = $this->actingAs($this->admin())->get($url);

            $this->assertContains(
                $response->getStatusCode(),
                [404, 405],
                "GET {$url} answered {$response->getStatusCode()} — it must not be a readable route.",
            );
            $this->assertStringNotContainsString(self::FICTITIOUS_KEY, $response->getContent() ?: '');
        }
    }

    // ---------------------------------------------------------------- audit

    #[Test]
    public function configuring_the_engine_is_audited_with_the_fields_that_changed(): void
    {
        $this->actingAs($this->admin())->put('/admin/ai', [
            'ai_enabled' => true,
            'ai_provider' => 'gemini',
            'ai_model' => 'gemini-2.5-flash',
        ])->assertRedirect();

        $event = AuditEvent::withoutGlobalScope('organization')
            ->where('event', 'ai.settings_updated')
            ->sole();

        // A platform act belongs to no tenant.
        $this->assertNull($event->organization_id);
        $this->assertContains('ai_provider', $event->properties['fields']);
        $this->assertSame('gemini-2.5-flash', $event->properties['values']['ai_model']);
    }

    /**
     * The two credential events are told apart, because «when did this key
     * change» is the one question a reader of the trail actually has.
     */
    #[Test]
    public function creating_and_replacing_a_credential_are_different_events_and_neither_carries_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/ai/credential', ['ai_credential' => self::FICTITIOUS_KEY]);
        $this->actingAs($admin)->post('/admin/ai/credential', ['ai_credential' => 'AIza-SEGUNDA-CHAVE-FICTICIA-99']);
        $this->actingAs($admin)->delete('/admin/ai/credential');

        $events = AuditEvent::withoutGlobalScope('organization')->orderBy('id')->pluck('event')->all();

        $this->assertSame(
            ['ai.credential_created', 'ai.credential_replaced', 'ai.credential_removed'],
            $events,
        );

        // NOT THE KEY, NOT ITS LENGTH, NOT A HASH OF IT — asserted over every
        // audit row in the database, serialised whole.
        $trail = json_encode(
            AuditEvent::withoutGlobalScope('organization')->get()->map->getAttributes()->all(),
        );
        $this->assertIsString($trail);
        $this->assertStringNotContainsString(self::FICTITIOUS_KEY, $trail);
        $this->assertStringNotContainsString('CHAVE-FICTICIA', $trail);
        $this->assertStringNotContainsString(hash('sha256', self::FICTITIOUS_KEY), $trail);
    }

    /** Platform rows are invisible to a tenant's own audit log. */
    #[Test]
    public function a_platform_audit_row_never_appears_in_an_organizations_trail(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/ai/credential', ['ai_credential' => self::FICTITIOUS_KEY]);

        app(CurrentOrganization::class)->set($admin->personalOrganization());

        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertSame(1, AuditEvent::withoutGlobalScope('organization')->count());
    }

    // ---------------------------------------------------------------- connection test

    #[Test]
    public function the_connection_test_succeeds_against_the_fake_engine(): void
    {
        config(['lapis.ai.driver' => 'fake', 'lapis.ai.model' => 'fake-model']);
        app(FakeAiTextProvider::class)->willReturn('OK');

        $this->actingAs($this->admin())->post('/admin/ai/test')->assertRedirect();

        $event = AuditEvent::withoutGlobalScope('organization')->where('event', 'ai.connection_tested')->sole();
        $this->assertSame('succeeded', $event->properties['outcome']);
        $this->assertSame('fake', $event->properties['provider']);
    }

    /**
     * The operator's own test belongs to no school, so it counts against no
     * school's quota — while still being measured, because it costs tokens.
     */
    #[Test]
    public function the_connection_test_is_measured_but_billed_to_no_organization(): void
    {
        config(['lapis.ai.driver' => 'fake', 'lapis.ai.model' => 'fake-model']);

        $this->actingAs($this->admin())->post('/admin/ai/test');

        $usage = AiUsageEvent::query()->sole();

        $this->assertNull($usage->organization_id);
        $this->assertSame('platform', $usage->capability);
        $this->assertSame(AiUsageEvent::SUCCEEDED, $usage->status);
    }

    #[Test]
    public function the_connection_test_reports_an_unconfigured_engine_rather_than_failing(): void
    {
        config(['lapis.ai.driver' => null]);

        $this->actingAs($this->admin())->post('/admin/ai/test')->assertRedirect();

        $event = AuditEvent::withoutGlobalScope('organization')->where('event', 'ai.connection_tested')->sole();
        $this->assertSame('failed', $event->properties['outcome']);
        $this->assertSame('provider', $event->properties['error_category']);
    }

    /**
     * This is the ONE screen that is told which failure it was. An operator
     * debugging a key needs «a credencial foi rejeitada»; a teacher must never
     * see it, which is why the category lives apart from the public message.
     */
    #[Test]
    public function a_rejected_credential_is_reported_to_the_operator_as_such(): void
    {
        config(['lapis.ai.driver' => 'fake', 'lapis.ai.model' => 'fake-model']);
        app(FakeAiTextProvider::class)->willFail(AiRequestFailed::refused(401));

        $this->actingAs($this->admin())->post('/admin/ai/test')->assertRedirect();

        $event = AuditEvent::withoutGlobalScope('organization')->where('event', 'ai.connection_tested')->sole();
        $this->assertSame('unauthorized', $event->properties['error_category']);
    }

    // ------------------------------------------------------ teste de capacidade

    /**
     * THE CONNECTION TEST IS UNCHANGED, AND THAT IS THE POINT OF KEEPING IT.
     *
     * It asks for the word «OK» and it still asks for the word «OK» — a fast,
     * cheap proof that a credential, a base URL and a route out of the building
     * all work. What changed is that it is no longer the only test, because it
     * was never a test of whether the configured model can do the product's
     * work: it stayed green throughout an outage in which every síntese de
     * acompanhamento failed.
     */
    #[Test]
    public function the_connection_test_still_asks_only_for_a_word(): void
    {
        config(['lapis.ai.driver' => 'fake', 'lapis.ai.model' => 'fake-model']);
        $fake = app(FakeAiTextProvider::class);
        $fake->willReturn('OK');

        $this->actingAs($this->admin())->post('/admin/ai/test')->assertRedirect();

        $this->assertStringContainsString('OK', $fake->received[0]->instruction);
        $this->assertLessThan(400, mb_strlen($fake->received[0]->instruction));
    }

    /**
     * THE CAPABILITY TEST ASKS FOR THE WORK. The real synthesis instruction, a
     * realistic record, and a six-section answer that the product's own parser
     * accepts — which is the demand that runs out of budget, and therefore the
     * demand worth testing.
     */
    #[Test]
    public function the_capability_test_sends_a_representative_request(): void
    {
        config(['lapis.ai.driver' => 'fake', 'lapis.ai.model' => 'fake-model']);
        $fake = app(FakeAiTextProvider::class);
        $fake->willReturn(self::USABLE_SYNTHESIS);

        $this->actingAs($this->admin())->post('/admin/ai/probe')->assertRedirect();

        $sent = $fake->received[0];

        $this->assertSame(FollowupSynthesisPrompt::text(), $sent->instruction);
        $this->assertGreaterThan(2000, mb_strlen($sent->instruction));
        // Several sections' worth of facts, not a one-line ping.
        $this->assertGreaterThan(10, substr_count($sent->content, "\n"));
        // And it runs under the budget of the feature it stands for.
        $this->assertSame(
            AiUseCase::FollowupSynthesis->minimumOutputTokens(),
            $sent->maxOutputTokens,
        );
    }

    #[Test]
    public function a_usable_capability_test_is_recorded_with_its_dimensions_and_no_text(): void
    {
        config(['lapis.ai.driver' => 'fake', 'lapis.ai.model' => 'fake-model']);
        app(FakeAiTextProvider::class)->willReturn(self::USABLE_SYNTHESIS);

        $this->actingAs($this->admin())->post('/admin/ai/probe')->assertRedirect();

        $event = AuditEvent::withoutGlobalScope('organization')->where('event', 'ai.capability_probed')->sole();

        $this->assertSame('succeeded', $event->properties['outcome']);
        $this->assertSame(6, $event->properties['sections']);
        $this->assertSame(AiCapabilityProbe::VERSION, $event->properties['prompt_version']);

        // The answer itself is nowhere in the trail — only how big it was.
        $properties = json_encode($event->properties, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($properties);
        $this->assertStringNotContainsString('SINTESE', $properties);
        $this->assertStringNotContainsString('Aluno A', $properties);
    }

    /**
     * A MODEL THAT ANSWERS FLUENTLY IN THE WRONG SHAPE FAILS THIS TEST, which
     * is the entire difference between it and «Testar ligação». A 200 is not a
     * pass; a parseable synthesis is.
     */
    #[Test]
    public function a_fluent_answer_in_the_wrong_shape_fails_the_capability_test(): void
    {
        config(['lapis.ai.driver' => 'fake', 'lapis.ai.model' => 'fake-model']);
        app(FakeAiTextProvider::class)->willReturn('O aluno tem tido um percurso muito positivo este ano.');

        $this->actingAs($this->admin())->post('/admin/ai/probe')->assertRedirect();

        $event = AuditEvent::withoutGlobalScope('organization')->where('event', 'ai.capability_probed')->sole();

        $this->assertSame('failed', $event->properties['outcome']);
        $this->assertSame('unparsable_answer', $event->properties['error_category']);
    }

    /**
     * AND A TRUNCATION IS REPORTED AS THE SETTING IT IS. This is the sentence
     * that would have ended the outage on the first afternoon: the credential
     * works, the endpoint works, the budget does not.
     */
    #[Test]
    public function a_truncated_answer_points_the_operator_at_the_output_budget(): void
    {
        config(['lapis.ai.driver' => 'fake', 'lapis.ai.model' => 'fake-model']);
        app(FakeAiTextProvider::class)->willFail(AiRequestFailed::truncatedAnswer('MAX_TOKENS, no text produced'));

        $this->actingAs($this->admin())
            ->post('/admin/ai/probe')
            ->assertRedirect()
            ->assertSessionHas('inertia.flash_data', function (array $flash): bool {
                $this->assertSame('error', $flash['toast']['type']);
                $this->assertStringContainsString('orçamento de resposta', $flash['toast']['message']);
                $this->assertStringContainsString('tokens de saída', $flash['toast']['message']);

                return true;
            });

        $event = AuditEvent::withoutGlobalScope('organization')->where('event', 'ai.capability_probed')->sole();
        $this->assertSame('truncated_answer', $event->properties['error_category']);
    }

    /** The operator's diagnostic, like the connection test, is billed to no school. */
    #[Test]
    public function the_capability_test_is_measured_but_billed_to_no_organization(): void
    {
        config(['lapis.ai.driver' => 'fake', 'lapis.ai.model' => 'fake-model']);
        app(FakeAiTextProvider::class)->willReturn(self::USABLE_SYNTHESIS);

        $this->actingAs($this->admin())->post('/admin/ai/probe');

        $usage = AiUsageEvent::query()->sole();

        $this->assertNull($usage->organization_id);
        $this->assertSame('platform', $usage->capability);
        $this->assertSame(AiUseCase::AdminCapabilityProbe, $usage->use_case);
    }

    /** The two diagnostics are told apart in the meter, because they cost differently. */
    #[Test]
    public function the_two_diagnostics_are_distinguishable_in_the_meter(): void
    {
        config(['lapis.ai.driver' => 'fake', 'lapis.ai.model' => 'fake-model']);
        app(FakeAiTextProvider::class)
            ->willReturn('OK')
            ->willReturn(self::USABLE_SYNTHESIS);

        $this->actingAs($this->admin())->post('/admin/ai/test');
        $this->actingAs($this->admin())->post('/admin/ai/probe');

        $useCases = AiUsageEvent::query()->orderBy('id')->pluck('use_case')->all();

        $this->assertSame(
            [AiUseCase::AdminConnectionTest, AiUseCase::AdminCapabilityProbe],
            $useCases,
        );
    }

    /** Only the operator runs it. A teacher's session must not reach the route at all. */
    #[Test]
    public function the_capability_test_is_out_of_reach_of_a_teacher(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/admin/ai/probe')
            ->assertForbidden();

        $this->assertSame(0, AiUsageEvent::query()->count());
    }
}
