<?php

namespace App\Services\Usage\Insights;

use App\Support\Usage\UsageCoverage;
use Carbon\CarbonInterface;

/**
 * Os dias de um período em que havia recolha. Para uma pessoa, só contam os
 * dias em que a conta já existia: antes disso não havia nada a observar.
 */
final class ObservedDays
{
    /** @var list<string> */
    private array $covered;

    public function __construct(UsageCoverage $coverage, UsageFilters $filters)
    {
        $this->covered = array_map(
            fn (CarbonInterface $day): string => UsageDates::iso($day),
            $coverage->coveredDaysIn($filters->from, $filters->to),
        );
    }

    /** Dias com recolha, independentemente de quem seja. */
    public function count(): int
    {
        return count($this->covered);
    }

    /** Dias com recolha em que a conta já existia. */
    public function forAccount(CarbonInterface $createdAt): int
    {
        $created = UsageDates::iso($createdAt);

        return count(array_filter($this->covered, fn (string $day): bool => $day >= $created));
    }

    /** Semanas (segunda a domingo) com pelo menos um dia observado dessa conta. */
    public function weeksForAccount(CarbonInterface $createdAt): int
    {
        $created = UsageDates::iso($createdAt);
        $weeks = [];

        foreach ($this->covered as $day) {
            if ($day >= $created) {
                $weeks[UsageDates::iso(UsageDates::monday(UsageDates::day($day)))] = true;
            }
        }

        return count($weeks);
    }
}
