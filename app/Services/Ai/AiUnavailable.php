<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * There is no engine to ask.
 *
 * DISTINCT FROM AiRequestFailed, and the distinction matters to the person
 * reading the screen: «failed» means try again, «unavailable» means this
 * installation has not turned the feature on and clicking will never help.
 */
class AiUnavailable extends RuntimeException
{
    protected function __construct(string $message, protected string $publicMessage)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(
            'No writing assistant is configured for this installation.',
            'O apoio à redação não está configurado nesta instalação.',
        );
    }

    public function publicMessage(): string
    {
        return $this->publicMessage;
    }
}
