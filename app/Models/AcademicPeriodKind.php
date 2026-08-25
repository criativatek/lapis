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

    /**
     * The same label in the plural, for counting them out loud — «2 semestres»,
     * «3 períodos». A year is named by the shape its períodos really have, and
     * that shape is already written down here; nothing needs to be inferred
     * from a label's text.
     */
    public function pluralLabel(): string
    {
        return match ($this) {
            self::Semester => __('Semestres'),
            self::Term => __('Períodos'),
            self::Trimester => __('Trimestres'),
            self::Module => __('Módulos'),
            self::Other => __('Outros'),
        };
    }
}
