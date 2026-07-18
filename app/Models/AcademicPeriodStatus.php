<?php

namespace App\Models;

enum AcademicPeriodStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Rascunho'),
            self::Open => __('Aberto'),
            self::Closed => __('Encerrado'),
            self::Archived => __('Arquivado'),
        };
    }
}
