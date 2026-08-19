<?php

namespace App\Services\Reporting\Writing;

/**
 * A proposal, and everything needed to decide whether to take it (§19).
 *
 * IT CARRIES BOTH TEXTS. The screen shows what the section says now beside what
 * it could say, because a teacher cannot judge a rewrite of their own paragraph
 * without the paragraph. Nothing is written until they choose (§19, §51).
 *
 * A REFUSED SUGGESTION IS STILL A SUGGESTION OBJECT, with `text` null and a
 * verdict that says why. Returning null from the service instead would lose the
 * reason, and the reason is what the audit trail is for.
 *
 * WHAT IS NOT HERE: the prompt, the redacted text, the markers, the pseudonyms,
 * and anything the provider said beyond the sentence itself. This object crosses
 * into the HTTP layer and from there into a browser, so it holds what a teacher
 * needs to see and the identifiers an auditor needs to match it up — nothing
 * else.
 */
readonly class RewriteSuggestion
{
    public function __construct(
        public string $sectionUlid,
        public string $sectionKey,
        public string $heading,
        public WritingMode $mode,
        public string $current,
        public ?string $text,
        public RewriteVerdict $verdict,
        public string $promptVersion,
        public string $provider,
        public string $model,
        public bool $pseudonymised,
    ) {}

    /** Whether there is something a teacher may be offered. */
    public function isUsable(): bool
    {
        return $this->text !== null
            && $this->verdict->acceptable
            && trim($this->text) !== ''
            && $this->text !== $this->current;
    }

    /**
     * The shape the editor receives.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'section' => $this->sectionUlid,
            'section_key' => $this->sectionKey,
            'heading' => $this->heading,
            'mode' => $this->mode->value,
            'mode_label' => $this->mode->label(),
            'current' => $this->current,
            'text' => $this->isUsable() ? $this->text : null,
            // The engine that answered, shown beside the suggestion so nobody
            // has to wonder where a sentence came from.
            'provider' => $this->provider,
            'model' => $this->model,
            'pseudonymised' => $this->pseudonymised,
            'message' => $this->message(),
        ];
    }

    /**
     * What the teacher reads.
     *
     * The «unchanged» case gets its own sentence because it is not a failure and
     * should not be dressed as one: a model that had nothing to improve is
     * allowed to say so, and telling a teacher their paragraph was already fine
     * is a useful answer.
     */
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
