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
 *
 * A THIRD THING, ADDED FOR THE AI CORE: `category()`. The two messages are for
 * people; the category is for the machine — it is what
 * `ai_usage_events.error_category` stores (§7), and what the backoffice's
 * «testar ligação» reads to tell an OPERATOR that a credential was rejected.
 * That distinction is deliberate: a teacher must never be told which of a dozen
 * things went wrong at the vendor, and an operator debugging a key is useless
 * without it. The category is a closed vocabulary written here, never a vendor
 * string passed through.
 */
class AiRequestFailed extends RuntimeException
{
    /** Every value `category()` can return. Closed — a new one is added here or not at all. */
    public const CATEGORIES = [
        'timeout',
        'unauthorized',
        'rate_limited',
        'refused',
        'provider_error',
        'unusable_answer',
        'unreachable',
    ];

    protected function __construct(
        string $message,
        protected string $publicMessage,
        protected string $category,
    ) {
        parent::__construct($message);
    }

    public static function timedOut(int $seconds): self
    {
        return new self(
            "The writing assistant did not answer within {$seconds}s.",
            'O apoio à redação demorou demasiado a responder. O texto atual foi preservado.',
            'timeout',
        );
    }

    public static function refused(int $status): self
    {
        // 429 is the one a teacher can act on — everything else is the same
        // sentence, because «tente mais tarde» is all any of them mean here.
        //
        // 401 AND 403 ARE DELIBERATELY NOT SPECIAL IN THE PUBLIC MESSAGE. «A
        // chave está errada» is true, useful to exactly one person in the
        // building, and an invitation to probe for everybody else; a teacher
        // gets the same sentence as for any other failure, and the fact travels
        // in the category instead — where the backoffice, and only the
        // backoffice, reads it.
        return new self(
            "The writing assistant answered {$status}.",
            $status === 429
                ? 'O apoio à redação está a receber demasiados pedidos. Tente novamente dentro de instantes.'
                : 'Não foi possível obter uma sugestão neste momento. O texto atual foi preservado.',
            match (true) {
                $status === 429 => 'rate_limited',
                in_array($status, [401, 403], true) => 'unauthorized',
                $status >= 500 => 'provider_error',
                default => 'refused',
            },
        );
    }

    public static function unusableAnswer(string $why): self
    {
        return new self(
            "The writing assistant answered something unusable: {$why}.",
            'Não foi possível obter uma sugestão neste momento. O texto atual foi preservado.',
            'unusable_answer',
        );
    }

    public static function unreachable(): self
    {
        return new self(
            'The writing assistant could not be reached.',
            'Não foi possível contactar o apoio à redação. O texto atual foi preservado.',
            'unreachable',
        );
    }

    /** Safe to show a teacher. */
    public function publicMessage(): string
    {
        return $this->publicMessage;
    }

    /**
     * One of self::CATEGORIES. Safe to store and to show an operator: it names
     * a KIND of failure, never a value, a URL, a project or a key.
     */
    public function category(): string
    {
        return $this->category;
    }
}
