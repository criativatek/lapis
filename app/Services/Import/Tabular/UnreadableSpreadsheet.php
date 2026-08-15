<?php

namespace App\Services\Import\Tabular;

use RuntimeException;

/**
 * A file that cannot be read as a table, with a sentence the teacher can act on.
 *
 * Thrown by the readers and caught by the parser, which turns it into an Error
 * issue on the grid. The message is user-facing pt-PT and is expected to say
 * what to DO — «guarde como UTF-8», «exporte os valores» — because «erro ao ler
 * o ficheiro» leaves somebody staring at a spreadsheet with no next step.
 */
class UnreadableSpreadsheet extends RuntimeException {}
