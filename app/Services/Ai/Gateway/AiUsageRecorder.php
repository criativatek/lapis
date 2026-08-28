<?php

namespace App\Services\Ai\Gateway;

use App\Models\AiUsageEvent;
use App\Models\Organization;
use App\Models\User;

/**
 * Writes one row of `ai_usage_events` per call, whatever happened.
 *
 * EVERY OUTCOME IS RECORDED, INCLUDING THE ONES THAT NEVER LEFT. A meter that
 * only counts successes cannot answer the two questions an operator actually
 * asks — «why is this costing so much» needs the failures, and «why are teachers
 * complaining» needs the blocks. A ceiling set wrong is invisible unless the
 * refusals are written down.
 *
 * IT CANNOT RECORD CONTENT, and that is a property of the schema rather than a
 * rule this class follows. There is no column for a prompt or an answer, so
 * there is no version of this file that could start storing one without a
 * migration that somebody would have to review (§7).
 *
 * THE PROVIDER AND MODEL ARE RECORDED EVEN WHEN THE CALL FAILED, and this
 * matters more than it looks: «all the 429s were on the old model» is only
 * answerable if a failed row knows what it was talking to. On a blocked call
 * there is no engine yet, so those two say what WOULD have answered — the
 * configured driver and model — rather than nothing.
 */
class AiUsageRecorder
{
    /** The engine answered usefully. */
    public function succeeded(
        AiAsk $ask,
        AiAnswer $answer,
        ?Organization $organization,
        ?User $user,
        int $durationMilliseconds,
    ): AiUsageEvent {
        return $this->record(
            $ask,
            $organization,
            $user,
            provider: $answer->provider,
            model: $answer->model,
            status: AiUsageEvent::SUCCEEDED,
            durationMilliseconds: $durationMilliseconds,
            inputTokens: $answer->inputTokens,
            outputTokens: $answer->outputTokens,
            totalTokens: $answer->totalTokens(),
        );
    }

    /** The engine was asked and did not answer usefully. */
    public function failed(
        AiAsk $ask,
        ?Organization $organization,
        ?User $user,
        string $provider,
        string $model,
        string $errorCategory,
        int $durationMilliseconds,
    ): AiUsageEvent {
        return $this->record(
            $ask,
            $organization,
            $user,
            provider: $provider,
            model: $model,
            status: AiUsageEvent::FAILED,
            durationMilliseconds: $durationMilliseconds,
            errorCategory: $errorCategory,
        );
    }

    /**
     * An entitlement, a quota or a rate limit stopped the call.
     *
     * No duration and no tokens: nothing happened that could be measured, and a
     * zero here would be indistinguishable from an instant success.
     */
    public function blocked(
        AiAsk $ask,
        ?Organization $organization,
        ?User $user,
        string $reason,
        ?string $provider = null,
        ?string $model = null,
    ): AiUsageEvent {
        return $this->record(
            $ask,
            $organization,
            $user,
            // What WOULD have answered. Unknown is recorded as unknown rather
            // than as an empty string.
            provider: $provider ?? $this->configuredProvider(),
            model: $model ?? $this->configuredModel(),
            status: AiUsageEvent::BLOCKED,
            errorCategory: $reason,
        );
    }

    protected function record(
        AiAsk $ask,
        ?Organization $organization,
        ?User $user,
        string $provider,
        string $model,
        string $status,
        ?int $durationMilliseconds = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $totalTokens = null,
        ?string $errorCategory = null,
    ): AiUsageEvent {
        return AiUsageEvent::create([
            'organization_id' => $organization?->getKey(),
            'user_id' => $user?->getKey(),
            'capability' => $ask->useCase->capabilityColumn(),
            'use_case' => $ask->useCase,
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'duration_ms' => $durationMilliseconds,
            'status' => $status,
            'error_category' => $errorCategory,
            'subject_hash' => $ask->subjectHash,
            'created_at' => now(),
        ]);
    }

    /** Truncated to the column: an operator can misconfigure a very long model name. */
    protected function configuredProvider(): string
    {
        $driver = config('lapis.ai.driver');

        return mb_substr(is_string($driver) && $driver !== '' ? $driver : 'none', 0, 32);
    }

    protected function configuredModel(): string
    {
        $model = config('lapis.ai.model');

        return mb_substr(is_string($model) && $model !== '' ? $model : 'none', 0, 64);
    }
}
