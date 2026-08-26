<?php

namespace App\Services\Reporting\Narrative;

use App\Domain\Assessment\Bc;

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
     * Small numbers, written out, as prose does.
     *
     * Up to ten, because that is where Portuguese editorial practice puts the
     * line and where the digit starts to look like a data field rather than a
     * sentence. «vinte e seis alunos» would be worse than «26 alunos», so
     * beyond ten the numeral stays.
     *
     * NOT USED FOR PERCENTAGES, LEVELS, DATES OR TABLE CELLS. Those are values,
     * and a value written out is harder to scan, not easier.
     *
     * @var array<int, array{0: string, 1: string}> masculine, feminine
     */
    protected const SPELLED = [
        1 => ['um', 'uma'],
        2 => ['dois', 'duas'],
        3 => ['três', 'três'],
        4 => ['quatro', 'quatro'],
        5 => ['cinco', 'cinco'],
        6 => ['seis', 'seis'],
        7 => ['sete', 'sete'],
        8 => ['oito', 'oito'],
        9 => ['nove', 'nove'],
        10 => ['dez', 'dez'],
    ];

    public static function spelled(int $number, bool $feminine = false): string
    {
        $words = self::SPELLED[$number] ?? null;

        return $words === null ? (string) $number : $words[$feminine ? 1 : 0];
    }

    /**
     * «vinte e seis alunos», «um aluno», «nenhum aluno».
     *
     * The zero case takes a word rather than a digit because «0 alunos
     * obtiveram classificação positiva» reads as a result and «nenhum aluno
     * obteve classificação positiva» reads as a fact — and where zero means
     * «não há dados» the caller should not be using this at all (§41).
     */
    public static function count(
        int $number,
        string $singular,
        string $plural,
        string $none = 'nenhum',
        bool $feminine = false,
    ): string {
        return match (true) {
            $number === 0 => $none.' '.$singular,
            $number === 1 => self::spelled(1, $feminine).' '.$singular,
            default => self::spelled($number, $feminine).' '.$plural,
        };
    }

    /** «26 alunos» / «seis alunos» / «um aluno» / «nenhum aluno». */
    public static function students(int $number): string
    {
        return self::count($number, 'aluno', 'alunos');
    }

    /** «18 registos» / «seis registos» / «um registo». */
    public static function records(int $number): string
    {
        return self::count($number, 'registo', 'registos');
    }

    /**
     * A group of students AND what they did, agreeing in number.
     *
     * THE ZERO CASE IS SINGULAR. «nenhum aluno mantiveram» is the single most
     * visible grammatical failure this module can produce, and it happens
     * because zero feels plural to a counter and reads singular in Portuguese.
     * Handled once, here, rather than in every composer that counts something.
     */
    public static function studentsDid(int $number, string $singularVerb, string $pluralVerb): string
    {
        return self::students($number).' '.($number === 1 || $number === 0 ? $singularVerb : $pluralVerb);
    }

    /**
     * A date as it is written inside a Portuguese sentence: «19 de agosto de
     * 2026». The short form belongs in a footer or a table, not in prose (§5).
     */
    public static function date(\DateTimeInterface $date): string
    {
        // Spelled out rather than taken from the locale: the month name inside a
        // Portuguese sentence is lower-case, and Intl gives it capitalised on
        // some platforms and not on others.
        $months = [
            1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
            'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
        ];

        return ((int) $date->format('j')).' de '.$months[(int) $date->format('n')].' de '.$date->format('Y');
    }

    /**
     * A canonical decimal as Portuguese writes it: comma, one decimal at
     * most, and no trailing zeros that the rest of the application does not
     * show either.
     *
     * ROUNDS — IT DOES NOT ONLY RELOCALE. A value arriving here can still
     * carry the engine's own internal scale (`Bc::SCALE`, ten decimals —
     * «77.766927»), because this is the one place that turns a canonical
     * figure into prose; a caller is never expected to pre-round before
     * calling it. Without this step, every «already wraps it in
     * Phrase::percentage()» call site would be true and the sentence would
     * still read «77,766927%» — a raw value merely relocalised, not
     * rounded, which is the exact bug this method exists to prevent (§1 of
     * the panel review). Rounded on the decimal string itself, through
     * `Bc::round()`, so no float ever touches a figure here either (§24.4).
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

        $text = Bc::round(Bc::of($text), 1, 'half_up');

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

    /**
     * Adds a full stop unless the sentence already ends in punctuation.
     *
     * A closing guillemet is NOT punctuation for this purpose: «assim» needs a
     * stop after it, and Portuguese puts it outside the quotation. What is
     * checked is the last character that is not a closing mark, so a sentence
     * that already ends «assim.» is left alone.
     */
    public static function terminate(string $text): string
    {
        $text = rtrim($text);

        if ($text === '') {
            return '';
        }

        $tail = rtrim($text, '»"\'');

        return in_array(mb_substr($tail === '' ? $text : $tail, -1), ['.', '!', '?', ':'], true)
            ? $text
            : $text.'.';
    }

    /**
     * Tidies the seams that assembling a sentence from parts leaves behind.
     *
     * Two of them, and both are the kind of thing a reader notices immediately
     * even when they could not say what is wrong:
     *
     *  - the double space where an empty clause was dropped;
     *  - the space before a comma, when a clause legitimately begins with one
     *    because it continues the previous one («…, e o mais baixo»).
     *
     * Done here rather than in each composer, so that no sentence anywhere can
     * ship with «Leitura (72,1%) , e o mais baixo».
     */
    protected static function squash(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        // No space before closing punctuation.
        $text = (string) preg_replace('/\s+([,.;:!?%»])/u', '$1', $text);
        // No space after an opening quote.
        $text = (string) preg_replace('/([«(])\s+/u', '$1', $text);

        return trim($text);
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
     * «24 de 26 alunos», or «os 26 alunos» when it is all of them.
     *
     * Saying «26 dos 26» is technically true and reads as a hedge; a report
     * that means «todos» should say so.
     *
     * MASCULINE, because its subject is «aluno». A feminine noun needs
     * count() plus its own sentence — bending this one with an article
     * parameter produces «os 26 turmas» the first time somebody forgets.
     */
    public static function outOfTotal(int $part, int $total, string $singular = 'aluno', string $plural = 'alunos'): string
    {
        if ($total > 0 && $part === $total) {
            return $total === 1 ? 'o único '.$singular : 'os '.$total.' '.$plural;
        }

        return $part.' de '.$total.' '.($total === 1 ? $singular : $plural);
    }

    /**
     * The same proportion, but where the thing being counted is a VERB rather
     * than a noun: «todas foram positivas», «4 de 6 foram positivas».
     *
     * Separate from outOfTotal because «os 6 foram positivas» — which is what
     * you get by passing a verb to a helper that prepends an article — is
     * exactly the kind of small wrongness that makes a document look
     * machine-made. Here the «all» word is the caller's, so gender agrees.
     */
    public static function howMany(
        int $part,
        int $total,
        string $singular,
        string $plural,
        string $all = 'todas',
        string $onlyOne = 'a única',
        bool $feminine = true,
    ): string {
        if ($total === 0) {
            return '';
        }

        if ($part === $total) {
            return $total === 1 ? $onlyOne.' '.$singular : $all.' '.$plural;
        }

        return self::ratio($part, $total, $feminine).' '.($part === 1 ? $singular : $plural);
    }

    /**
     * «quatro de cinco», «12 de 26».
     *
     * A ratio inside a sentence is prose, not a figure, so it follows the same
     * rule as any other small number (§19). Percentages, levels and table cells
     * are figures and stay in digits.
     */
    public static function ratio(int $part, int $total, bool $feminine = false): string
    {
        return self::spelled($part, $feminine).' de '.self::spelled($total, $feminine);
    }
}
