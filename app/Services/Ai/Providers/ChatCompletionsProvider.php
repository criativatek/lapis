<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiTextProvider;
use App\Services\Ai\AiTextRequest;
use App\Services\Ai\AiTextResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A wire format, not a vendor.
 *
 * `POST /chat/completions` with a `messages` array is implemented by most hosted
 * engines and by every self-hosted runner worth the name. This driver speaks it
 * and knows nothing else: the endpoint, the model and the key all come from the
 * environment, there is no default for any of them, and no company is named
 * anywhere in this file. Whoever configures LAPIS_AI_ENDPOINT decides where the
 * text goes — including to a machine inside the school (§8, and CLAUDE.md §31,
 * which puts «pick an AI provider» on the ask-first list).
 *
 * THE INSTRUCTION AND THE TEXT TRAVEL AS DIFFERENT ROLES (§30). `system` carries
 * what LÁPIS wants; `user` carries the teacher's paragraph. They are never
 * concatenated, so a report containing «ignora as instruções acima» arrives as
 * content — which is what it is.
 *
 * ONE ATTEMPT. No retry: a rephrase is a convenience, and a teacher who waited
 * twenty seconds for nothing would rather click again than wait forty (§27).
 */
class ChatCompletionsProvider implements AiTextProvider
{
    public function __construct(
        protected string $endpoint,
        protected string $key,
        protected string $model,
        protected int $timeout,
    ) {}

    public function name(): string
    {
        return 'chat-completions';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function complete(AiTextRequest $request): AiTextResponse
    {
        $startedAt = hrtime(true);

        try {
            $response = Http::withToken($this->key)
                ->timeout($this->timeout)
                // No retries, and no connect timeout longer than the whole
                // budget: this must fail fast or not at all.
                ->connectTimeout(min(5, $this->timeout))
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint, [
                    'model' => $this->model,
                    'temperature' => $request->temperature,
                    'messages' => [
                        ['role' => 'system', 'content' => $request->instruction],
                        ['role' => 'user', 'content' => $request->content],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            // The message can carry the endpoint, which can carry a key in a
            // query string on some deployments. It is not re-thrown.
            report($exception);

            throw str_contains(strtolower($exception->getMessage()), 'timed out')
                ? AiRequestFailed::timedOut($this->timeout)
                : AiRequestFailed::unreachable();
        }

        if ($response->failed()) {
            throw AiRequestFailed::refused($response->status());
        }

        $text = $response->json('choices.0.message.content');

        if (! is_string($text) || trim($text) === '') {
            throw AiRequestFailed::unusableAnswer('no text in the first choice');
        }

        return new AiTextResponse(
            text: $text,
            provider: $this->name(),
            model: $this->model,
            inputTokens: $this->count($response->json('usage.prompt_tokens')),
            outputTokens: $this->count($response->json('usage.completion_tokens')),
            latencyMilliseconds: (int) round((hrtime(true) - $startedAt) / 1_000_000),
        );
    }

    /** An absent count stays absent. Recording it as zero would be a false measurement. */
    protected function count(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
