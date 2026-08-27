<?php

namespace App\Domain\Import\Correction;

/**
 * What makes a workbook a Lapispro grid, stated formally.
 *
 * The contract lives in the workbook's DEFINED NAMES — not in a sheet called
 * «Resultados», not in a tab called «Importar_LAPIS». A name is something a
 * teacher types, and recognising a file by one would mean any renamed
 * spreadsheet could claim to be ours (§6). A defined name is structure: it
 * survives a save, it is invisible on screen, and nothing a teacher does in the
 * ordinary course of filling in marks touches it.
 *
 * Deliberately NOT a custom document property. Those live in the same OOXML
 * part as the author and the path the file was saved from, and reading them
 * would mean reopening the rule that keeps that part unread (§17). A defined
 * name is a narrower door.
 *
 * WHAT THE FILE IS ALLOWED TO SAY: which instrument, which class, which item
 * each column belongs to, which enrolment each row belongs to. That is
 * IDENTITY, and identity is a claim. Everything that is TRUTH — the cotação,
 * the domain, the weights, whether the teacher may touch any of it — is read
 * from the database afterwards. A workbook is never an authorisation (§6).
 */
final class LapisGridContract
{
    /** The marker. Its presence is what says «this is one of ours». */
    public const NAME_MARKER = 'LAPIS_GRID';

    public const NAME_VERSION = 'LAPIS_GRID_VERSION';

    public const NAME_INSTRUMENT = 'LAPIS_GRID_INSTRUMENT';

    public const NAME_CLASS = 'LAPIS_GRID_CLASS';

    /** One per item column: LAPIS_ITEM_D => the item's ULID. */
    public const NAME_ITEM_PREFIX = 'LAPIS_ITEM_';

    public const MARKER = 'correction-grid';

    /**
     * The only version this build writes and the only one it reads.
     *
     * A file stamped with anything else is refused rather than interpreted: a
     * grid from a future version may put a column somewhere this code does not
     * expect, and reading it anyway is how marks land on the wrong item (§14).
     */
    public const VERSION = '1';

    /** The single visible sheet. Named for the teacher, never used to recognise. */
    public const SHEET = 'Resultados';

    /** Hidden, and carrying the enrolment identity for each row. */
    public const COLUMN_ENROLLMENT = 'A';

    public const COLUMN_NUMBER = 'B';

    public const COLUMN_NAME = 'C';

    /** Items start here. */
    public const FIRST_ITEM_COLUMN = 'D';

    public const HEADER_ROW = 1;

    public const FIRST_DATA_ROW = 2;

    /**
     * A defined name's stored value, as a plain string.
     *
     * PhpSpreadsheet keeps a constant defined name as the formula text of a
     * string literal, so `="correction-grid"` comes back as `"correction-grid"`
     * — quotes included. This unwraps exactly that and nothing else: anything
     * which is not a simple quoted literal is not a value this contract wrote,
     * and returns null rather than being coerced into one.
     *
     * Nothing is evaluated. This is string unwrapping, not formula execution.
     */
    public static function unwrap(?string $stored): ?string
    {
        if ($stored === null) {
            return null;
        }

        $value = trim($stored);
        $value = ltrim($value, '=');

        if (preg_match('/^"([^"]*)"$/', $value, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1]);

        return $value === '' ? null : $value;
    }

    /**
     * The literal to store for a value. The inverse of unwrap().
     */
    public static function wrap(string $value): string
    {
        // Quotes cannot survive a quoted literal and nothing this contract
        // writes contains any: ULIDs are alphanumeric and the marker is fixed.
        return '="'.str_replace('"', '', $value).'"';
    }

    public static function itemName(string $columnLetter): string
    {
        return self::NAME_ITEM_PREFIX.strtoupper($columnLetter);
    }

    /**
     * The column a LAPIS_ITEM_* name refers to, or null when the name is not
     * one of ours.
     */
    public static function columnOf(string $definedName): ?string
    {
        $name = strtoupper($definedName);

        if (! str_starts_with($name, self::NAME_ITEM_PREFIX)) {
            return null;
        }

        $letter = substr($name, strlen(self::NAME_ITEM_PREFIX));

        return preg_match('/^[A-Z]{1,3}$/', $letter) === 1 ? $letter : null;
    }
}
