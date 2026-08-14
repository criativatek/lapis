<?php

namespace App\Support\Interventions;

use App\Models\Organization;
use Carbon\CarbonInterface;

/**
 * Picks the legal framework for an organization at a point in time.
 *
 * Two rules carry all the weight here.
 *
 * **The date is the intervention's own `started_on`, never today.** An
 * intervention recorded in 2026 and edited in 2027 stays read under the regime
 * that was in force when it happened. Reaching for the current date would
 * silently reinterpret history the day any law changes.
 *
 * **The compatibility default only covers a jurisdiction that was never
 * stated.** An organization that explicitly names a jurisdiction nobody
 * supports gets NullLegalFramework — never Portugal. Falling back there would
 * apply one country's law to another country's school, which is worse than
 * applying none.
 */
final class LegalFrameworkResolver
{
    public function __construct(private readonly LegalFrameworkRegistry $registry) {}

    /**
     * @param  CarbonInterface  $date  the intervention's started_on
     */
    public function for(Organization $organization, CarbonInterface $date): InterventionLegalFramework
    {
        return $this->resolve($this->jurisdictionFor($organization), $date);
    }

    public function resolve(?string $jurisdiction, CarbonInterface $date): InterventionLegalFramework
    {
        $normalised = LegalFrameworkRegistry::normalise($jurisdiction);

        if ($normalised === null) {
            return new NullLegalFramework;
        }

        return $this->registry->find($normalised, $date) ?? new NullLegalFramework;
    }

    /**
     * The organization's own jurisdiction, or the configured compatibility
     * default while it has never had one.
     *
     * Never derived from locale, timezone, domain or language: a school in
     * Portugal may work in English, and a school abroad may work in Portuguese.
     * The language of the interface says nothing about which law applies.
     */
    public function jurisdictionFor(Organization $organization): ?string
    {
        if (LegalFrameworkRegistry::normalise($organization->jurisdiction) !== null) {
            return $organization->jurisdiction;
        }

        /** @var string|null $default */
        $default = config('lapis.default_jurisdiction');

        return $default;
    }
}
