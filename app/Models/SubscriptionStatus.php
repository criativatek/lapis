<?php

namespace App\Models;

enum SubscriptionStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';

    /**
     * Whether this status grants access to the plan's modules at all. Being
     * within the date window is checked separately.
     */
    public function grantsAccess(): bool
    {
        return match ($this) {
            self::Trial, self::Active => true,
            self::Suspended, self::Expired => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Trial => __('Em experiência'),
            self::Active => __('Ativo'),
            self::Suspended => __('Suspenso'),
            self::Expired => __('Expirado'),
        };
    }
}
