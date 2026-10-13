<?php

namespace App\Services\Usage\Insights;

use App\Support\Usage\UsageFeature;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Consultas ao agregado diário (`usage_daily_rollups`) dentro de um âmbito.
 *
 * REGRAS QUE ESTE FICHEIRO NÃO QUEBRA
 *  - Só lê o agregado (e, por junção, `users` e `organizations`). Nunca
 *    `usage_events` em bruto, nunca tabelas pedagógicas.
 *  - As datas filtram-se SEMPRE por intervalo (`day >= from and day < to+1`),
 *    nunca com `whereDate()`: em MySQL isso mata o índice.
 *  - «Ativo» = `views > 0 or actions > 0`. Falhas não contam.
 *  - Um utilizador conta uma só vez, mesmo que atue em várias organizações.
 */
final class UsageActivity
{
    /**
     * Quantos utilizadores distintos tiveram uma interação elegível no intervalo.
     */
    public function activeUsers(InsightsScope $scope, CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) $this->active($this->interval($scope->rollups(), $from, $to))
            ->distinct()
            ->count('r.user_id');
    }

    /**
     * Pares (dia, utilizador) com atividade, com os totais desse dia.
     *
     * @param  list<int>|null  $userIds  restringe a estas pessoas
     * @return list<array{day: string, user_id: int, actions: int, items: int}>
     */
    public function activeDayRows(InsightsScope $scope, CarbonInterface $from, CarbonInterface $to, ?array $userIds = null): array
    {
        $query = $this->interval($scope->rollups(), $from, $to);

        if ($userIds !== null) {
            $query->whereIn('r.user_id', $userIds);
        }

        $rows = $query
            ->selectRaw('r.day as day, r.user_id as user_id, sum(r.views) as views, sum(r.actions) as actions, sum(r.items) as items')
            ->groupBy('r.day', 'r.user_id')
            ->get()
            ->filter(fn (object $row): bool => (int) $row->views > 0 || (int) $row->actions > 0)
            ->map(fn (object $row): array => [
                'day' => substr((string) $row->day, 0, 10),
                'user_id' => (int) $row->user_id,
                'actions' => (int) $row->actions,
                'items' => (int) $row->items,
            ]);

        return array_values($rows->all());
    }

    /**
     * Totais por semana ou mês dos pares ativos.
     *
     * @param  list<array{day: string, user_id: int, actions: int, items: int}>  $rows
     * @return array{active_users: int, active_user_days: int, actions: int, items: int}
     */
    public function totalsWithin(array $rows, UsagePeriod $period): array
    {
        $users = [];
        $days = 0;
        $actions = 0;
        $items = 0;

        foreach ($rows as $row) {
            if (! $period->contains($row['day'])) {
                continue;
            }

            $users[$row['user_id']] = true;
            $days++;
            $actions += $row['actions'];
            $items += $row['items'];
        }

        return ['active_users' => count($users), 'active_user_days' => $days, 'actions' => $actions, 'items' => $items];
    }

    /**
     * A atividade por funcionalidade. `failures` conta todas as falhas, mesmo
     * de quem não foi «ativo» nessa funcionalidade.
     *
     * @return list<array{feature: UsageFeature, users: int, users_with_actions: int, actions: int, items: int, items_created: int, items_changed: int, items_unchanged: int, failures: int}>
     */
    public function byFeature(InsightsScope $scope, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->interval($scope->rollups(), $from, $to)
            ->selectRaw('r.feature as feature, r.user_id as user_id, sum(r.views) as views, sum(r.actions) as actions, sum(r.items) as items, sum(r.items_created) as items_created, sum(r.items_changed) as items_changed, sum(r.items_unchanged) as items_unchanged, sum(r.failures) as failures')
            ->groupBy('r.feature', 'r.user_id')
            ->get();

        $result = [];

        foreach (UsageFeature::cases() as $feature) {
            $mine = $rows->filter(fn (object $row): bool => $row->feature === $feature->value);

            $result[] = [
                'feature' => $feature,
                'users' => $mine->filter(fn (object $row): bool => (int) $row->views > 0 || (int) $row->actions > 0)->count(),
                'users_with_actions' => $mine->filter(fn (object $row): bool => (int) $row->actions > 0)->count(),
                'actions' => (int) $mine->sum(fn (object $row): int => (int) $row->actions),
                'items' => (int) $mine->sum(fn (object $row): int => (int) $row->items),
                'items_created' => (int) $mine->sum(fn (object $row): int => (int) $row->items_created),
                'items_changed' => (int) $mine->sum(fn (object $row): int => (int) $row->items_changed),
                'items_unchanged' => (int) $mine->sum(fn (object $row): int => (int) $row->items_unchanged),
                'failures' => (int) $mine->sum(fn (object $row): int => (int) $row->failures),
            ];
        }

        return $result;
    }

    /**
     * Métricas por utilizador no intervalo.
     *
     * @param  list<int>|null  $userIds  restringe a estas pessoas
     * @return array<int, UserActivity> indexado por user_id; quem não tem linhas não aparece
     */
    public function perUser(InsightsScope $scope, CarbonInterface $from, CarbonInterface $to, ?array $userIds = null): array
    {
        $query = $this->interval($scope->rollups(), $from, $to);

        if ($userIds !== null) {
            $query->whereIn('r.user_id', $userIds);
        }

        return $this->summarise($this->rows($query));
    }

    /**
     * O mesmo que `perUser`, mas sobre TODA a atividade de uma pessoa (todas as
     * organizações e segmentos), sem filtros de âmbito: o detalhe de uma pessoa.
     *
     * @return array{rows: list<RollupRow>, activity: UserActivity}
     */
    public function ofUser(int $userId, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->rows(DB::table('usage_daily_rollups as r')
            ->where('r.user_id', $userId)
            ->where('r.day', '>=', UsageDates::iso($from))
            ->where('r.day', '<', UsageDates::iso($to->addDay())));

        return [
            'rows' => $rows,
            'activity' => $this->summarise($rows)[$userId] ?? UserActivity::none(),
        ];
    }

    /**
     * O último dia de atividade de cada pessoa, sobre tudo o que o âmbito tem
     * (não só o período). Não é o último login.
     *
     * @param  list<int>|null  $userIds
     * @return array<int, string> user_id => `Y-m-d`
     */
    public function lastActivity(InsightsScope $scope, ?array $userIds = null): array
    {
        $query = $this->active($scope->rollups());

        if ($userIds !== null) {
            $query->whereIn('r.user_id', $userIds);
        }

        return $query
            ->selectRaw('r.user_id as user_id, max(r.day) as last_day')
            ->groupBy('r.user_id')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->user_id => substr((string) $row->last_day, 0, 10)])
            ->all();
    }

    /**
     * O último dia de atividade de uma pessoa em TODAS as organizações.
     */
    public function lastActivityOf(int $userId): ?string
    {
        $day = DB::table('usage_daily_rollups as r')
            ->where('r.user_id', $userId)
            ->where(fn (Builder $inner) => $inner->where('r.views', '>', 0)->orWhere('r.actions', '>', 0))
            ->max('r.day');

        return is_string($day) ? substr($day, 0, 10) : null;
    }

    /**
     * @param  list<RollupRow>  $rows
     * @return array<int, UserActivity>
     */
    public function summarise(array $rows): array
    {
        $perUser = [];

        foreach ($rows as $row) {
            $perUser[$row->userId] ??= ['days' => [], 'features' => [], 'actions' => 0, 'items' => 0, 'failures' => 0];
            $perUser[$row->userId]['actions'] += $row->actions;
            $perUser[$row->userId]['items'] += $row->items;
            $perUser[$row->userId]['failures'] += $row->failures;

            if ($row->isActive()) {
                $perUser[$row->userId]['days'][$row->day] = true;
                $perUser[$row->userId]['features'][$row->feature] = true;
            }
        }

        $result = [];

        foreach ($perUser as $userId => $data) {
            $days = array_keys($data['days']);
            sort($days);

            $weeks = [];
            foreach ($days as $day) {
                $weeks[UsageDates::iso(UsageDates::monday(UsageDates::day($day)))] = true;
            }

            $features = [];
            foreach (UsageFeature::cases() as $feature) {
                if (isset($data['features'][$feature->value])) {
                    $features[] = $feature;
                }
            }

            $result[$userId] = new UserActivity(
                activeDays: count($days),
                activeWeeks: count($weeks),
                actions: $data['actions'],
                items: $data['items'],
                failures: $data['failures'],
                activeDayList: $days,
                features: $features,
            );
        }

        return $result;
    }

    /**
     * Agrupa por pessoa, dia e funcionalidade e devolve linhas tipadas.
     *
     * @return list<RollupRow>
     */
    private function rows(Builder $query): array
    {
        $rows = $query
            ->selectRaw('r.user_id as user_id, r.day as day, r.feature as feature, sum(r.views) as views, sum(r.actions) as actions, sum(r.items) as items, sum(r.items_created) as items_created, sum(r.items_changed) as items_changed, sum(r.items_unchanged) as items_unchanged, sum(r.failures) as failures')
            ->groupBy('r.user_id', 'r.day', 'r.feature')
            ->get()
            ->map(fn (object $row): RollupRow => new RollupRow(
                userId: (int) $row->user_id,
                day: substr((string) $row->day, 0, 10),
                feature: (string) $row->feature,
                views: (int) $row->views,
                actions: (int) $row->actions,
                items: (int) $row->items,
                failures: (int) $row->failures,
                itemsCreated: (int) $row->items_created,
                itemsChanged: (int) $row->items_changed,
                itemsUnchanged: (int) $row->items_unchanged,
            ));

        return array_values($rows->all());
    }

    private function interval(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query
            ->where('r.day', '>=', UsageDates::iso($from))
            ->where('r.day', '<', UsageDates::iso($to->addDay()));
    }

    private function active(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner->where('r.views', '>', 0)->orWhere('r.actions', '>', 0));
    }
}
