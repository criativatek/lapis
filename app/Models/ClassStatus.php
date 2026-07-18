<?php

namespace App\Models;

enum ClassStatus: string
{
    case Preparation = 'preparation';
    case Active = 'active';
    case Closed = 'closed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Preparation => __('Em preparação'),
            self::Active => __('Ativa'),
            self::Closed => __('Encerrada'),
            self::Archived => __('Arquivada'),
        };
    }
}
