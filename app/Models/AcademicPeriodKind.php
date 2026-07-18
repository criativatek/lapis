<?php

namespace App\Models;

/**
 * A label for the shape of a period, never a calculation rule. Whether a period
 * is cumulative is a versioned pedagogical rule that lives with the profile
 * (§9.2, domain-model.md §2.2), not here.
 */
enum AcademicPeriodKind: string
{
    case Semester = 'semester';
    case Term = 'term';
    case Trimester = 'trimester';
    case Module = 'module';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Semester => __('Semestre'),
            self::Term => __('Período'),
            self::Trimester => __('Trimestre'),
            self::Module => __('Módulo'),
            self::Other => __('Outro'),
        };
    }
}
