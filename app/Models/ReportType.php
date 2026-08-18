<?php

namespace App\Models;

/**
 * The kinds of report the module produces (§3).
 *
 * Deliberately a small, closed set with a common core rather than four separate
 * engines: each case names WHAT the report is about, and everything else — how
 * it is generated, edited, finalized and exported — is the same machinery.
 *
 * No `default` arm anywhere below: adding a case without deciding its scope or
 * its label fails in tests rather than falling back to something plausible.
 */
enum ReportType: string
{
    case SchoolClass = 'class';
    case Student = 'student';
    case Records = 'records';
    case School = 'school';

    public function label(): string
    {
        return match ($this) {
            self::SchoolClass => __('Relatório de turma'),
            self::Student => __('Relatório individual'),
            self::Records => __('Relatório por registos'),
            self::School => __('Relatório de escola'),
        };
    }

    /** What the report is about, in one word, for a listing column. */
    public function subjectLabel(): string
    {
        return match ($this) {
            self::SchoolClass => __('Turma'),
            self::Student => __('Aluno'),
            self::Records => __('Registos'),
            self::School => __('Escola'),
        };
    }

    /**
     * The capability a school must hold to produce this type at all.
     *
     * Three of the four are Base — a descriptive report is part of the core
     * offer (§4). The school-wide one aggregates across teachers, which is what
     * `institution_reports` already means; it is not a new plan structure.
     */
    public function module(): string
    {
        return match ($this) {
            self::SchoolClass, self::Student, self::Records => 'reports',
            self::School => 'institution_reports',
        };
    }
}
