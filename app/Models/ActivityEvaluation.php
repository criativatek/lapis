<?php

namespace App\Models;

/**
 * The teacher's overall appreciation of a pedagogical activity (a school
 * trip, a reading club session, a play, ...) — the only rating field for
 * EvidenceKind::Activity.
 */
enum ActivityEvaluation: string
{
    case VeryPositive = 'very_positive';
    case Positive = 'positive';
    case Satisfactory = 'satisfactory';
    case NotVeryPositive = 'not_very_positive';

    public function label(): string
    {
        return match ($this) {
            self::VeryPositive => __('Muito positiva'),
            self::Positive => __('Positiva'),
            self::Satisfactory => __('Satisfatória'),
            self::NotVeryPositive => __('Pouco positiva'),
        };
    }
}
