<?php

namespace App\Models;

/**
 * How the generated sentences are worded (§46).
 *
 * NOT A CREATIVE WRITING SETTING. The tone changes register, not content: the
 * same facts, the same numbers, the same claims. «24 alunos obtiveram
 * classificação positiva» does not become a different number because a school
 * prefers institutional phrasing.
 *
 * Base gets Objective. Pedagogical requires the pedagogical-analysis
 * capability — not because the wording is privileged, but because the sections
 * that make a pedagogical register worth having are.
 */
enum ReportTone: string
{
    /** Plain statement of what the data says. The floor, and the default. */
    case Objective = 'objective';

    /** The same facts in the register of a conselho de turma. */
    case Pedagogical = 'pedagogical';

    /** Third person, formal, for a document that leaves the school. */
    case Formal = 'formal';

    public function label(): string
    {
        return match ($this) {
            self::Objective => __('Objetivo'),
            self::Pedagogical => __('Pedagógico'),
            self::Formal => __('Formal / institucional'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Objective => __('Descrição direta dos dados, sem adjetivação.'),
            self::Pedagogical => __('Os mesmos factos no registo de um conselho de turma.'),
            self::Formal => __('Terceira pessoa, para um documento que sai da escola.'),
        };
    }

    /**
     * The capability a tone needs, or null when anyone may use it.
     *
     * Objective is always available — a Base report is never left without a
     * way to be worded.
     */
    public function module(): ?string
    {
        return match ($this) {
            self::Objective => null,
            self::Pedagogical, self::Formal => 'report_pedagogical_analysis',
        };
    }
}
