<?php

namespace App\Support\Interventions;

use Carbon\CarbonInterface;

/**
 * Every legal framework Lapispro knows how to apply.
 *
 * One entry today. The announced revision of the Portuguese regime becomes a
 * second entry, with its own effective dates, once its final text exists — not
 * a rewrite of anything here. No date for it is written down anywhere in this
 * codebase: a proposal has no commencement until it has one, and guessing at
 * one is how speculative law ends up in front of a teacher. Another country
 * becomes an entry too. Neither touches the pedagogical catalogue.
 *
 * No fictitious frameworks: a jurisdiction is only listed once its rules have
 * actually been read and encoded.
 *
 * Being listed is not the same as being applied. find() skips any version whose
 * status is not applicable, so a draft may be registered — and tested — long
 * before it is law, without ever reaching a teacher.
 */
final class LegalFrameworkRegistry
{
    /** @var list<InterventionLegalFramework> */
    private array $frameworks;

    /**
     * @param  list<InterventionLegalFramework>|null  $frameworks  overridable so tests can register a fictitious jurisdiction without shipping one
     */
    public function __construct(?array $frameworks = null)
    {
        $this->frameworks = $frameworks ?? [
            new PortugalInclusiveEducationFramework,
        ];
    }

    /**
     * The framework in force in a jurisdiction on a date, or null when none is.
     *
     * Null here means "Lapispro has nothing for this place and time" — the caller
     * turns that into NullLegalFramework. It must never become a fallback to
     * some other jurisdiction's framework.
     */
    public function find(string $jurisdiction, CarbonInterface $date): ?InterventionLegalFramework
    {
        $normalised = self::normalise($jurisdiction);

        if ($normalised === null) {
            return null;
        }

        $applicable = [];

        foreach ($this->frameworks as $framework) {
            // The status check comes first and is not negotiable. A draft or a
            // published-but-not-yet-in-force version may legitimately sit in
            // this list — so it can be tested, inspected, and be ready on the
            // day it commences — and must never be handed to a teacher as the
            // law. Putting the guard here rather than at the call sites means a
            // future version cannot leak into the UI because somebody forgot an
            // `if` in a controller.
            if (! $framework->status()->isApplicable()) {
                continue;
            }

            if ($framework->jurisdiction() === $normalised && $framework->coversDate($date)) {
                $applicable[] = $framework;
            }
        }

        // EVERY candidate is collected before one is chosen, deliberately. The
        // obvious loop returns the first match, which means that when two
        // versions both claim a date — a configuration error — the legal
        // reading of every record on that date is decided by the order
        // somebody wrote a constructor, silently, and changes the day that
        // order changes. A date is governed by exactly one regime; anything
        // else is a bug that must be seen, not absorbed.
        if (count($applicable) > 1) {
            throw new OverlappingLegalFrameworksException(
                $normalised,
                $date->toDateString(),
                array_map(fn (InterventionLegalFramework $framework) => $framework->code(), $applicable),
            );
        }

        return $applicable[0] ?? null;
    }

    /**
     * Defensive normalisation of a code that may have been typed or imported:
     * 'pt' and ' PT ' both mean Portugal. This is robustness, not validation —
     * proper ISO 3166-1 alpha-2 checking belongs with the institutional
     * onboarding that will eventually set this field.
     */
    public static function normalise(?string $jurisdiction): ?string
    {
        if ($jurisdiction === null) {
            return null;
        }

        $trimmed = strtoupper(trim($jurisdiction));

        return $trimmed === '' ? null : $trimmed;
    }
}
