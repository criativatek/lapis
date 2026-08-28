<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * There is no engine to ask.
 *
 * DISTINCT FROM AiRequestFailed, and the distinction matters to the person
 * reading the screen: «failed» means try again, «unavailable» means this
 * installation has not turned the feature on and clicking will never help.
 *
 * IT NOW CARRIES A REASON, for the same purpose `AiRequestFailed::category()`
 * carries one: the screen needs a sentence, and `ai_usage_events` needs a word
 * it can group by. `plan` and `provider` are the two that matter and they are
 * NOT the same problem — a Base school is being offered an upgrade, a Pro school
 * with no engine configured is waiting on whoever administers the installation,
 * and telling one that it is the other wastes everybody's afternoon (§41).
 */
class AiUnavailable extends RuntimeException
{
    protected function __construct(
        string $message,
        protected string $publicMessage,
        protected string $reason,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(
            'No writing assistant is configured for this installation.',
            'O apoio à redação não está configurado nesta instalação.',
            'provider',
        );
    }

    /**
     * The installation has an engine; this organization's plan does not include
     * the capability being asked for.
     */
    public static function notEntitled(string $capability): self
    {
        return new self(
            "The current plan does not include the [{$capability}] capability.",
            'Esta funcionalidade não está incluída no plano atual.',
            'plan',
        );
    }

    public function publicMessage(): string
    {
        return $this->publicMessage;
    }

    /** `plan` or `provider`. */
    public function reason(): string
    {
        return $this->reason;
    }
}
