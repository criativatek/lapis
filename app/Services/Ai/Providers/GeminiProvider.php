<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiTextProvider;
use App\Services\Ai\AiTextRequest;
use App\Services\Ai\AiTextResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google's Generative Language API — `POST /models/{model}:generateContent`.
 *
 * THE FIRST DRIVER IN THIS APPLICATION THAT NAMES A COMPANY, and it exists
 * because the operator asked for it by name, which is what CLAUDE.md §31
 * requires before a vendor may be chosen at all. Nothing above it knows:
 * callers hold `AiTextProvider`, the resolver picks by config, and swapping
 * engines is a settings change (§2 of the AI Core brief).
 *
 * THE KEY TRAVELS IN A HEADER, NEVER IN THE URL. Gemini also accepts `?key=…`,
 * and every layer between here and Google — proxies, the framework's own
 * exception rendering, an access log — records URLs. A credential in a query
 * string is a credential in somebody's log file. `x-goog-api-key` is the same
 * request with none of that (§3, §4).
 *
 * THE INSTRUCTION AND THE TEXT TRAVEL SEPARATELY (§30 of the Relatórios brief,
 * §6 here). `systemInstruction` carries what Lapispro asks for; `contents`
 * carries the material Lapispro is holding on somebody else's behalf. They are
 * never concatenated, so stored text that reads like an order arrives where it
 * cannot be read as one.
 *
 * THE OUTPUT CEILING IS EXPLICIT AND NOT THE CALLER'S TO RAISE.
 * `maxOutputTokens` comes from installation config; a request may carry a
 * budget of its own, but only because `AiGateway` put it there after clamping
 * it against the installation's hard ceiling. Nothing a caller builds has a
 * field for it — see `AiTextRequest`.
 *
 * THINKING IS PAID FOR OUT OF THE SAME BUDGET, WHICH IS THE WHOLE REASON
 * `thinkingConfig` IS SENT. On the Gemini 2.5 line `maxOutputTokens` is not the
 * size of the answer — it is the size of the answer plus everything the model
 * thought on the way to it, and the thinking is spent first. A six-section
 * instruction under a 2048-token ceiling could therefore come back as
 * `finishReason: MAX_TOKENS` with no `parts` at all, having produced nothing but
 * reasoning nobody asked for: a deterministic failure that looked exactly like
 * a flaky provider and survived every retry. `GeminiThinking` holds the table of
 * what each model will accept, and this driver sends the budget it names or
 * sends no `thinkingConfig` at all (§13 — nothing is guessed at the wire).
 *
 * ONE ATTEMPT, NO RETRY — the same reasoning as ChatCompletionsProvider. A
 * caller who waited out one timeout would rather be told than wait out a second.
 *
 * WHAT IT NEVER DOES: no `tools`, no function calling, no Google Search
 * grounding, no file upload, no cached content, no streaming. Every one of those
 * either sends more than the sanitiser approved or lets the answer act on the
 * application, and both are out of scope by design (§13).
 */
class GeminiProvider implements AiTextProvider
{
    public function __construct(
        protected string $baseUrl,
        protected string $key,
        protected string $model,
        protected int $timeout,
        protected int $maxOutputTokens,
    ) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function complete(AiTextRequest $request): AiTextResponse
    {
        $startedAt = hrtime(true);

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->key])
                ->timeout($this->timeout)
                // Never a connect timeout longer than the whole budget: this
                // fails fast or not at all.
                ->connectTimeout(min(5, $this->timeout))
                ->acceptJson()
                ->asJson()
                ->post($this->url(), [
                    'systemInstruction' => [
                        'parts' => [['text' => $request->instruction]],
                    ],
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [['text' => $request->content]],
                    ]],
                    'generationConfig' => $this->generationConfig($request),
                ]);
        } catch (ConnectionException $exception) {
            // Reported WITHOUT its message: a connection exception's text
            // carries the whole URL, and a deployment behind a proxy could put
            // a credential in one. The class and the fact are what a log needs.
            report(new ConnectionException('Gemini could not be reached.'));

            throw str_contains(strtolower($exception->getMessage()), 'timed out')
                ? AiRequestFailed::timedOut($this->timeout)
                : AiRequestFailed::unreachable();
        }

        if ($response->failed()) {
            // The STATUS, and nothing else. Gemini's error bodies quote back
            // request metadata, and a rejected key names its Google Cloud
            // project — neither belongs in a log line or an exception (§3).
            throw AiRequestFailed::refused($response->status());
        }

        return new AiTextResponse(
            text: $this->textOf($response),
            provider: $this->name(),
            model: $this->model,
            inputTokens: $this->count($response->json('usageMetadata.promptTokenCount')),
            outputTokens: $this->count($response->json('usageMetadata.candidatesTokenCount')),
            latencyMilliseconds: (int) round((hrtime(true) - $startedAt) / 1_000_000),
        );
    }

    /**
     * Everything under `generationConfig`, assembled in one place.
     *
     * THE `thinkingConfig` KEY IS PRESENT OR ABSENT — never present and empty,
     * and never present with a value the model rejects. `GeminiThinking`
     * returns null for every model whose thinking behaviour this application
     * has not been told about, including every model that predates the feature,
     * and a null means the key is simply not written. An unknown field is a 400
     * on the 1.5 and 2.0 lines, so «send nothing» is the only safe default for
     * a model name an operator typed by hand.
     *
     * @return array<string, mixed>
     */
    protected function generationConfig(AiTextRequest $request): array
    {
        $config = [
            'temperature' => $request->temperature,
            'maxOutputTokens' => $this->budgetFor($request),
        ];

        $thinkingBudget = GeminiThinking::budgetFor($this->model);

        if ($thinkingBudget !== null) {
            $config['thinkingConfig'] = ['thinkingBudget' => $thinkingBudget];
        }

        return $config;
    }

    /**
     * The ceiling for THIS call.
     *
     * A request that carries its own budget was given one by `AiGateway`, which
     * is the only thing that may hand out one and has already clamped it
     * against `lapis.ai.max_output_tokens_ceiling`. Anything else — a request
     * built without one, and every path in the product that does not need more
     * room — gets the installation default this provider was constructed with.
     *
     * A non-positive value is treated as absent rather than sent: zero output
     * tokens is a request for an empty answer, and no caller means that.
     */
    protected function budgetFor(AiTextRequest $request): int
    {
        return $request->maxOutputTokens !== null && $request->maxOutputTokens > 0
            ? $request->maxOutputTokens
            : $this->maxOutputTokens;
    }

    /**
     * A 200 IS NOT AN ANSWER. Gemini reports a refused prompt, a safety block
     * and a truncated reply all with HTTP 200 and a differently-shaped body, so
     * every one of them has to be recognised here or it becomes an empty string
     * presented to somebody as a suggestion.
     *
     * @throws AiRequestFailed when there is no usable text in the body.
     */
    protected function textOf(Response $response): string
    {
        $blocked = $response->json('promptFeedback.blockReason');

        if (is_string($blocked) && $blocked !== '') {
            throw AiRequestFailed::unusableAnswer('the prompt was blocked ('.$this->slug($blocked).')');
        }

        $finish = $response->json('candidates.0.finishReason');

        // MAX_TOKENS IS ITS OWN FAILURE, not one more unusable answer. Half an
        // answer is still worse than none — that judgement has not changed —
        // but the CAUSE is a budget this installation set, not a vendor having
        // a bad day, and only an operator can fix it. Reported with whether
        // anything came back at all, because a truncation with no text is the
        // signature of a model that spent the entire budget thinking, and a
        // truncation with text is a genuinely long answer.
        if ($finish === 'MAX_TOKENS') {
            throw AiRequestFailed::truncatedAnswer(
                'MAX_TOKENS, '.($this->hasText($response) ? 'partial text discarded' : 'no text produced')
            );
        }

        // STOP is the ordinary end. Everything else — a safety stop, a recitation
        // block, a vendor enum this application has never seen — is an answer
        // that ended somewhere other than where the instruction asked it to.
        if (is_string($finish) && ! in_array($finish, ['STOP', 'FINISH_REASON_UNSPECIFIED'], true)) {
            throw AiRequestFailed::unusableAnswer('the answer ended early ('.$this->slug($finish).')');
        }

        $parts = $response->json('candidates.0.content.parts');

        if (! is_array($parts)) {
            throw AiRequestFailed::unusableAnswer('no content parts in the first candidate');
        }

        $text = $this->joinParts($parts);

        if (trim($text) === '') {
            throw AiRequestFailed::unusableAnswer('no text in the first candidate');
        }

        return $text;
    }

    /** Whether the first candidate carried any text at all. Used only to describe a truncation. */
    protected function hasText(Response $response): bool
    {
        $parts = $response->json('candidates.0.content.parts');

        return is_array($parts) && trim($this->joinParts($parts)) !== '';
    }

    /**
     * Gemini splits one answer across several parts, and they are reassembled in
     * order.
     *
     * A PART MARKED `thought` IS NOT THE ANSWER. When an installation asks for
     * thought summaries the model returns its reasoning as ordinary text parts
     * flagged with `thought: true`, and concatenating those would put the
     * model's private working-out in front of a teacher under a heading that
     * promises a reading. This driver never asks for them; it drops them anyway,
     * because a field that only matters when somebody changes a setting is
     * exactly the field that gets forgotten when they do.
     *
     * @param  array<int|string, mixed>  $parts
     */
    protected function joinParts(array $parts): string
    {
        $text = '';

        foreach ($parts as $part) {
            if (! is_array($part) || ! isset($part['text']) || ! is_string($part['text'])) {
                continue;
            }

            if (($part['thought'] ?? false) === true) {
                continue;
            }

            $text .= $part['text'];
        }

        return $text;
    }

    /**
     * The model is a path segment, so it is escaped rather than interpolated —
     * a model name is operator-supplied configuration, and configuration that
     * builds a URL is configuration that can build a different one.
     */
    protected function url(): string
    {
        return $this->baseUrl.'/models/'.rawurlencode($this->model).':generateContent';
    }

    /** An absent count stays absent. Recording it as zero would be a false measurement. */
    protected function count(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    /**
     * A vendor enum reduced to something safe to write down: upper-case letters
     * and underscores only, and short. It reaches a log line, so it must not be
     * a place where a remote system can put arbitrary text.
     */
    protected function slug(string $value): string
    {
        $slug = (string) preg_replace('/[^A-Z_]/', '', strtoupper($value));

        return $slug === '' ? 'UNKNOWN' : mb_substr($slug, 0, 32);
    }
}
