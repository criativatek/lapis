<?php

namespace App\Services\Documents\Pdf;

/**
 * The way an A4 sheet is held.
 *
 * A TYPE, NOT A STRING, because the orientation is needed twice and the two
 * uses must never disagree: dompdf is told `portrait` or `landscape`, and the
 * page-number label is placed from the sheet's real width and height. A caller
 * that passed «landscape» to one and computed the other from portrait numbers
 * would paint «Página 2 / 3» off the right edge of a wide page.
 *
 * Every report so far is portrait. Landscape exists for the class documents
 * that are tables first and prose second.
 */
enum PaperOrientation: string
{
    case Portrait = 'portrait';
    case Landscape = 'landscape';

    /** A4 short edge, in points. */
    private const SHORT_EDGE = 595.28;

    /** A4 long edge, in points. */
    private const LONG_EDGE = 841.89;

    /** The sheet's width as it is held, in points. */
    public function width(): float
    {
        return match ($this) {
            self::Portrait => self::SHORT_EDGE,
            self::Landscape => self::LONG_EDGE,
        };
    }

    /** The sheet's height as it is held, in points. */
    public function height(): float
    {
        return match ($this) {
            self::Portrait => self::LONG_EDGE,
            self::Landscape => self::SHORT_EDGE,
        };
    }
}
