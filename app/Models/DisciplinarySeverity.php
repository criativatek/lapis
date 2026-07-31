<?php

namespace App\Models;

/**
 * The severity grade of a disciplinary occurrence (EvidenceKind::Incident) —
 * G2 through G6. G1 ("Comportamento meritório") is a positive-behaviour
 * EvidenceKind of its own, not a severity level here.
 */
enum DisciplinarySeverity: string
{
    case Grade2 = 'g2';
    case Grade3 = 'g3';
    case Grade4 = 'g4';
    case Grade5 = 'g5';
    case Grade6 = 'g6';

    public function label(): string
    {
        return match ($this) {
            self::Grade2 => __('Advertência / Falta de material (G2)'),
            self::Grade3 => __('Perturbação ligeira da aula (G3)'),
            self::Grade4 => __('Indisciplina / Falta de respeito (G4)'),
            self::Grade5 => __('Infração grave (G5)'),
            self::Grade6 => __('Infração muito grave (G6)'),
        };
    }
}
