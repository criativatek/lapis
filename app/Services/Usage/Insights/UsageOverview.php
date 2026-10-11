<?php

namespace App\Services\Usage\Insights;

use App\Models\UsageUserState;
use App\Support\Commercial\CurrentSubscriptions;
use App\Support\Usage\UsageCoverage;
use Carbon\CarbonImmutable;

/**
 * As props de `admin/usage/Overview`.
 *
 * Contas e adesões lêem-se de `users` no momento; a atividade vem do agregado
 * diário. Períodos sem recolha são `null`, nunca zero, e o servidor devolve
 * contagens e denominadores — as percentagens são de quem as mostra, ao lado
 * do denominador.
 */
final class UsageOverview
{
    /** Quantas semanas depois da adesão se mede o regresso (0 = a própria semana). */
    private const COHORT_OFFSETS = 4;

    public function __construct(
        private readonly CurrentSubscriptions $subscriptions,
        private readonly InsightsChrome $chrome,
        private readonly UsagePeriods $periods,
        private readonly UsageActivity $activity,
        private readonly ScopePeople $people,
        private readonly FollowUpSignals $signals,
        private readonly UsageCoverage $coverage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(UsageFilters $filters, CarbonImmutable $now): array
    {
        $scope = new InsightsScope($filters, $this->subscriptions);

        return [
            ...$this->chrome->common($filters),
            'headline' => $this->headline($scope, $filters),
            'weeks' => $this->weeks($scope, $filters),
            'months' => $this->months($scope, $filters),
            'features' => $this->features($scope, $filters),
            'signals' => $this->signalSummary($scope, $now),
            'cohorts' => $this->cohorts($scope, $filters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function headline(InsightsScope $scope, UsageFilters $filters): array
    {
        $startedAt = $this->coverage->startedAt();
        $newAccounts = $this->people->createdBetween($scope, $filters->from, $filters->toExclusive());

        $afterStart = array_filter(
            $newAccounts,
            fn (array $account): bool => $startedAt !== null && $account['created_at']->greaterThanOrEqualTo($startedAt),
        );

        $done = 0;
        if ($afterStart !== []) {
            $done = UsageUserState::query()
                ->whereIn('user_id', array_column($afterStart, 'id'))
                ->whereNotNull('first_useful_record_on')
                ->count();
        }

        $coveredPeriod = $this->coverage->coveredDaysIn($filters->from, $filters->to);

        return [
            'active_7' => $this->window($scope, $filters, 7),
            'active_30' => $this->window($scope, $filters, 30),
            'active_period' => $coveredPeriod === [] ? null : $this->activity->activeUsers($scope, $filters->from, $filters->to),
            'new_accounts' => count($newAccounts),
            'first_useful_record' => [
                'done' => $done,
                'of' => count($afterStart),
                'before_collection' => count($newAccounts) - count($afterStart),
            ],
        ];
    }

    /**
     * @return array{value: int|null, observed_days: int, window_days: int}
     */
    private function window(InsightsScope $scope, UsageFilters $filters, int $days): array
    {
        $from = $filters->today->subDays($days - 1);
        $observed = count($this->coverage->coveredDaysIn($from, $filters->today));

        return [
            'value' => $observed === 0 ? null : $this->activity->activeUsers($scope, $from, $filters->today),
            'observed_days' => $observed,
            'window_days' => $days,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function weeks(InsightsScope $scope, UsageFilters $filters): array
    {
        $rows = $this->activity->activeDayRows($scope, $filters->from, $filters->to);

        return array_map(function (UsagePeriod $week) use ($rows): array {
            $totals = $week->isObserved() ? $this->activity->totalsWithin($rows, $week) : null;

            return [
                'start' => UsageDates::iso($week->start),
                'end' => UsageDates::iso($week->end),
                'status' => $week->status,
                'active_users' => $totals['active_users'] ?? null,
                'active_user_days' => $totals['active_user_days'] ?? null,
                'actions' => $totals['actions'] ?? null,
                'items' => $totals['items'] ?? null,
            ];
        }, $this->periods->weeks($filters));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function months(InsightsScope $scope, UsageFilters $filters): array
    {
        $rows = $this->activity->activeDayRows($scope, $filters->from, $filters->to);

        return array_map(fn (UsagePeriod $month): array => [
            'month' => $month->key,
            'label' => $month->label,
            'status' => $month->status,
            'active_users' => $month->isObserved() ? $this->activity->totalsWithin($rows, $month)['active_users'] : null,
        ], $this->periods->months($filters));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function features(InsightsScope $scope, UsageFilters $filters): array
    {
        // Sem nenhum dia com recolha não há nada a mostrar: zeros seriam mentira.
        if ($this->coverage->coveredDaysIn($filters->from, $filters->to) === []) {
            return [];
        }

        return array_map(fn (array $row): array => [
            'key' => $row['feature']->value,
            'label' => $row['feature']->label(),
            'users' => $row['users'],
            'users_with_actions' => $row['users_with_actions'],
            'actions' => $row['actions'],
            'items' => $row['items'],
            'failures' => $row['failures'],
        ], $this->activity->byFeature($scope, $filters->from, $filters->to));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function signalSummary(InsightsScope $scope, CarbonImmutable $now): array
    {
        ['users' => $users, 'states' => $states] = $this->people->withState($scope);
        $evaluation = $this->signals->evaluate($users, $states, $now);

        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => FollowUpSignals::label($key),
            'description' => FollowUpSignals::description($key),
            'count' => $evaluation->counts[$key] ?? 0,
            'not_evaluated' => $evaluation->notEvaluatedCounts[$key] ?? 0,
        ], FollowUpSignals::enabledKeys());
    }

    /**
     * Coortes por semana de adesão: contas do âmbito criadas no período e
     * DEPOIS do início da recolha (as outras não têm a primeira semana observada).
     *
     * @return list<array<string, mixed>>
     */
    private function cohorts(InsightsScope $scope, UsageFilters $filters): array
    {
        $startedAt = $this->coverage->startedAt();
        if ($startedAt === null) {
            return [];
        }

        $byWeek = [];
        foreach ($this->people->createdBetween($scope, $filters->from, $filters->toExclusive()) as $account) {
            if ($account['created_at']->lessThan($startedAt)) {
                continue;
            }

            $byWeek[UsageDates::iso(UsageDates::monday($account['created_at']))][] = $account['id'];
        }

        if ($byWeek === []) {
            return [];
        }

        ksort($byWeek);
        $firstMonday = UsageDates::day((string) array_key_first($byWeek));
        $lastMonday = UsageDates::day((string) array_key_last($byWeek));
        $horizon = $lastMonday->addWeeks(self::COHORT_OFFSETS + 1)->subDay()->min($filters->today);

        $memberIds = array_merge(...array_values($byWeek));
        $activeByUser = [];
        foreach ($this->activity->activeDayRows($scope, $firstMonday, $horizon, $memberIds) as $row) {
            $activeByUser[$row['user_id']][$row['day']] = true;
        }

        $cohorts = [];
        foreach ($byWeek as $mondayIso => $ids) {
            $monday = UsageDates::day($mondayIso);
            $weeks = [];

            for ($offset = 0; $offset <= self::COHORT_OFFSETS; $offset++) {
                $start = $monday->addWeeks($offset);
                $end = $start->addDays(6);

                if ($start->greaterThan($filters->today)) {
                    $weeks[] = ['offset' => $offset, 'status' => 'not_started', 'returned' => null];

                    continue;
                }

                $returned = 0;
                foreach ($ids as $id) {
                    foreach (UsageDates::eachDay($start, $end) as $day) {
                        if (isset($activeByUser[$id][UsageDates::iso($day)])) {
                            $returned++;

                            break;
                        }
                    }
                }

                $weeks[] = [
                    'offset' => $offset,
                    'status' => $end->greaterThanOrEqualTo($filters->today) ? 'in_progress' : 'complete',
                    'returned' => $returned,
                ];
            }

            $cohorts[] = [
                'week_start' => $mondayIso,
                'week_end' => UsageDates::iso($monday->addDays(6)),
                'size' => count($ids),
                'weeks' => $weeks,
            ];
        }

        return $cohorts;
    }
}
