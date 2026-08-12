<?php

namespace App\Models;

/**
 * How a student took part in a learning activity — the only detail field for
 * EvidenceKind::Participation. Deliberately has no "disruptive" option: a
 * disruptive situation belongs under EvidenceKind::Incident instead.
 */
enum ParticipationLevel: string
{
    case Positive = 'positive';
    case Adequate = 'adequate';
    case Reduced = 'reduced';

    public function label(): string
    {
        return match ($this) {
            self::Positive => __('Positiva'),
            self::Adequate => __('Adequada'),
            self::Reduced => __('Reduzida'),
        };
    }
}
