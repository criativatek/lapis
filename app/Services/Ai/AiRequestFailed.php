<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * The engine was asked and did not answer usefully.
 *
 * TWO MESSAGES, ON PURPOSE. `getMessage()` is for the log and carries the
 * technical reason; `publicMessage()` is what a teacher may read and never
 * carries a status code, an endpoint, a vendor name or a stack trace (§49).
 *
 * NEITHER EVER CARRIES THE KEY (§47). The constructors below are the only way to
 * build one, and none of them accepts credentials — a provider that wants to
 * report «401» reports the number, not the header it sent.
 */
class AiRequestFailed extends RuntimeException
{
    protected function __construct(string $message, protected string $publicMessage)
    {
        parent::__construct($message);
    }

    public static function timedOut(int $seconds): self
    {
        return new self(
            "The writing assistant did not answer within {$seconds}s.",
            'O apoio à redação demorou demasiado a responder. O texto atual foi preservado.',
        );
    }

    public static function refused(int $status): self
    {
        // 429 is the one a teacher can act on — everything else is the same
        // sentence, because «tente mais tarde» is all any of them mean here.
        return new self(
            "The writing assistant answered {$status}.",
            $status === 429
                ? 'O apoio à redação está a receber demasiados pedidos. Tente novamente dentro de instantes.'
                : 'Não foi possível obter uma sugestão neste momento. O texto atual foi preservado.',
        );
    }

    public static function unusableAnswer(string $why): self
    {
        return new self(
            "The writing assistant answered something unusable: {$why}.",
            'Não foi possível obter uma sugestão neste momento. O texto atual foi preservado.',
        );
    }

    public static function unreachable(): self
    {
        return new self(
            'The writing assistant could not be reached.',
            'Não foi possível contactar o apoio à redação. O texto atual foi preservado.',
        );
    }

    /** Safe to show a teacher. */
    public function publicMessage(): string
    {
        return $this->publicMessage;
    }
}
