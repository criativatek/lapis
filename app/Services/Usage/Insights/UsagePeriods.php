<?php

namespace App\Services\Usage\Insights;

use App\Support\Usage\UsageCoverage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * As semanas (segunda a domingo) e os meses civis que tocam o período, cada um
 * com o estado de cobertura de docs/usage-insights.md.
 *
 * Estados, por esta ordem de precedência:
 *  - `no_coverage`: nenhum dia do período, nesta semana/mês, tem recolha —
 *    os números são nulos, nunca zero;
 *  - `in_progress`: contém hoje;
 *  - `partial_period`: o período corta-o (só parte dele está no intervalo);
 *  - `partial_coverage`: algum dia do período não tem recolha completa;
 *  - `complete`.
 */
final class UsagePeriods
{
    public const NO_COVERAGE = 'no_coverage';

    public const IN_PROGRESS = 'in_progress';

    public const PARTIAL_PERIOD = 'partial_period';

    public const PARTIAL_COVERAGE = 'partial_coverage';

    public const COMPLETE = 'complete';

    /** @var array<int, string> */
    private const MONTHS = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho',
        7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
    ];

    public function __construct(private readonly UsageCoverage $coverage) {}

    /**
     * @return list<UsagePeriod>
     */
    public function weeks(UsageFilters $filters): array
    {
        $periods = [];

        for ($start = UsageDates::monday($filters->from); $start->lessThanOrEqualTo($filters->to); $start = $start->addWeek()) {
            $periods[] = $this->build(
                $filters,
                $start,
                $start->addDays(6),
                UsageDates::iso($start),
                'Semana de '.UsageDates::short($start),
            );
        }

        return $periods;
    }

    /**
     * @return list<UsagePeriod>
     */
    public function months(UsageFilters $filters): array
    {
        $periods = [];

        for ($start = $filters->from->startOfMonth(); $start->lessThanOrEqualTo($filters->to); $start = $start->addMonthNoOverflow()) {
            $periods[] = $this->build(
                $filters,
                $start,
                $start->endOfMonth()->startOfDay(),
                $start->format('Y-m'),
                self::MONTHS[$start->month].' de '.$start->year,
            );
        }

        return $periods;
    }

    /**
     * Uma semana ou um mês: a parte que está dentro do período e o seu estado.
     */
    private function build(UsageFilters $filters, CarbonImmutable $start, CarbonImmutable $end, string $key, string $label): UsagePeriod
    {
        $from = $start->max($filters->from);
        $to = $end->min($filters->to);

        $days = UsageDates::eachDay($from, $to);
        $covered = array_values(array_filter($days, fn (CarbonInterface $day): bool => $this->coverage->isDayCovered($day)));
        $fullyCovered = count(array_filter($days, fn (CarbonInterface $day): bool => $this->coverage->isDayFullyCovered($day)));

        $status = match (true) {
            $covered === [] => self::NO_COVERAGE,
            $start->lessThanOrEqualTo($filters->today) && $end->greaterThanOrEqualTo($filters->today) => self::IN_PROGRESS,
            ! $from->equalTo($start) || ! $to->equalTo($end) => self::PARTIAL_PERIOD,
            $fullyCovered < count($days) => self::PARTIAL_COVERAGE,
            default => self::COMPLETE,
        };

        return new UsagePeriod(
            key: $key,
            label: $label,
            start: $start,
            end: $end,
            from: $from,
            to: $to,
            status: $status,
            coveredDays: array_map(fn (CarbonInterface $day): string => UsageDates::iso($day), $covered),
        );
    }
}
