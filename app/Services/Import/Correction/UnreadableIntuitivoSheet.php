<?php

namespace App\Services\Import\Correction;

use RuntimeException;

/**
 * The Intuitivo sheet is not the one this parser understands.
 *
 * Thrown while reading and caught by the parser itself, which turns it into an
 * Error issue on an otherwise empty grid. It never escapes the parser: a file
 * that cannot be read is a refusal the teacher is shown, not an exception
 * somebody has to find in a log.
 *
 * The messages are written for a teacher and quote structure — a column letter,
 * a header — never a student or a mark, because they travel into logs (§26).
 */
class UnreadableIntuitivoSheet extends RuntimeException {}
