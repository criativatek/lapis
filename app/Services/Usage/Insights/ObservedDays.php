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

    /** @var list<string> dias com recolha do princípio ao fim (o dia em que a recolha começou não é um deles) */
    private array $fullyCovered;

    private int $periodDays;

    public function __construct(UsageCoverage $coverage, UsageFilters $filters)
    {
        $days = $coverage->coveredDaysIn($filters->from, $filters->to);

        $this->covered = array_map(fn (CarbonInterface $day): string => UsageDates::iso($day), $days);
        $this->fullyCovered = array_values(array_map(
            fn (CarbonInterface $day): string => UsageDates::iso($day),
            array_filter($days, fn (CarbonInterface $day): bool => $coverage->isDayFullyCovered($day)),
        ));
        $this->periodDays = $filters->days();
    }

    /**
     * A conta foi observada o período INTEIRO: todos os dias com recolha do
     * princípio ao fim e a conta já existente no início de cada um. O dia em
     * que a recolha começou e o dia em que a conta foi criada são parciais.
     */
    public function isCompleteForAccount(CarbonInterface $createdAt): bool
    {
        $created = UsageDates::iso($createdAt);

        return count(array_filter($this->fullyCovered, fn (string $day): bool => $day > $created)) === $this->periodDays;
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
