<?php

namespace App\Models;

enum OrganizationType: string
{
    case Personal = 'personal';
    case Institutional = 'institutional';

    public function label(): string
    {
        return match ($this) {
            self::Personal => __('Conta pessoal'),
            self::Institutional => __('Instituição'),
        };
    }
}
