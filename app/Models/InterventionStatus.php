<?php

namespace App\Models;

enum InterventionStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Concluded = 'concluded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => __('Nova'),
            self::InProgress => __('Em curso'),
            self::Concluded => __('Concluída'),
            self::Cancelled => __('Cancelada'),
        };
    }

    /** A finished intervention — no further status change is offered. */
    public function isClosed(): bool
    {
        return $this === self::Concluded || $this === self::Cancelled;
    }
}
