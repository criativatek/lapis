<?php

namespace App\Support\Interventions;

use RuntimeException;

/**
 * Two versions of one jurisdiction's law claim the same date.
 *
 * This is a configuration error, never a state the application can be in
 * legitimately: a date is governed by exactly one regime. The registry used to
 * answer it by returning whichever framework happened to be first in the
 * array, which means the legal reading of every record on that date depended
 * on the order somebody wrote a constructor — and would change silently the
 * day that order changed.
 *
 * Failing loudly is the only safe answer. The alternative is not "a sensible
 * default"; it is a legal classification about a child chosen by array order.
 *
 * It throws on a GENUINE overlap only — two applicable versions for the same
 * jurisdiction covering the same day. Versions that abut (one ends
 * 2030-08-31, the next begins 2030-09-01) never overlap, which is why
 * `validUntil` is inclusive across the whole domain.
 */
final class OverlappingLegalFrameworksException extends RuntimeException
{
    /**
     * @param  list<string>  $codes
     */
    public function __construct(string $jurisdiction, string $date, array $codes)
    {
        parent::__construct(sprintf(
            'Mais do que um enquadramento legal aplicável a %s em %s: %s. '.
            'Uma data é regida por exatamente um regime — corrija as vigências antes de continuar.',
            $jurisdiction,
            $date,
            implode(', ', $codes),
        ));
    }
}
