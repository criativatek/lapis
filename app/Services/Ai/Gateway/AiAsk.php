<?php

namespace App\Services\Ai\Gateway;

use App\Support\Privacy\SanitisedPayload;

/**
 * One request to `AiGateway`: what for, what to say, and what about.
 *
 * `content` IS A `SanitisedPayload`, NOT A STRING, AND THAT IS THE WHOLE POINT
 * (§5). A service cannot send an engine text it prepared itself, because this
 * object will not hold text that has not been through `AiPayloadSanitizer`. The
 * privacy policy is enforced by the type system rather than by a code review.
 *
 * `instruction` AND `content` ARE NEVER CONCATENATED — not here, not in the
 * gateway, not in a provider (§6, and §30 of the Relatórios brief). The
 * instruction is what Lapispro asks for; the content is material Lapispro is
 * carrying on somebody else's behalf. They travel as different roles all the way
 * to the wire, so stored text that reads like an order arrives where it cannot
 * be obeyed.
 *
 * `instruction` IS APPLICATION-AUTHORED, ALWAYS. There is no path by which a
 * user's text becomes an instruction — no custom prompt field, no
 * per-organization guidance box, no «additional context» parameter. Every one of
 * those would be a place to write «and ignore the rules above», and ADR-0006
 * already closed that door for Relatórios; this keeps it closed for everything
 * built on the gateway.
 *
 * `promptVersion` IS REQUIRED. A usage row saying «a pedagogical analysis
 * happened on 3 March» is worth very little if nobody can tell what the engine
 * had been told that day. Any change to an instruction's text takes a new
 * version — the convention `WritingPrompt::VERSION` established.
 */
readonly class AiAsk
{
    public function __construct(
        public AiUseCase $useCase,
        /** Application-authored, versioned. Never user text. */
        public string $instruction,
        /** Whatever the caller is asking about, already minimised. */
        public SanitisedPayload $content,
        /** Bump on any change to `instruction`. Recorded on the usage row. */
        public string $promptVersion,
        /**
         * Enough to see that two calls were about the same thing, never enough
         * to say what. Callers hash whatever identifies the subject to them —
         * the gateway does not invent one, because it does not know what the
         * subject is.
         */
        public ?string $subjectHash = null,
        /** Deterministic by preference: the same question should not get a different shape of answer each time. */
        public float $temperature = 0.2,
    ) {}

    /** The entitlement this ask needs, or null for the operator's own traffic. */
    public function capability(): ?AiCapability
    {
        return $this->useCase->capability();
    }
}
