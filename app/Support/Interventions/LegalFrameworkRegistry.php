<?php

namespace App\Support\Interventions;

use Carbon\CarbonInterface;

/**
 * Every legal framework LÁPIS knows how to apply.
 *
 * One entry today. A second Portuguese version (the revision approved in 2026
 * with effect announced for 2027) becomes a second entry with its own effective
 * dates once the final text exists — not a rewrite of anything here. Another
 * country becomes an entry too. Neither touches the pedagogical catalogue.
 *
 * No fictitious frameworks: a jurisdiction is only listed once its rules have
 * actually been read and encoded.
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
     * Null here means "LÁPIS has nothing for this place and time" — the caller
     * turns that into NullLegalFramework. It must never become a fallback to
     * some other jurisdiction's framework.
     */
    public function find(string $jurisdiction, CarbonInterface $date): ?InterventionLegalFramework
    {
        $normalised = self::normalise($jurisdiction);

        if ($normalised === null) {
            return null;
        }

        foreach ($this->frameworks as $framework) {
            if ($framework->jurisdiction() === $normalised && $framework->coversDate($date)) {
                return $framework;
            }
        }

        return null;
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
