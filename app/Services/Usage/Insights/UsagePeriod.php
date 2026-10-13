<?php

namespace App\Services\Usage\Insights;

use Carbon\CarbonImmutable;

/**
 * Uma semana ou um mês que toca o período consultado.
 *
 * `start`/`end` são os limites completos da semana ou do mês; `from`/`to` a
 * parte que está dentro do período. `coveredDays` são os dias dessa parte que
 * têm recolha (`Y-m-d`).
 */
final readonly class UsagePeriod
{
    /**
     * @param  list<string>  $coveredDays
     */
    public function __construct(
        public string $key,
        public string $label,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $status,
        public array $coveredDays,
    ) {}

    /** Há recolha em algum dia desta parte do período. */
    public function isObserved(): bool
    {
        return $this->status !== UsagePeriods::NO_COVERAGE;
    }

    public function contains(string $day): bool
    {
        return $day >= UsageDates::iso($this->from) && $day <= UsageDates::iso($this->to);
    }
}
