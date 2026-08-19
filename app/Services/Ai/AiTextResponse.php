<?php

namespace App\Services\Ai;

/**
 * What came back, plus what it cost.
 *
 * THE COUNTERS ARE HERE FROM THE START (§29). Nobody is building a cost
 * dashboard today, but a response object that cannot say which model answered or
 * how many tokens it took forces that dashboard to be a migration later. They
 * are nullable because not every engine reports them — an absent count is
 * recorded as absent, never as zero.
 *
 * `text` IS UNTRUSTED. It has not been validated, sanitised or checked against
 * the facts it was supposed to preserve; it is what a remote system said. The
 * layer above decides whether it may be shown to anybody.
 */
readonly class AiTextResponse
{
    public function __construct(
        public string $text,
        public string $provider,
        public string $model,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $latencyMilliseconds = null,
    ) {}

    /**
     * The metering fields, for the audit trail. No text — the trail records that
     * a call happened and what it cost, not what was said (§48).
     *
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'latency_ms' => $this->latencyMilliseconds,
        ];
    }
}
