<?php

namespace App\Models;

enum ProfileVersionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Superseded = 'superseded';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Rascunho'),
            self::Active => __('Ativo'),
            self::Superseded => __('Substituído'),
            self::Retired => __('Retirado'),
        };
    }

    /**
     * A frozen version is immutable. Only drafts can be edited; activation freezes.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
