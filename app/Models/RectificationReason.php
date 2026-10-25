<?php

namespace App\Models;

/**
 * Why a published classification was rectified.
 *
 * The first four say enough on their own; `Other` does not, so it demands a
 * written note — a rectification whose reason is "other" and which explains
 * nothing is exactly what rectification exists to prevent.
 */
enum RectificationReason: string
{
    case EntryError = 'entry_error';
    case CalculationError = 'calculation_error';
    case ReviewOrComplaint = 'review_or_complaint';
    case CouncilDecision = 'council_decision';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::EntryError => __('Erro de lançamento'),
            self::CalculationError => __('Erro de cálculo ou de dados'),
            self::ReviewOrComplaint => __('Revisão ou reclamação'),
            self::CouncilDecision => __('Decisão do conselho'),
            self::Other => __('Outro'),
        };
    }

    /** Only «Outro» does not explain itself. */
    public function requiresNote(): bool
    {
        return $this === self::Other;
    }
}
