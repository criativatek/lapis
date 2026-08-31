<?php

namespace App\Services\Ai\Providers;

/**
 * How much of the answer budget a Gemini model may spend on THINKING.
 *
 * WHY THIS CLASS EXISTS AT ALL. `maxOutputTokens` on the Gemini 2.5 line is not
 * a ceiling on the answer — it is a ceiling on the answer PLUS the model's
 * internal reasoning, and reasoning is billed and counted first. A model given
 * 2048 tokens and a six-section instruction can spend all 2048 thinking, return
 * `finishReason: MAX_TOKENS` with no `parts` at all, and be entirely within its
 * contract. That is not an outage, a bad key or a flaky network: it is a
 * DETERMINISTIC failure that repeats on every retry, which is exactly what the
 * «Síntese de acompanhamento» was hitting.
 *
 * THE TABLE IS A VENDOR FACT, NOT A PREFERENCE, and it is written down in one
 * place so that no controller ever grows a `if ($model === …)`. Google does not
 * accept the same `thinkingBudget` everywhere:
 *
 *   - `gemini-2.5-flash` and `gemini-2.5-flash-lite` accept 0, which turns
 *     thinking OFF. That is what this application wants: every prompt it sends
 *     is a closed instruction over already-computed facts, and none of them is a
 *     reasoning problem.
 *   - `gemini-2.5-pro` CANNOT have thinking disabled. Zero is rejected by the
 *     API, so the minimum it does accept (128) is sent instead — the cheapest
 *     legal value rather than an invalid one.
 *   - Everything else — the 2.0 and 1.5 lines, anything newer, anything an
 *     operator typed by hand — gets NO `thinkingConfig` at all. An unknown field
 *     is a 400 on models that predate the feature, and guessing a budget for a
 *     model this table has never seen would trade a known failure for an
 *     unknown one.
 *
 * SILENCE IS THE SAFE ANSWER. `null` means «send nothing», and sending nothing
 * is always a valid request. That is why the default arm returns it.
 */
class GeminiThinking
{
    /** The smallest budget `gemini-2.5-pro` accepts. Below this the API rejects the request. */
    public const PRO_MINIMUM = 128;

    /**
     * The `thinkingBudget` to send for `$model`, or null to send no
     * `thinkingConfig` at all.
     *
     * Matching is on a NORMALISED PREFIX rather than equality, because Google
     * ships dated and previewed variants of the same model
     * (`gemini-2.5-flash-preview-05-20`, `gemini-2.5-pro-002`) that behave
     * identically for this purpose. A `models/` prefix is tolerated because
     * that is how the API names them in its own documentation, and an operator
     * pasting from it should not get a silently different request.
     */
    public static function budgetFor(string $model): ?int
    {
        $normalised = strtolower(trim($model));
        $normalised = str_starts_with($normalised, 'models/')
            ? substr($normalised, strlen('models/'))
            : $normalised;

        return match (true) {
            // Checked BEFORE plain flash: `gemini-2.5-flash-lite` also starts
            // with `gemini-2.5-flash`, and the two are kept apart here so that
            // the day their accepted ranges diverge, this table is where it
            // shows.
            str_starts_with($normalised, 'gemini-2.5-flash-lite') => 0,
            str_starts_with($normalised, 'gemini-2.5-flash') => 0,

            // The one model on the 2.5 line that may not be silenced.
            str_starts_with($normalised, 'gemini-2.5-pro') => self::PRO_MINIMUM,

            default => null,
        };
    }

    /** Whether this model is one whose thinking budget this application knows how to set. */
    public static function isConfigurable(string $model): bool
    {
        return self::budgetFor($model) !== null;
    }
}
