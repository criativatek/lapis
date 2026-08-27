<?php

namespace App\Domain\Assessment;

/**
 * A classification proposal already translated into the profile's own scale.
 *
 * The engine produces a normalized percentage; what a teacher confirms is a
 * classification ON A SCALE — a 4, a 16, an 80%, a "Bom". Those are different
 * numbers with different meanings, and showing the percentage where the scale
 * value belongs invites the teacher to confirm something the profile never said.
 *
 * `state` distinguishes the three ways a proposal can be absent, which the UI
 * must never collapse into one:
 *
 *  - NoResult      nothing to compute (no elements). "—", never a zero and
 *                  never the lowest level of the scale (§9 rule 5).
 *  - Unconfigured  there is a result, but this scale has no bands to translate
 *                  it with. Lapispro does not invent thresholds (§10.4, CLAUDE.md
 *                  open question 1) — the teacher assigns the level.
 *  - Resolved      the profile's scale answered.
 */
final readonly class ScaleProposal
{
    public const RESOLVED = 'resolved';

    public const UNCONFIGURED = 'unconfigured';

    public const NO_RESULT = 'no_result';

    public function __construct(
        /** Already formatted for reading: "4", "Bom", "80". Null unless resolved. */
        public ?string $value,
        public string $state,
        /** Whether `value` is a percentage, so the UI knows to append "%" — and not to on a 1-5. */
        public bool $isPercentage = false,
    ) {}

    public static function noResult(): self
    {
        return new self(null, self::NO_RESULT);
    }

    public static function unconfigured(): self
    {
        return new self(null, self::UNCONFIGURED);
    }

    public function isResolved(): bool
    {
        return $this->state === self::RESOLVED;
    }

    /**
     * @return array{value: string|null, state: string, is_percentage: bool}
     */
    public function toPayload(): array
    {
        return [
            'value' => $this->value,
            'state' => $this->state,
            'is_percentage' => $this->isPercentage,
        ];
    }
}
