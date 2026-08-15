<?php

namespace App\Domain\Import\Correction;

/**
 * One question as the source describes it.
 *
 * `code` is a label, never identity. Q1 may legitimately exist in two different
 * groups of the same instrument — that is a real thing on a real test paper, and
 * anything that resolves a question by its code alone will silently merge two
 * different questions. Identity inside an import is `sourceKey`; identity
 * afterwards is the InstrumentItem's own key.
 *
 * `pointsPossible` is nullable on purpose. Plickers does not say what a question
 * is worth, and inventing a number would be inventing an assessment rule (§1).
 * Null means "nobody has decided yet", which is a state the import can carry all
 * the way to the teacher — but never past confirmation.
 *
 * `domainHint` is a hint and nothing more. No source is trusted to classify a
 * question curricularly; the teacher does that (§55).
 */
final readonly class CanonicalItem
{
    /**
     * @param  string  $sourceKey  Identity WITHIN this import only.
     * @param  string|null  $answerKey  The correct response, when the source states one.
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $sourceKey,
        public int $sequence,
        public ?string $groupSourceKey = null,
        public ?string $externalId = null,
        public ?string $code = null,
        public ?string $label = null,
        public ?string $questionText = null,
        public ?string $pointsPossible = null,
        public ?string $answerKey = null,
        public ?string $domainHint = null,
        public array $metadata = [],
    ) {}

    /**
     * Whether this question can already produce marks. A question with no
     * declared worth cannot: not because the import failed, but because nobody
     * has said what it is worth yet.
     */
    public function hasDeclaredWorth(): bool
    {
        return $this->pointsPossible !== null;
    }

    public function withPointsPossible(string $pointsPossible): self
    {
        return new self(
            sourceKey: $this->sourceKey,
            sequence: $this->sequence,
            groupSourceKey: $this->groupSourceKey,
            externalId: $this->externalId,
            code: $this->code,
            label: $this->label,
            questionText: $this->questionText,
            pointsPossible: $pointsPossible,
            answerKey: $this->answerKey,
            domainHint: $this->domainHint,
            metadata: $this->metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_key' => $this->sourceKey,
            'sequence' => $this->sequence,
            'group_source_key' => $this->groupSourceKey,
            'external_id' => $this->externalId,
            'code' => $this->code,
            'label' => $this->label,
            'question_text' => $this->questionText,
            'points_possible' => $this->pointsPossible,
            'answer_key' => $this->answerKey,
            'domain_hint' => $this->domainHint,
            'metadata' => $this->metadata,
        ];
    }
}
