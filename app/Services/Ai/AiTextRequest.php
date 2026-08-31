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
 * `maxOutputTokens` IS RESOLVED, NOT REQUESTED, and the distinction is the
 * whole of why it is allowed to exist here at all. This class used to carry no
 * ceiling on the stated grounds that «a ceiling a caller can express is a
 * ceiling a caller can raise», and that reasoning still holds — so nothing a
 * caller touches has gained a field. `AiAsk`, which is what callers build, has
 * none. This object is constructed by `AiGateway` and by nobody else, from the
 * installation's configured default, the use case's declared minimum, and the
 * installation's hard ceiling. A null still means «take the provider's
 * installation default», which is what every path that does not need more gets.
 */
readonly class AiTextRequest
{
    public function __construct(
        public string $instruction,
        public string $content,
        /** Deterministic by preference: the same paragraph should not read differently on every click. */
        public float $temperature = 0.2,
        /**
         * The budget for this one call, already clamped by `AiGateway`, or null
         * to use `lapis.ai.max_output_tokens`. Never caller-supplied — see the
         * class docblock.
         */
        public ?int $maxOutputTokens = null,
    ) {}
}
