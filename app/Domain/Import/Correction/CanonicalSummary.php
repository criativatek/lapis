<?php

namespace App\Domain\Import\Correction;

/**
 * A number the source worked out for itself.
 *
 * Plickers' «Score 75%», Intuitivo's «Total», a teacher's spreadsheet «Total do
 * domínio». They are kept in their own list, apart from results, for one reason:
 * they are conclusions, and LÁPIS draws its own. CalculationEngine is sovereign
 * (§75); a summary is worth carrying because comparing the two afterwards can
 * catch a misread file, not because it may replace the calculation.
 *
 * A source that weights its questions differently from the instrument will
 * legitimately disagree. That is not an error, and the wording shown to the
 * teacher must not call it one.
 */
final readonly class CanonicalSummary
{
    public const SCOPE_STUDENT = 'student';

    public const SCOPE_ITEM = 'item';

    public const SCOPE_GROUP = 'group';

    public const SCOPE_INSTRUMENT = 'instrument';

    /**
     * @param  string  $scope  One of the SCOPE_* constants — what the number is about.
     * @param  string|null  $subjectSourceKey  Which student/item/group, when the scope needs one.
     * @param  string  $key  What the source called it: 'score', 'correct', 'answered', 'total', …
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $scope,
        public string $key,
        public ?string $subjectSourceKey = null,
        public ?string $value = null,
        public ?string $unit = null,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'key' => $this->key,
            'subject_source_key' => $this->subjectSourceKey,
            'value' => $this->value,
            'unit' => $this->unit,
            'metadata' => $this->metadata,
        ];
    }
}
