<?php

namespace App\Domain\Import\Correction;

/**
 * One student as the source names them — which is not the same as a student.
 *
 * Every field here belongs to the FILE, not to Lapispro. A Plickers card number is
 * a piece of cardboard, not an enrolment; a name in a spreadsheet is a string
 * somebody typed. Keeping them in their own object is what stops provider
 * columns leaking into Enrollment or StudentIdentity, and what makes the
 * matching step an explicit decision rather than an accident of naming.
 *
 * `sourceScore`, `sourceCorrect` and `sourceAnswered` are the source's own
 * summaries. They are carried for reconciliation and shown as reference; they
 * are never marks (§49).
 */
final readonly class CanonicalStudent
{
    /**
     * @param  string  $sourceKey  Identity WITHIN this import only.
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $sourceKey,
        public ?string $externalId = null,
        public ?string $cardNumber = null,
        public ?int $classNumber = null,
        public ?string $displayName = null,
        public ?string $sourceScore = null,
        public ?int $sourceCorrect = null,
        public ?int $sourceAnswered = null,
        public array $metadata = [],
    ) {}

    /**
     * Whether the source itself says this student took part. A row that exists
     * with nothing answered is a row about someone who was not assessed — which
     * is not a zero, and not an absence either (§68, §69).
     */
    public function answeredNothing(): bool
    {
        return $this->sourceAnswered === 0;
    }

    /**
     * The platform's own overall result as a plain number out of 100, or null.
     *
     * `sourceScore` is whatever the export literally held — «55%», «55,5%», «-».
     * This is the one place that reading is turned into something arithmetic can
     * touch, so that no caller has to know that Plickers writes a percent sign
     * and a dash.
     *
     * Null in, null out, and null for anything that does not parse. Never «0»:
     * a platform that reported no score has not reported a zero, and the whole
     * import is built on keeping those apart (§3, §5).
     */
    public function scorePercent(): ?string
    {
        if ($this->sourceScore === null) {
            return null;
        }

        $value = str_replace([' ', '%', ','], ['', '', '.'], trim($this->sourceScore));

        return is_numeric($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_key' => $this->sourceKey,
            'external_id' => $this->externalId,
            'card_number' => $this->cardNumber,
            'class_number' => $this->classNumber,
            'display_name' => $this->displayName,
            'source_score' => $this->sourceScore,
            'source_correct' => $this->sourceCorrect,
            'source_answered' => $this->sourceAnswered,
            'metadata' => $this->metadata,
        ];
    }
}
