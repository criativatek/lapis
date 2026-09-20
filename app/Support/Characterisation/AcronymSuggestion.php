<?php

namespace App\Support\Characterisation;

/**
 * A plausible correction for a token that came back unrecognised — §19.
 *
 * THIS IS NEVER A RESOLUTION. It names a dictionary token the raw text
 * probably meant, and nothing else: no code, no level, no family. Accepting
 * one does not resolve a measure — it only replaces the raw text and sends it
 * back through LegalCodeResolver exactly as if the teacher had typed the
 * suggested token herself. That is the whole point of keeping this a
 * separate, tiny object rather than a second constructor on CodeResolution:
 * a suggestion cannot be mistaken for, or silently promoted into, a
 * resolution.
 */
readonly class AcronymSuggestion
{
    public function __construct(
        public string $token,
        public ?string $expansion,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'expansion' => $this->expansion,
        ];
    }
}
