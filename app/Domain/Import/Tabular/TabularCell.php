<?php

namespace App\Domain\Import\Tabular;

/**
 * One cell of a spreadsheet, as read — never as interpreted.
 *
 * A generic sheet has no agreed meaning, so the only honest thing a reader can
 * produce is what the cell literally contains plus the few facts the FILE ITSELF
 * states about it. Everything pedagogical is decided later, by the teacher.
 *
 * Two of those facts are load-bearing:
 *
 *  - `isPercentage` is true only when the SOURCE said so — a `%` written in a CSV
 *    field, or a number format of `0.00%` in a workbook. A bare `0.75` is never
 *    read as 75%, because a teacher who marks out of one would be astonished
 *    (§24). The distinction cannot be recovered later, so it is captured here.
 *  - `isFormula` is true when the cell holds an expression rather than a value.
 *    LÁPIS never evaluates one; a column of formulas is refused with an
 *    explanation, not computed (§28).
 *
 * `number` holds what a person reading the sheet would see, as a decimal string:
 * a cell storing 0.75 under a percent format reads as «75», and so `number` is
 * «75». That keeps CSV and XLSX saying the same thing about the same sheet,
 * which is the entire point of having one tabular abstraction for both (§40).
 */
final readonly class TabularCell
{
    public function __construct(
        /** Trimmed display text; null when the cell is empty. */
        public ?string $text = null,
        /** The value as a decimal string when it is unambiguously numeric, else null. */
        public ?string $number = null,
        /** Whether the source itself declared this value a percentage. */
        public bool $isPercentage = false,
        /** Whether the cell holds a formula. Never evaluated. */
        public bool $isFormula = false,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * A cell nobody wrote anything in. Not a zero, and not an absence — those are
     * conclusions, and a blank supports neither (§29).
     */
    public function isBlank(): bool
    {
        return $this->text === null;
    }

    public function isNumeric(): bool
    {
        return $this->number !== null;
    }

    /**
     * Text that carries no number: «F», «NR», «Disp.», a comment. Shown to the
     * teacher as something to resolve, never guessed at (§30).
     */
    public function isUnreadableValue(): bool
    {
        return ! $this->isBlank() && ! $this->isNumeric() && ! $this->isFormula;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'number' => $this->number,
            'is_percentage' => $this->isPercentage,
            'is_formula' => $this->isFormula,
        ];
    }
}
