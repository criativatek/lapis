<?php

namespace App\Services\Evidence\Ai;

/**
 * A proposal, and everything needed to decide whether to take it — the
 * Registos equivalent of `App\Services\Reporting\Writing\RewriteSuggestion`.
 *
 * IT WRITES NOTHING. This crosses into the HTTP layer and from there into a
 * browser via a flashed session value; nothing here ever touches
 * `EvidenceRecord`. Accepting is the teacher pasting this text over their own
 * draft, in a form that has not been submitted yet.
 */
readonly class IncidentRewriteSuggestion
{
    public function __construct(
        public string $current,
        public ?string $text,
        public IncidentRewriteVerdict $verdict,
        public string $promptVersion,
        public string $provider,
        public string $model,
        public bool $pseudonymised,
    ) {}

    public function isUsable(): bool
    {
        return $this->text !== null
            && $this->verdict->acceptable
            && trim($this->text) !== ''
            && $this->text !== $this->current;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'current' => $this->current,
            'text' => $this->isUsable() ? $this->text : null,
            'provider' => $this->provider,
            'model' => $this->model,
            'pseudonymised' => $this->pseudonymised,
            'message' => $this->message(),
        ];
    }

    protected function message(): ?string
    {
        if (! $this->verdict->acceptable) {
            return $this->verdict->publicMessage();
        }

        if ($this->text !== null && $this->text === $this->current) {
            return 'A reformulação devolveu o mesmo texto. Nada foi alterado.';
        }

        return null;
    }
}
