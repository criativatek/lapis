<?php

namespace App\Support\Characterisation;

/**
 * Whether an acronym belongs to the national framework or to one school's own
 * vocabulary. The distinction matters because a local acronym may mean
 * different things in two organizations, so it never resolves on its own.
 */
enum AcronymScope: string
{
    case National = 'national';
    case Institutional = 'institutional';

    public function label(): string
    {
        return match ($this) {
            self::National => __('Sigla nacional'),
            self::Institutional => __('Sigla local'),
        };
    }
}
