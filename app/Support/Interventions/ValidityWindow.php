<?php

namespace App\Support\Interventions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The one implementation of "was this version of the law in force on this
 * date".
 *
 * A pure function over two bounds rather than a trait, deliberately. A trait
 * inherits the using class's narrowed signatures — Portugal's `validFrom()`
 * returns a non-null date, so the null branch became unreachable there and
 * live only elsewhere, which is the shape of rule that gets subtly different
 * in two places. Here the bounds are parameters, both genuinely nullable, and
 * every framework answers through the same arithmetic.
 *
 * ## The boundaries, decided and consistent across the domain
 *
 * **`from` is INCLUSIVE.** A diploma that enters into force on a date is in
 * force ON that date. Decreto-Lei n.º 54/2018 was published on 6 July 2018 and
 * entered into force the day after, so its start is 2018-07-07 and an
 * intervention dated 2018-07-07 is covered by it.
 *
 * **`until` is INCLUSIVE.** It names the LAST day the version was in force,
 * not the first day it was not. An exclusive bound reads as a date on which
 * the law both did and did not apply depending on which framework you ask, and
 * the off-by-one stays invisible until exactly one record lands on it. So two
 * consecutive versions differ by a day: one ends 2030-08-31, the next begins
 * 2030-09-01. They never share a date, and no date falls between them — which
 * is what lets the registry treat any shared date as a configuration error.
 *
 * **An absent bound is an absent bound**, not "not yet in force" and not "no
 * longer in force": null on either side means unbounded on that side.
 *
 * **Comparison is by CALENDAR DAY, never by instant.** `started_on` is a date
 * and legislation commences on a date; comparing times of day would make the
 * answer depend on a timezone nobody chose.
 */
final class ValidityWindow
{
    public static function covers(?CarbonInterface $from, ?CarbonInterface $until, CarbonInterface $date): bool
    {
        $day = self::day($date);

        if ($from !== null && $day->lessThan(self::day($from))) {
            return false;
        }

        if ($until !== null && $day->greaterThan(self::day($until))) {
            return false;
        }

        return true;
    }

    private static function day(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment->toDateString());
    }
}
