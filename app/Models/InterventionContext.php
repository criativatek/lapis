<?php

namespace App\Models;

/**
 * The transversal area an intervention belongs to — NOT a subject domain
 * (§7 of the module brief). "Escrita" is a domain; "avaliação" is a context.
 * Mixing the two produces false domains like "Transversal" or "Comportamento",
 * which is exactly what this separation exists to prevent.
 *
 * Never persisted: derived from InterventionType::context(), the same way
 * EvidenceKind::group() derives EvidenceInternalGroup for the Registos module.
 * The stable anchor in the database is the intervention's type code.
 */
enum InterventionContext: string
{
    case Learning = 'learning';
    case Evaluation = 'evaluation';
    case StudyAutonomy = 'study_autonomy';
    case AttentionSelfRegulation = 'attention_self_regulation';
    case Behavior = 'behavior';
    case Integration = 'integration';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Learning => __('Aprendizagem'),
            self::Evaluation => __('Avaliação'),
            self::StudyAutonomy => __('Métodos de estudo e autonomia'),
            self::AttentionSelfRegulation => __('Atenção e autorregulação'),
            self::Behavior => __('Comportamento'),
            self::Integration => __('Integração'),
            self::Other => __('Outro'),
        };
    }
}
