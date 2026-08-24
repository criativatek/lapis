<?php

namespace App\Services\Import\Timetable;

use RuntimeException;

/**
 * A PDF this import cannot read.
 *
 * Every message carried by this exception is written FOR THE TEACHER and shown
 * to them verbatim: an encrypted file, a scan with no text layer, a document
 * that is simply not a timetable. None of these is a bug — they are the normal
 * ways a real upload goes wrong — so none of them may surface as a stack trace
 * or as a generic "erro".
 */
class TimetablePdfException extends RuntimeException {}
