<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiTextRequest;
use App\Services\Ai\Gateway\AiUseCase;
use App\Services\Ai\Providers\GeminiThinking;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE ARITHMETIC OF THE OUTPUT CEILING, AND THE FAILURE THAT HAPPENS WHEN IT IS
 * WRONG.
 *
 * These are the two halves of one bug. A síntese de acompanhamento asks for six
 * labelled sections under a two-page instruction, and it was being sent with a
 * 2048-token budget on a Gemini 2.5 model that spends that budget thinking
 * BEFORE it writes anything. The answer came back as `finishReason: MAX_TOKENS`
 * with no text in it, every single time, on a credential and an endpoint that
 * were both perfectly healthy — and the interface offered a retry, which failed
 * identically and billed the school for the demonstration.
 *
 * So there are three fixes and this file guards all three: the thinking is
 * turned off where the vendor allows it, the budget is raised for the one use
 * case whose answer does not fit in the default, and a truncation is reported
 * as the deterministic thing it is rather than as one more unusable answer.
 *
 * THE CLAMP IS THE PART WORTH TESTING HARDEST. «A use case may ask for more» is
 * only safe while there is something above it saying how much more.
 */
class AiOutputBudgetTest extends TestCase
{
    /**
     * `AiGateway::outputBudgetFor()` is protected, and deliberately so — this
     * reproduces its arithmetic through the public surface it was written
     * against rather than reaching into it, so the test breaks if the RULE
     * changes and not merely if the method is renamed.
     */
    private function resolve(AiUseCase $useCase, int $default, int $ceiling): int
    {
        return min($ceiling, max($default, $useCase->minimumOutputTokens() ?? 0));
    }

    #[Test]
    public function the_synthesis_asks_for_more_room_than_the_installation_default(): void
    {
        $minimum = AiUseCase::FollowupSynthesis->minimumOutputTokens();

        $this->assertNotNull($minimum, 'The synthesis must declare the budget its six sections need.');
        $this->assertGreaterThan(
            (int) config('lapis.ai.max_output_tokens'),
            $minimum,
            'A minimum at or below the default would not have fixed anything.',
        );
    }

    /**
     * EVERY OTHER USE CASE STAYS ON THE DEFAULT. «Não aumentar globalmente sem
     * necessidade» — a ceiling is raised where it is short, not everywhere,
     * because this is the one setting in the AI config that is directly a bill.
     */
    #[Test]
    public function nothing_but_the_synthesis_and_its_probe_raises_the_budget(): void
    {
        foreach (AiUseCase::cases() as $useCase) {
            if (in_array($useCase, [AiUseCase::FollowupSynthesis, AiUseCase::AdminCapabilityProbe], true)) {
                continue;
            }

            $this->assertNull(
                $useCase->minimumOutputTokens(),
                "{$useCase->value} raised the output budget without a stated reason.",
            );
        }
    }

    /**
     * THE PROBE RUNS UNDER THE BUDGET OF THE THING IT STANDS FOR. A capability
     * test with more room than the feature would pass on an installation where
     * the feature fails, which is worse than having no test at all.
     */
    #[Test]
    public function the_capability_probe_carries_the_synthesis_budget(): void
    {
        $this->assertSame(
            AiUseCase::FollowupSynthesis->minimumOutputTokens(),
            AiUseCase::AdminCapabilityProbe->minimumOutputTokens(),
        );
    }

    #[Test]
    public function a_use_case_with_no_minimum_gets_the_installation_default(): void
    {
        $this->assertSame(2048, $this->resolve(AiUseCase::ReportSectionRewrite, default: 2048, ceiling: 8192));
    }

    #[Test]
    public function a_use_case_that_needs_more_gets_more(): void
    {
        $this->assertSame(3072, $this->resolve(AiUseCase::FollowupSynthesis, default: 2048, ceiling: 8192));
    }

    /**
     * THE HARD CEILING WINS, ALWAYS. Without this the use-case minimum would be
     * a number any future enum case could set to anything, which is the same
     * objection the codebase has always made to a caller-supplied ceiling.
     */
    #[Test]
    public function the_hard_ceiling_bounds_a_use_case_that_asks_for_too_much(): void
    {
        $this->assertSame(1000, $this->resolve(AiUseCase::FollowupSynthesis, default: 512, ceiling: 1000));
    }

    /**
     * AN OPERATOR WHO LOWERS THE HARD CEILING BELOW THE DEFAULT MEANS IT. The
     * hard ceiling is the most a single call may cost; a default that quietly
     * overrode it would make the hard ceiling the soft one.
     */
    #[Test]
    public function a_ceiling_below_the_default_is_still_the_ceiling(): void
    {
        $this->assertSame(256, $this->resolve(AiUseCase::ReportSectionRewrite, default: 2048, ceiling: 256));
    }

    /** The installation ships with room above the default for the raise to land in. */
    #[Test]
    public function the_configured_ceiling_leaves_room_for_the_synthesis(): void
    {
        $this->assertGreaterThanOrEqual(
            (int) AiUseCase::FollowupSynthesis->minimumOutputTokens(),
            (int) config('lapis.ai.max_output_tokens_ceiling'),
            'The shipped ceiling would clamp the synthesis back below what it needs.',
        );
    }

    /**
     * THE THINKING TABLE, AS A TABLE. Each row is a vendor fact, and the last
     * one is the safety property: silence for anything unrecognised.
     */
    #[Test]
    public function the_thinking_budget_matches_what_each_model_accepts(): void
    {
        $expected = [
            'gemini-2.5-flash' => 0,
            'gemini-2.5-flash-lite' => 0,
            'gemini-2.5-flash-preview-09-2025' => 0,
            'models/gemini-2.5-flash' => 0,
            'GEMINI-2.5-FLASH' => 0,
            'gemini-2.5-pro' => GeminiThinking::PRO_MINIMUM,
            'gemini-2.5-pro-002' => GeminiThinking::PRO_MINIMUM,
            'gemini-2.0-flash' => null,
            'gemini-1.5-pro' => null,
            'gpt-4o-mini' => null,
            '' => null,
            'nome-inventado' => null,
        ];

        foreach ($expected as $model => $budget) {
            $this->assertSame($budget, GeminiThinking::budgetFor((string) $model), "model: {$model}");
        }
    }

    /**
     * ZERO IS NEVER SENT TO A MODEL THAT REJECTS IT. The whole point of the
     * table is that «turn thinking off» is not a request every model accepts,
     * and a driver that sent it everywhere would trade a truncation for a 400.
     */
    #[Test]
    public function a_model_that_cannot_disable_thinking_never_receives_zero(): void
    {
        $this->assertGreaterThan(0, (int) GeminiThinking::budgetFor('gemini-2.5-pro'));
    }

    /**
     * A REQUEST BUILT WITHOUT A BUDGET STILL HAS NONE. `AiAsk` — the object
     * callers actually construct — has no field for it at all, and this is the
     * layer below: a null here is what every path that does not need more room
     * sends, and it means «take the installation default».
     */
    #[Test]
    public function a_request_carries_no_budget_unless_the_gateway_resolved_one(): void
    {
        $request = new AiTextRequest(instruction: 'i', content: 'c');

        $this->assertNull($request->maxOutputTokens);
    }

    /**
     * A TRUNCATION IS DETERMINISTIC AND A TIMEOUT IS NOT, and the interface
     * needs to be able to tell them apart before it offers anybody a button.
     */
    #[Test]
    public function only_the_failures_a_second_press_could_fix_are_retryable(): void
    {
        $this->assertFalse(AiRequestFailed::truncatedAnswer('MAX_TOKENS, no text produced')->isRetryable());
        $this->assertFalse(AiRequestFailed::unparsableAnswer('no synthesis could be parsed')->isRetryable());
        $this->assertFalse(AiRequestFailed::refused(401)->isRetryable());
        $this->assertFalse(AiRequestFailed::unusableAnswer('the prompt was blocked (SAFETY)')->isRetryable());

        $this->assertTrue(AiRequestFailed::timedOut(20)->isRetryable());
        $this->assertTrue(AiRequestFailed::refused(429)->isRetryable());
        $this->assertTrue(AiRequestFailed::refused(503)->isRetryable());
        $this->assertTrue(AiRequestFailed::unreachable()->isRetryable());
    }

    /** Every category a constructor can produce is one the closed list knows about. */
    #[Test]
    public function every_category_is_declared(): void
    {
        $failures = [
            AiRequestFailed::timedOut(20),
            AiRequestFailed::refused(401),
            AiRequestFailed::refused(429),
            AiRequestFailed::refused(418),
            AiRequestFailed::refused(503),
            AiRequestFailed::unusableAnswer('why'),
            AiRequestFailed::truncatedAnswer('why'),
            AiRequestFailed::unparsableAnswer('why'),
            AiRequestFailed::unreachable(),
        ];

        foreach ($failures as $failure) {
            $this->assertContains($failure->category(), AiRequestFailed::CATEGORIES);
            $this->assertLessThanOrEqual(
                32,
                strlen($failure->category()),
                'ai_usage_events.error_category is a string(32).',
            );
        }

        foreach (AiRequestFailed::DETERMINISTIC_CATEGORIES as $category) {
            $this->assertContains($category, AiRequestFailed::CATEGORIES);
        }
    }

    /**
     * A PROVIDER FAILURE AND A PARSER FAILURE ARE DIFFERENT THINGS.
     *
     * Both used to be `unusable_answer`, which left the meter unable to answer
     * the first question anybody debugging this feature asks: did the engine
     * fail, or did we fail to read what it said? They are now told apart in the
     * one column that records it.
     */
    #[Test]
    public function a_parser_failure_is_never_confused_with_a_provider_failure(): void
    {
        $this->assertSame('truncated_answer', AiRequestFailed::truncatedAnswer('MAX_TOKENS')->category());
        $this->assertSame('unparsable_answer', AiRequestFailed::unparsableAnswer('no sections')->category());
        $this->assertSame('unusable_answer', AiRequestFailed::unusableAnswer('the prompt was blocked')->category());

        $this->assertNotSame(
            AiRequestFailed::truncatedAnswer('a')->category(),
            AiRequestFailed::unparsableAnswer('a')->category(),
        );
    }

    /**
     * THE PUBLIC SENTENCE NEVER PROMISES SOMETHING THE SCREEN CANNOT KEEP.
     *
     * «O texto atual foi preservado» is true beside a paragraph the teacher
     * typed and false under a synthesis of a student's Evolução, where there
     * was never any text to preserve. It now lives in the one controller that
     * can honestly say it — see `ReportRewriteController::preservingText()`.
     */
    #[Test]
    public function no_shared_failure_message_claims_a_previous_text_was_kept(): void
    {
        $failures = [
            AiRequestFailed::timedOut(20),
            AiRequestFailed::refused(401),
            AiRequestFailed::refused(429),
            AiRequestFailed::refused(500),
            AiRequestFailed::unusableAnswer('why'),
            AiRequestFailed::truncatedAnswer('why'),
            AiRequestFailed::unparsableAnswer('why'),
            AiRequestFailed::unreachable(),
        ];

        foreach ($failures as $failure) {
            $this->assertStringNotContainsStringIgnoringCase(
                'preservado',
                $failure->publicMessage(),
                'A shared message promised a text was kept, on screens where there is none.',
            );
            $this->assertNotSame('', trim($failure->publicMessage()));
        }
    }

    /**
     * THE PUBLIC SENTENCE STILL SAYS NOTHING TECHNICAL. Adding categories must
     * not have leaked a status code, a vendor enum or a model name into
     * anything a teacher reads (§49).
     */
    #[Test]
    public function the_public_sentence_never_carries_a_technical_detail(): void
    {
        $failures = [
            AiRequestFailed::refused(401),
            AiRequestFailed::refused(500),
            AiRequestFailed::truncatedAnswer('MAX_TOKENS, no text produced'),
            AiRequestFailed::unparsableAnswer('no synthesis could be parsed from the answer'),
        ];

        foreach ($failures as $failure) {
            $message = $failure->publicMessage();

            foreach (['MAX_TOKENS', '401', '500', 'gemini', 'Gemini', 'token', 'parse', 'http', 'HTTP'] as $leak) {
                $this->assertStringNotContainsString($leak, $message, "«{$leak}» reached a teacher.");
            }
        }
    }
}
