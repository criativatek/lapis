<?php

namespace App\Models;

enum EnrollmentStatus: string
{
    case Active = 'active';
    case TransferredOut = 'transferred_out';
    case Left = 'left';
    case Concluded = 'concluded';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Inscrito'),
            self::TransferredOut => __('Transferido'),
            self::Left => __('Saiu'),
            self::Concluded => __('Concluído'),
        };
    }
}
