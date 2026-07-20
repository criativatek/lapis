<?php

namespace App\Models;

/**
 * The state of a single recorded score (§12.5, domain-model.md §5).
 *
 * Never a magic value like -1. `assessed` is the ONLY state that carries a
 * number; every other state means "there is no value here", which is different
 * from "the value is zero".
 *
 * Whether an absence counts as zero or is excluded is NOT decided here — it is a
 * versioned pedagogical rule on the profile (absence_mode). The product owner
 * chose exclude-and-warn (ADR-0004), but a version may say otherwise.
 */
enum ResultState: string
{
    case Pending = 'pending';
    case Assessed = 'assessed';
    case Absent = 'absent';
    case AbsentJustified = 'absent_justified';
    case Exempt = 'exempt';
    case NotApplicable = 'not_applicable';
    case Annulled = 'annulled';
    case UnderReview = 'under_review';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Por avaliar'),
            self::Assessed => __('Avaliado'),
            self::Absent => __('Ausente'),
            self::AbsentJustified => __('Ausência justificada'),
            self::Exempt => __('Dispensado'),
            self::NotApplicable => __('Não aplicável'),
            self::Annulled => __('Anulado'),
            self::UnderReview => __('Em revisão'),
        };
    }

    /**
     * Only an assessed score carries a value. Everything else must leave
     * points_earned NULL — "empty is not zero" (§12.4).
     */
    public function carriesValue(): bool
    {
        return $this === self::Assessed;
    }

    /**
     * Whether this state's item enters the denominator without consulting the
     * profile's absence rule. Absences are deliberately absent from this list:
     * their treatment depends on absence_mode and is decided by the engine.
     */
    public function entersDenominator(): ?bool
    {
        return match ($this) {
            self::Assessed => true,
            // Not a lack of merit — a lack of data. Removed from the fraction
            // entirely, and flagged as a coverage warning (§13.4).
            self::Pending, self::Exempt, self::NotApplicable, self::Annulled, self::UnderReview => false,
            // Decided by the profile version's absence_mode, never assumed.
            self::Absent, self::AbsentJustified => null,
        };
    }

    /**
     * Blocks publishing a period's classification while a score is contested.
     */
    public function blocksPublication(): bool
    {
        return $this === self::UnderReview;
    }
}
