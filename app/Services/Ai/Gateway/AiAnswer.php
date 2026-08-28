<?php

namespace App\Services\Ai\Gateway;

use App\Services\Ai\AiTextResponse;
use App\Support\Privacy\SanitisedPayload;

/**
 * What came back, with the names put back and the meter read.
 *
 * `text` IS UNTRUSTED AND THIS OBJECT DOES NOT PRETEND OTHERWISE. It has been
 * rehydrated — the pseudonyms are people again — and nothing else. It has not
 * been checked against the facts it was supposed to preserve, it has not been
 * verified to obey the instruction, and it is not safe to store or show without
 * the calling feature deciding that it is. Relatórios has `RewriteGuard` for
 * exactly this job; a feature built on the gateway needs its own answer to «what
 * if the model said something it should not have», and the gateway deliberately
 * does not guess what that answer is.
 *
 * REHYDRATION IS NAMES ONLY. Emails, telephone numbers and identifiers that the
 * sanitiser removed do not come back, here or anywhere: a placeholder that could
 * be turned back into a phone number would mean the phone number had merely
 * taken a trip.
 */
readonly class AiAnswer
{
    public function __construct(
        public string $text,
        public string $provider,
        public string $model,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $durationMilliseconds = null,
    ) {}

    /**
     * Build from a provider's raw response, putting the names back on the way.
     *
     * The gateway is the only caller: rehydration has to happen exactly once,
     * and it has to happen after the answer is in hand and before anybody else
     * sees it.
     */
    public static function from(AiTextResponse $response, SanitisedPayload $sent, ?int $durationMilliseconds = null): self
    {
        return new self(
            text: $sent->rehydrate($response->text),
            provider: $response->provider,
            model: $response->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            durationMilliseconds: $durationMilliseconds ?? $response->latencyMilliseconds,
        );
    }

    /** Input + output, or null when either is unknown — a partial total is a wrong total. */
    public function totalTokens(): ?int
    {
        if ($this->inputTokens === null || $this->outputTokens === null) {
            return null;
        }

        return $this->inputTokens + $this->outputTokens;
    }
}
