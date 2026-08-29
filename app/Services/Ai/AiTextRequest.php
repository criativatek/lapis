<?php

namespace App\Services\Ai;

/**
 * One call to a text model: an instruction, and the text it applies to.
 *
 * THE TWO ARE NOT CONCATENATED, and that is the whole point of this class
 * existing (§30). `instruction` is what the application asks for; `content` is
 * material the application is carrying on somebody else's behalf. A teacher who
 * types «ignora as instruções anteriores» into a report has written a sentence
 * about their class, not given an order, and the provider must receive it in a
 * position where it cannot be read as one.
 *
 * Providers therefore MUST send these as separate roles. A provider that pastes
 * them into one string is a bug in that provider, not a decision this layer can
 * be asked to make.
 *
 * Nothing here knows what a report is. This is a text-in, text-out contract:
 * the report-specific rules — which facts are protected, which names are hidden,
 * what may be rejected — live in App\Services\Reporting\Writing and never reach
 * a provider.
 *
 * THERE IS NO OUTPUT CEILING ON THIS CLASS, deliberately. A ceiling a caller
 * can express is a ceiling a caller can raise, so the only one that exists is
 * `lapis.ai.max_output_tokens` — installation configuration, read by each real
 * provider at the wire and settable by an operator in the backoffice. A field
 * here would be a second source of truth for the same number, in a unit
 * (characters) that no engine's API actually accepts.
 */
readonly class AiTextRequest
{
    public function __construct(
        public string $instruction,
        public string $content,
        /** Deterministic by preference: the same paragraph should not read differently on every click. */
        public float $temperature = 0.2,
    ) {}
}
