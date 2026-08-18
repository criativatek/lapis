<?php

namespace App\Services\Reporting\Narrative;

/**
 * The deterministic sentence layer (§42).
 *
 * ONE PLACE THAT KNOWS HOW TO SAY THINGS IN pt-PT, so that string concatenation
 * never spreads into controllers, composers or Vue components. Everything here
 * is pure: same input, same sentence, every time — which is what makes a
 * generated report reproducible and testable at all.
 *
 * WHAT IT GETS RIGHT AND WHY IT MATTERS. «1 alunos», «26 aluno», «66.4%» and
 * «Escrita, Leitura, Oralidade» are the four tells that a document was
 * assembled by a machine, and a teacher who spots one stops trusting the rest.
 * So: agreement is handled, the decimal separator is a comma, and a list ends
 * in «e» without a comma before it, as Portuguese does.
 *
 * NO INTERPRETATION LIVES HERE. This turns facts into sentences. It never
 * decides which facts, never adds a qualifier the data did not carry, and never
 * softens or sharpens a number.
 */
class Phrase
{
    /**
     * «26 alunos», «1 aluno», «nenhum aluno».
     *
     * The zero case takes a word rather than a digit because «0 alunos
     * obtiveram classificação positiva» reads as a result and «nenhum aluno
     * obteve classificação positiva» reads as a fact — and where zero means
     * «não há dados» the caller should not be using this at all (§41).
     */
    public static function count(int $number, string $singular, string $plural, string $none = 'nenhum'): string
    {
        return match (true) {
            $number === 0 => $none.' '.$singular,
            $number === 1 => '1 '.$singular,
            default => $number.' '.$plural,
        };
    }

    /** «26 alunos» / «1 aluno». */
    public static function students(int $number): string
    {
        return self::count($number, 'aluno', 'alunos');
    }

    /** «18 registos» / «1 registo». */
    public static function records(int $number): string
    {
        return self::count($number, 'registo', 'registos');
    }

    /**
     * A canonical decimal as Portuguese writes it: comma, and no trailing
     * zeros that the rest of the application does not show either.
     *
     * Returns null for null. Absence is not «0,0» (§41).
     */
    public static function number(int|float|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = is_string($value) ? $value : (string) $value;

        if (! is_numeric($text)) {
            return null;
        }

        // Trim to the precision the rest of the app shows, then drop trailing
        // zeros: «66,40» and «66,4» are the same number and only one of them
        // looks like a person wrote it.
        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        if ($text === '' || $text === '-') {
            $text = '0';
        }

        return str_replace('.', ',', $text);
    }

    /** «66,4%», or null when there is no figure. */
    public static function percentage(int|float|string|null $value): ?string
    {
        $number = self::number($value);

        return $number === null ? null : $number.'%';
    }

    /**
     * «a Leitura», «a Leitura e a Escrita», «a Leitura, a Escrita e a
     * Oralidade» — no comma before the «e», as Portuguese does not use one.
     *
     * @param  list<string>  $items
     */
    public static function items(array $items, string $conjunction = 'e'): string
    {
        $items = array_values(array_filter($items, fn (string $item) => trim($item) !== ''));

        return match (count($items)) {
            0 => '',
            1 => $items[0],
            2 => $items[0].' '.$conjunction.' '.$items[1],
            default => implode(', ', array_slice($items, 0, -1))
                .' '.$conjunction.' '.$items[count($items) - 1],
        };
    }

    /**
     * Joins clauses into one sentence and finishes it.
     *
     * Empty parts are dropped rather than producing «A turma  obteve.» — the
     * caller is expected to hand over whatever it has, including nothing.
     */
    public static function sentence(?string ...$parts): string
    {
        $clean = array_values(array_filter(
            array_map(fn (?string $part) => trim((string) $part), $parts),
            fn (string $part) => $part !== '',
        ));

        if ($clean === []) {
            return '';
        }

        $text = self::squash(implode(' ', $clean));

        return self::terminate(self::capitalise($text));
    }

    /**
     * Sentences into a paragraph. Empty ones vanish; if all of them do, so does
     * the paragraph — a heading over a blank line is worse than no section.
     *
     * @param  list<string|null>  $sentences
     */
    public static function paragraph(array $sentences): string
    {
        $clean = array_values(array_filter(
            array_map(fn (?string $sentence) => trim((string) $sentence), $sentences),
            fn (string $sentence) => $sentence !== '',
        ));

        return implode(' ', $clean);
    }

    /**
     * Paragraphs into a body. Blank-line separated, because that is what both
     * renderers and the editor treat as a paragraph break.
     *
     * @param  list<string|null>  $paragraphs
     */
    public static function body(array $paragraphs): string
    {
        $clean = array_values(array_filter(
            array_map(fn (?string $paragraph) => trim((string) $paragraph), $paragraphs),
            fn (string $paragraph) => $paragraph !== '',
        ));

        return implode("\n\n", $clean);
    }

    public static function capitalise(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // mb_*, because a sentence may legitimately open with «Á» or «Ó».
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /** Adds a full stop unless the sentence already ends in punctuation. */
    public static function terminate(string $text): string
    {
        $text = rtrim($text);

        if ($text === '') {
            return '';
        }

        return in_array(mb_substr($text, -1), ['.', '!', '?', ':', '»'], true)
            ? $text
            : $text.'.';
    }

    /** Collapses the double spaces that dropping an empty clause leaves behind. */
    protected static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * «de 26 alunos» / «do único aluno» — the denominator, said once and said
     * the same way everywhere.
     */
    public static function outOf(int $total): string
    {
        return $total === 1 ? 'do único aluno' : 'dos '.$total.' alunos';
    }

    /**
     * «24 dos 26 alunos», or «os 26 alunos» when it is all of them.
     *
     * Saying «26 dos 26» is technically true and reads as a hedge; a report
     * that means «todos» should say so.
     */
    public static function outOfTotal(int $part, int $total, string $singular = 'aluno', string $plural = 'alunos'): string
    {
        if ($total > 0 && $part === $total) {
            return $total === 1 ? 'o único '.$singular : 'os '.$total.' '.$plural;
        }

        return $part.' de '.$total.' '.($total === 1 ? $singular : $plural);
    }
}
