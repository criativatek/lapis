<?php

namespace Tests\Feature\Ai;

use App\Models\AiUsageEvent;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\User;
use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\Gateway\AiAsk;
use App\Services\Ai\Gateway\AiCapability;
use App\Services\Ai\Gateway\AiGateway;
use App\Services\Ai\Gateway\AiUseCase;
use App\Services\Ai\Providers\FakeAiTextProvider;
use App\Services\Diagnostics\Ai\AiCapabilityProbe;
use App\Services\Progress\Ai\FollowupSynthesisParser;
use App\Services\Progress\Ai\FollowupSynthesisPrompt;
use App\Support\Entitlements\Entitlements;
use App\Support\Privacy\AiPayloadSanitizer;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE SÍNTESE DE ACOMPANHAMENTO, END TO END, THROUGH THE GATEWAY THAT WAS
 * STARVING IT.
 *
 * `AiOutputBudgetTest` checks the arithmetic in isolation. This checks that the
 * arithmetic actually reaches the wire: that an ask for a followup synthesis
 * arrives at the provider carrying the raised budget, that the six sections
 * survive the round trip, and that the two failures which used to be
 * indistinguishable — the engine running out of room, and this application
 * failing to read what the engine said — are recorded in `ai_usage_events` as
 * different things.
 *
 * NOTHING HERE CALLS A REAL ENGINE, and nothing here is about a real student.
 * The fake driver answers, and the record it answers about is the synthetic one
 * checked into `AiCapabilityProbe`.
 */
class AiFollowupSynthesisFailureTest extends TestCase
{
    use RefreshDatabase;

    /** A complete, well-formed answer in the shape the prompt asks for. */
    private const SIX_SECTIONS = <<<'ANSWER'
    SINTESE: Os registos deste período mostram um percurso estável, com um domínio claramente mais consolidado do que os restantes. A evidência disponível é suficiente para uma leitura de conjunto.
    POSITIVOS:
    - Os registos mostram um resultado consolidado em Números e operações.
    - A participação aparece registada seis vezes ao longo do período.
    ATENCAO:
    - Os registos mostram dois trabalhos de casa por realizar.
    - Geometria e medida está abaixo dos restantes domínios.
    MUDOU:
    - O resultado do período mantém-se face ao período anterior.
    PROXIMO:
    - Pode ser útil verificar com o aluno o que torna Geometria e medida mais difícil.
    CAUTELAS:
    - Um domínio ficou sem resultado no período, pelo que a cobertura é parcial.
    ANSWER;

    private function gateway(): AiGateway
    {
        return app(AiGateway::class);
    }

    private function fake(): FakeAiTextProvider
    {
        config(['lapis.ai.driver' => 'fake', 'lapis.ai.model' => 'fake-model']);

        return app(FakeAiTextProvider::class);
    }

    private function teacher(): User
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();

        app(CurrentOrganization::class)->set($organization);
        $this->grant($organization, AiCapability::Followup);

        return $user;
    }

    private function grant(Organization $organization, AiCapability $capability): void
    {
        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'module_id' => Module::where('key', $capability->value)->firstOrFail()->getKey(),
            'enabled' => true,
        ]);

        app(Entitlements::class)->flush();
    }

    private function ask(AiUseCase $useCase = AiUseCase::FollowupSynthesis): AiAsk
    {
        return new AiAsk(
            useCase: $useCase,
            instruction: 'Escreve seis secções.',
            content: app(AiPayloadSanitizer::class)->sanitise(AiCapabilityProbe::content()),
            promptVersion: 'teste/1',
        );
    }

    // -------------------------------------------------------------- the budget

    /**
     * THE RAISE REACHES THE WIRE. This is the fix, observed from outside: the
     * provider is handed a budget larger than the installation default, and it
     * is handed it because the USE CASE asked, not because a caller did.
     */
    #[Test]
    public function a_followup_synthesis_reaches_the_provider_with_its_raised_budget(): void
    {
        config(['lapis.ai.max_output_tokens' => 2048, 'lapis.ai.max_output_tokens_ceiling' => 8192]);

        $fake = $this->fake();
        $fake->willReturn(self::SIX_SECTIONS);

        $this->gateway()->ask($this->ask(), $this->teacher());

        $this->assertSame(3072, $fake->received[0]->maxOutputTokens);
    }

    /** Everything else still travels on the installation default. */
    #[Test]
    public function a_rewrite_reaches_the_provider_with_the_installation_default(): void
    {
        config(['lapis.ai.max_output_tokens' => 2048, 'lapis.ai.max_output_tokens_ceiling' => 8192]);

        $fake = $this->fake();
        $fake->willReturn('Um parágrafo melhor.');

        $user = $this->teacher();
        $this->grant($user->personalOrganization(), AiCapability::Reports);

        $this->gateway()->ask($this->ask(AiUseCase::ReportSectionRewrite), $user);

        $this->assertSame(2048, $fake->received[0]->maxOutputTokens);
    }

    /**
     * AND THE HARD CEILING STILL WINS AT THE WIRE, not merely in a helper — but
     * a ceiling below a DECLARED MINIMUM refuses instead of sending a number
     * that cannot work.
     *
     * This test used to assert that a 2560 ceiling clamped the synthesis's 3072
     * and sent it. The docblock claimed the feature would then «fail honestly»,
     * and it did fail — as `truncated_answer`, which points at the model. The
     * cause was a setting in the backoffice. Nothing in the product connected
     * the two, so the honest failure was honest about the wrong thing.
     */
    #[Test]
    public function a_ceiling_below_the_declared_minimum_refuses_before_the_request_is_made(): void
    {
        config(['lapis.ai.max_output_tokens' => 2048, 'lapis.ai.max_output_tokens_ceiling' => 2560]);

        $fake = $this->fake();
        $fake->willReturn(self::SIX_SECTIONS);

        try {
            $this->gateway()->ask($this->ask(), $this->teacher());
            $this->fail('A ceiling below the synthesis minimum must refuse.');
        } catch (AiRequestFailed $failure) {
            $this->assertSame('misconfigured_budget', $failure->category());
            $this->assertFalse($failure->isRetryable());
        }

        $this->assertSame([], $fake->received, 'Nothing may reach the engine when the budget is contradictory.');
    }

    /**
     * IT IS ON THE METER, with the category that names the cause. This is how
     * an operator finds a misconfiguration they cannot see from the failure a
     * teacher reports.
     */
    #[Test]
    public function a_contradictory_budget_is_metered_as_its_own_kind_of_failure(): void
    {
        config(['lapis.ai.max_output_tokens' => 2048, 'lapis.ai.max_output_tokens_ceiling' => 2560]);

        $this->fake()->willReturn(self::SIX_SECTIONS);

        try {
            $this->gateway()->ask($this->ask(), $this->teacher());
        } catch (AiRequestFailed) {
            // recorded below
        }

        $event = AiUsageEvent::withoutGlobalScope('organization')->latest('id')->firstOrFail();

        $this->assertSame('misconfigured_budget', $event->error_category);
    }

    /**
     * AND THE CLAMP IS UNTOUCHED for a use case that declared no minimum: the
     * hard ceiling still bounds what it may spend.
     */
    #[Test]
    public function the_hard_ceiling_still_clamps_a_use_case_that_declared_no_minimum(): void
    {
        config(['lapis.ai.max_output_tokens' => 2048, 'lapis.ai.max_output_tokens_ceiling' => 1024]);

        $fake = $this->fake();
        $fake->willReturn('Um parágrafo melhor.');

        $user = $this->teacher();
        $this->grant($user->personalOrganization(), AiCapability::Reports);

        $this->gateway()->ask($this->ask(AiUseCase::ReportSectionRewrite), $user);

        $this->assertSame(1024, $fake->received[0]->maxOutputTokens);
    }

    // ------------------------------------------------- the three failure modes

    /**
     * (A) THE PROVIDER RAN OUT OF ROOM. Recorded as `truncated_answer`, which
     * is the row an operator can act on, and never offered a retry.
     */
    #[Test]
    public function a_truncated_answer_is_metered_as_its_own_kind_of_failure(): void
    {
        $fake = $this->fake();
        $fake->willFail(AiRequestFailed::truncatedAnswer('MAX_TOKENS, no text produced'));

        try {
            $this->gateway()->ask($this->ask(), $this->teacher());
            $this->fail('The gateway accepted a truncated answer.');
        } catch (AiRequestFailed $exception) {
            $this->assertSame('truncated_answer', $exception->category());
            $this->assertFalse($exception->isRetryable());
        }

        $event = AiUsageEvent::query()->where('status', AiUsageEvent::FAILED)->sole();

        $this->assertSame('truncated_answer', $event->error_category);
        $this->assertSame(AiUseCase::FollowupSynthesis, $event->use_case);
    }

    /**
     * (B) THE PROVIDER ANSWERED IN FULL AND THIS APPLICATION COULD NOT READ IT.
     * A different failure with a different cause, and it must not be filed
     * under the provider's name.
     */
    #[Test]
    public function an_answer_the_parser_rejects_is_not_recorded_as_a_provider_failure(): void
    {
        $fake = $this->fake();
        // Fluent, complete, well-formed prose in entirely the wrong shape: no
        // labels, so no sections, so nothing the panel could render.
        $fake->willReturn('O aluno tem tido um percurso positivo e deve continuar assim.');

        $answer = $this->gateway()->ask($this->ask(), $this->teacher());

        $this->assertNull(FollowupSynthesisParser::parse($answer->text));

        // The GATEWAY call succeeded — the engine did its job, and the meter
        // records a successful request, because one was made and paid for.
        $event = AiUsageEvent::query()->sole();
        $this->assertSame(AiUsageEvent::SUCCEEDED, $event->status);
        $this->assertNull($event->error_category);

        // The failure the CALLER then raises is the parser's, and it says so.
        $failure = AiRequestFailed::unparsableAnswer('no synthesis could be parsed from the answer');
        $this->assertSame('unparsable_answer', $failure->category());
        $this->assertNotSame('truncated_answer', $failure->category());
    }

    /** (C) A COMPLETE ANSWER SURVIVES THE ROUND TRIP, all six sections intact. */
    #[Test]
    public function a_complete_six_section_answer_is_parsed_whole(): void
    {
        $fake = $this->fake();
        $fake->willReturn(self::SIX_SECTIONS);

        $answer = $this->gateway()->ask($this->ask(), $this->teacher());
        $synthesis = FollowupSynthesisParser::parse($answer->text);

        $this->assertNotNull($synthesis);
        $this->assertNotSame('', $synthesis->summary);
        $this->assertCount(2, $synthesis->positiveSignals);
        $this->assertCount(2, $synthesis->attentionSignals);
        $this->assertCount(1, $synthesis->whatChanged);
        $this->assertCount(1, $synthesis->nextSteps);
        $this->assertCount(1, $synthesis->cautions);

        $this->assertSame(6, AiCapabilityProbe::sectionsFound($answer->text));
    }

    /**
     * A PARTIAL ANSWER IS NOT A SYNTHESIS. The parser's existing refusal, kept
     * under the new arrangement: half a reading of a child under headings that
     * promise a whole one is the failure this feature was designed against.
     */
    #[Test]
    public function a_partial_answer_is_still_refused(): void
    {
        $cut = 'SINTESE: Os registos deste período mostram um percurso estável, com um domínio';

        $this->assertNull(FollowupSynthesisParser::parse($cut));
        $this->assertSame(0, AiCapabilityProbe::sectionsFound($cut));
    }

    // ------------------------------------------------------------- the meter

    /**
     * NEITHER THE PROMPT NOR THE ANSWER IS EVER WRITTEN DOWN — not in the usage
     * row, not in a failure's technical message. The row records dimensions;
     * the reading itself lives only on the screen it was drawn for.
     */
    #[Test]
    public function no_prompt_or_answer_text_reaches_the_usage_row(): void
    {
        $fake = $this->fake();
        $fake->willReturn(self::SIX_SECTIONS);

        $this->gateway()->ask($this->ask(), $this->teacher());

        $row = json_encode(AiUsageEvent::query()->sole()->toArray(), JSON_UNESCAPED_UNICODE);

        $this->assertIsString($row);
        $this->assertStringNotContainsString('Números e operações', $row);
        $this->assertStringNotContainsString('trabalhos de casa', $row);
        $this->assertStringNotContainsString('Aluno A', $row);
        $this->assertStringNotContainsString('SINTESE', $row);
    }

    /**
     * THE SYNTHETIC PROBE RECORD HAS NOTHING IN IT TO PROTECT. It is the one
     * payload in the product that leaves the building from a screen with no
     * tenant behind it, so it is checked for the things a real record would
     * carry and this one must not.
     */
    #[Test]
    public function the_capability_probe_carries_no_personal_data(): void
    {
        $content = AiCapabilityProbe::content();

        $this->assertStringNotContainsString('@', $content, 'No email may appear in the probe.');
        $this->assertMatchesRegularExpression('/^Aluno: Aluno A$/m', $content);
        $this->assertSame(0, preg_match('/\b\d{9}\b/', $content), 'No nine-digit identifier may appear.');

        // It goes through the sanitiser like everything else, and comes out
        // unchanged — because there was nothing in it to replace.
        $payload = app(AiPayloadSanitizer::class)->sanitise($content);

        $this->assertFalse($payload->wasPseudonymised());
        $this->assertSame($content, $payload->text);
    }

    /**
     * THE PROBE IS THE OPERATOR'S TRAFFIC, NOT A SCHOOL'S. Same rule as the
     * connection test: no organization on the row, no quota consumed, because
     * billing a school for an operator's diagnostic would be wrong in both
     * directions.
     */
    #[Test]
    public function the_capability_probe_belongs_to_no_organization(): void
    {
        $this->assertNull(AiUseCase::AdminCapabilityProbe->capability());
        $this->assertSame('platform', AiUseCase::AdminCapabilityProbe->capabilityColumn());
    }

    /**
     * THE PROBE IS A REAL EXERCISE OF THE FEATURE, and would have caught the
     * bug that «Testar ligação» slept through: it sends the actual synthesis
     * instruction, not a shortened stand-in, so the prompt's own length is part
     * of what is being measured.
     */
    #[Test]
    public function the_capability_probe_sends_the_features_own_instruction(): void
    {
        $instruction = AiCapabilityProbe::instruction();

        $this->assertSame(FollowupSynthesisPrompt::text(), $instruction);
        $this->assertStringContainsString('SINTESE', $instruction);
        $this->assertStringContainsString('CAUTELAS', $instruction);
        $this->assertGreaterThan(2000, mb_strlen($instruction), 'A short prompt would not exercise the budget.');
    }

    /** And its verdict is the product's own parser, not a looser one. */
    #[Test]
    public function the_capability_probe_accepts_only_what_the_product_would_use(): void
    {
        $this->assertTrue(AiCapabilityProbe::isUsable(self::SIX_SECTIONS));
        $this->assertFalse(AiCapabilityProbe::isUsable('OK'));
        $this->assertFalse(AiCapabilityProbe::isUsable('Uma resposta fluente mas sem rótulos nenhuns.'));

        // The parser's own rule: a reading with nothing positive in it is
        // refused, and the probe inherits that rather than relaxing it.
        $this->assertFalse(AiCapabilityProbe::isUsable(
            "SINTESE: Uma leitura.\nATENCAO:\n- Um problema."
        ));
    }
}
