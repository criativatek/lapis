<?php

namespace App\Services\Reporting\ClassDocuments;

use RuntimeException;

/**
 * An instrument whose grid cannot be printed: still being prepared, or annulled.
 *
 * Neither has results to put on paper. The caller decides what to do with it —
 * the editor for a draft, a refusal for an annulled one — which is why this is a
 * named exception and not a status code chosen here.
 */
final class InstrumentGridUnavailable extends RuntimeException {}
