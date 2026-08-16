<?php

namespace App\Support\Assessment;

use App\Models\AcademicPeriod;

/**
 * «O que preciso de melhorar no próximo…» — in the terms of the calendar the
 * class actually has.
 *
 * A year of two semesters has no «próximo período», and the last moment of any
 * year has no next anything: asking a student in the 2.º Semestre what they will
 * improve «no próximo período» names something that does not exist, and asking
 * it in the final one points at a year that is over.
 *
 * The wording is decided from the AcademicPeriods themselves — the next one by
 * sequence, and its own `kind`, which is the abstraction that already knows a
 * semester from a term from a module. Never from how many there are, and never
 * from reading a label.
 *
 * WHAT IS NOT DECIDED HERE is which question this is. That is `role =
 * improvement`, stored, and nothing about the sentence changes it. The prompt is
 * contextualised on the way to the screen; the question, its identity and every
 * answer already given stay exactly as they are.
 */
final readonly class ImprovementPrompt
{
    /**
     * Every sentence this app has authored for the improvement question — the
     * current one and the second-person one it replaced.
     *
     * A prompt outside this list is one a teacher wrote, and theirs is the one
     * that shows. Compared whole, never by pattern.
     *
     * @var list<string>
     */
    private const AUTHORED = [
        'O que preciso de melhorar no próximo período?',
        'O que precisas de melhorar no próximo período?',
    ];

    /** Asked in the last moment of the year, where there is no next one to name. */
    public const CLOSING = 'O que preciso de continuar a melhorar?';

    public static function forPeriod(string $stored, ?AcademicPeriod $next): string
    {
        if (! in_array($stored, self::AUTHORED, true)) {
            return $stored;
        }

        if ($next === null) {
            return self::CLOSING;
        }

        // «Semestre» → «no próximo semestre», «Período» → «no próximo período»,
        // and whatever else a school configures.
        return sprintf('O que preciso de melhorar no próximo %s?', mb_strtolower($next->kind->label()));
    }

    /**
     * The moment that follows this one in its own year, or nothing.
     */
    public static function nextAfter(AcademicPeriod $period): ?AcademicPeriod
    {
        return AcademicPeriod::query()
            ->where('academic_year_id', $period->academic_year_id)
            ->where('sequence', '>', $period->sequence)
            ->orderBy('sequence')
            ->first();
    }
}
