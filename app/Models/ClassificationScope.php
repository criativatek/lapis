<?php

namespace App\Models;

/**
 * Whether a classification is the isolated result of one period or the cumulative
 * result over the year (§6). The two live as separate rows for the same
 * (enrollment, period): the accumulated one reprocesses the year's raw elements,
 * it is not the average of period averages (Q4).
 */
enum ClassificationScope: string
{
    case Period = 'period';
    case Accumulated = 'accumulated';

    public function label(): string
    {
        return match ($this) {
            self::Period => __('Período'),
            self::Accumulated => __('Acumulado'),
        };
    }
}
