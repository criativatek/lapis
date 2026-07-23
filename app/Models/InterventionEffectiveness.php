<?php

namespace App\Models;

/**
 * How a review rates the intervention's effect (§14).
 */
enum InterventionEffectiveness: string
{
    case NotEffective = 'not_effective';
    case PartiallyEffective = 'partially_effective';
    case Effective = 'effective';
    case Inconclusive = 'inconclusive';

    public function label(): string
    {
        return match ($this) {
            self::NotEffective => __('Sem efeito'),
            self::PartiallyEffective => __('Parcialmente eficaz'),
            self::Effective => __('Eficaz'),
            self::Inconclusive => __('Inconclusivo'),
        };
    }
}
