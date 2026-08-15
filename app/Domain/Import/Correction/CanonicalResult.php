<?php

namespace App\Domain\Import\Correction;

use App\Models\ResultState;

/**
 * What one student did on one question, as the source recorded it.
 *
 * Three things are kept apart that are easy to collapse and expensive to
 * un-collapse:
 *
 *  - `rawResponse` is what the student actually answered — «C». It is not a
 *    mark and cannot become one without knowing both the answer key and what
 *    the question is worth.
 *  - `isCorrect` is a judgement, and it is a THREE-state one: true, false, or
 *    null for "the source does not say and nothing here may guess".
 *  - `pointsEarned` is a mark. Null means nobody has determined one yet;
 *    the string "0" means a determined zero. Those are different facts about a
 *    student and the whole model exists to keep them different (§18).
 *
 * An unanswered question is not a wrong answer. A wrong answer earns zero once
 * the question has a declared worth; an unanswered one stays unresolved until
 * somebody decides what it means, and it never becomes an absence on its own —
 * an absence is an event the teacher records, not an inference from a blank
 * cell (§68, §69).
 */
final readonly class CanonicalResult
{
    /**
     * @param  string|null  $pointsEarned  Decimal string, or null for "not determined". Never a float.
     * @param  string|null  $sourceValue  Whatever the cell literally held, for provenance.
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $studentSourceKey,
        public string $itemSourceKey,
        public ?string $rawResponse = null,
        public ?string $pointsEarned = null,
        public ?ResultState $resultState = null,
        public ?bool $isCorrect = null,
        public ?string $sourceValue = null,
        public array $metadata = [],
    ) {}

    /**
     * Whether a mark has been determined. Deliberately not `!empty()`: a
     * determined zero is a mark, and treating it as absent is precisely the
     * mistake this whole class is shaped to prevent.
     */
    public function hasDeterminedMark(): bool
    {
        return $this->pointsEarned !== null;
    }

    /**
     * A cell the source left without an answer. Carries no mark, no state, and
     * no opinion about why.
     */
    public function isUnanswered(): bool
    {
        return $this->rawResponse === null && $this->pointsEarned === null;
    }

    /**
     * Resolves the mark once the question's worth is known. Correct earns the
     * full points, incorrect earns a determined zero, and anything the source
     * did not judge stays exactly as unresolved as it was.
     */
    public function resolvedAgainst(string $pointsPossible): self
    {
        if ($this->isCorrect === null) {
            return $this;
        }

        return new self(
            studentSourceKey: $this->studentSourceKey,
            itemSourceKey: $this->itemSourceKey,
            rawResponse: $this->rawResponse,
            pointsEarned: $this->isCorrect ? $pointsPossible : '0',
            resultState: ResultState::Assessed,
            isCorrect: $this->isCorrect,
            sourceValue: $this->sourceValue,
            metadata: $this->metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'student_source_key' => $this->studentSourceKey,
            'item_source_key' => $this->itemSourceKey,
            'raw_response' => $this->rawResponse,
            'points_earned' => $this->pointsEarned,
            'result_state' => $this->resultState?->value,
            'is_correct' => $this->isCorrect,
            'source_value' => $this->sourceValue,
            'metadata' => $this->metadata,
        ];
    }
}
