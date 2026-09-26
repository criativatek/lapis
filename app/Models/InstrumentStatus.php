<?php

namespace App\Models;

enum InstrumentStatus: string
{
    case Draft = 'draft';
    case Prepared = 'prepared';
    case InCorrection = 'in_correction';
    case Completed = 'completed';
    case Published = 'published';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Em preparação'),
            self::Prepared => __('Preparado'),
            self::InCorrection => __('Em correção'),
            self::Completed => __('Concluído'),
            self::Published => __('Publicado'),
            self::Cancelled => __('Anulado'),
            self::Archived => __('Arquivado'),
        };
    }

    /**
     * Whether the calculation engine may read this instrument's scores.
     *
     * `in_correction` DOES enter, with whatever scores exist — that is what lets
     * a teacher watch results build up mid-marking. Students not yet corrected
     * stay `pending` and count for nothing (not even zero).
     */
    public function entersCalculation(): bool
    {
        return match ($this) {
            self::Draft, self::Cancelled => false,
            self::Prepared, self::InCorrection, self::Completed, self::Published, self::Archived => true,
        };
    }
}
