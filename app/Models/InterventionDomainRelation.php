<?php

namespace App\Models;

/**
 * How an intervention relates to the subject's assessment domains (§7 of the
 * module brief).
 *
 * "None" and "all" are genuinely different statements — "não se dirige a nenhum
 * domínio em particular" versus "dirige-se a todos" — and collapsing both into a
 * null domain_id would lose that. Only Specific carries a domain_id.
 */
enum InterventionDomainRelation: string
{
    case None = 'none';
    case Specific = 'specific';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::None => __('Sem domínio específico'),
            self::Specific => __('Um domínio específico'),
            self::All => __('Todos os domínios'),
        };
    }

    public function requiresDomain(): bool
    {
        return $this === self::Specific;
    }
}
