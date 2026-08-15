<?php

namespace App\Domain\Import\Correction;

use App\Domain\Assessment\Bc;

/**
 * How «this section of the test scored N out of M» is written down in LÁPIS.
 *
 * The same decision as OverallResultItem, one level finer. The domain model
 * settled it in §4.2: the item is the one scoring unit, and anything assessed
 * as a whole rather than question by question is an item with an allocation to
 * the domain it belongs to. A section of a test is exactly that.
 *
 * What this shape buys is the multi-domain paper. A Português test whose Grupo I
 * assesses Leitura and whose Grupo III assesses Gramática becomes ONE instrument
 * with four items pointing at four domains — not four instruments, and not a
 * parallel table of domain scores. CalculationEngine already aggregates several
 * items into the same domain, so it needs no changes at all.
 */
final class GroupResultItem
{
    /**
     * Short, because it becomes a column header in the correction grid, and
     * unique within its group by construction. «G1», «G2», …
     */
    public static function codeFor(int $index): string
    {
        return 'G'.($index + 1);
    }

    /**
     * A student's mark for a section: the sum of their marks on its questions.
     *
     * Null when ANY question of the section has no mark. A partial sum would be
     * a lower score presented as a complete one, which is the same mistake as
     * treating a blank as a zero — and the whole import is built on refusing it.
     * What a blank means in Intuitivo has never been demonstrated, so nothing
     * here decides it (§14).
     *
     * @param  array<string, string|null>  $marks  item source key => points earned
     * @param  list<string>  $itemKeys  every question of this section
     */
    public static function sum(array $marks, array $itemKeys): ?string
    {
        $total = '0';

        foreach ($itemKeys as $key) {
            $mark = $marks[$key] ?? null;

            if ($mark === null) {
                return null;
            }

            $total = Bc::add($total, Bc::of($mark));
        }

        return $itemKeys === [] ? null : self::points($total);
    }

    /**
     * One decimal shape for every number this mode produces: four decimals at
     * most, no trailing zeros. «20» and «20.0000» are the same cotação, and
     * letting both exist would make every comparison downstream decide which one
     * it meant.
     */
    public static function points(string $value): string
    {
        return rtrim(rtrim(Bc::round(Bc::of($value), 4, 'half_up'), '0'), '.') ?: '0';
    }
}
