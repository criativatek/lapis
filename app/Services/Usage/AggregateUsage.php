<?php

namespace App\Services\Usage;

use App\Models\UsageDailyRollup;
use App\Models\UsageEvent;
use App\Models\UsageUserState;
use App\Models\User;
use App\Support\Retention\RetentionPolicy;
use App\Support\Usage\UsageCoverage;
use App\Support\Usage\UsageEventKind;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageMilestone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Transforma `usage_events` em agregados diários e estados de pessoa
 * (docs/usage-insights.md, «Cobertura dos dados»). Corre a cada 15 minutos.
 *
 * IDEMPOTENTE: cada dia recalculado apaga os seus agregados e volta a
 * escrevê-los a partir dos eventos, por isso correr duas vezes dá o mesmo.
 * Recalcula sempre ontem, porque um evento de ontem pode ter chegado depois da
 * última passagem.
 *
 * AS DATAS `first_*_on` SÃO PEGAJOSAS: a conservação apaga eventos, e uma data
 * já observada não pode andar para a frente.
 */
class AggregateUsage
{
    private const TABLE_STATE = 'usage_aggregation_state';

    public function __construct(
        private readonly UsageCoverage $coverage,
        private readonly RetentionPolicy $retention,
    ) {}

    /**
     * @return array{days: int, states: int}
     */
    public function run(CarbonImmutable $now): array
    {
        $now = $now->setTimezone((string) config('app.timezone'));
        $today = $now->startOfDay();

        $this->coverage->sync($now);

        $days = 0;
        foreach ($this->daysToRecompute($today) as $day) {
            $this->aggregateDay($day);
            $days++;
        }

        $states = $this->refreshStates($now);

        DB::table(self::TABLE_STATE)->updateOrInsert(['id' => 1], [
            'aggregated_through' => $today->toDateString(),
            'aggregated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        return ['days' => $days, 'states' => $states];
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function daysToRecompute(CarbonImmutable $today): array
    {
        $cutoff = $today->subDays($this->retention->usageMetricsDays());
        $through = DB::table(self::TABLE_STATE)->where('id', 1)->value('aggregated_through');

        if (is_string($through) && $through !== '') {
            $start = CarbonImmutable::parse(substr($through, 0, 10), config('app.timezone'))->startOfDay();
            $start = $start->min($today->subDay());
        } else {
            $earliest = UsageEvent::query()->min('occurred_on');
            $start = is_string($earliest) && $earliest !== ''
                ? CarbonImmutable::parse(substr($earliest, 0, 10), config('app.timezone'))->startOfDay()
                : $today;
        }

        $start = $start->max($cutoff);

        $days = [];
        // addDay() sobre datas: nunca addHours(24), que falha na mudança de hora.
        for ($day = $start; $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
            $days[] = $day;
        }

        return $days;
    }

    private function aggregateDay(CarbonImmutable $day): void
    {
        $date = $day->toDateString();
        // Intervalo [dia, dia seguinte) e NUNCA whereDate(): em MySQL este
        // vira `date(coluna) = ?`, o índice deixa de servir e cada passagem
        // de 15 minutos leria a tabela inteira. O intervalo também é certo
        // em SQLite, guarde ele o dia como 'Y-m-d' ou 'Y-m-d 00:00:00'.
        $next = $day->addDay()->toDateString();

        /** @var array<string, array{user_id: int, organization_id: int, feature: string, views: int, actions: int, useful_actions: int, items: int, items_created: int, items_changed: int, items_unchanged: int, failures: int}> $groups */
        $groups = [];

        DB::table('usage_events')
            ->where('occurred_on', '>=', $date)
            ->where('occurred_on', '<', $next)
            ->select(['id', 'user_id', 'organization_id', 'feature', 'event', 'kind', 'item_count', 'items_created', 'items_changed', 'items_unchanged'])
            ->orderBy('id')
            ->chunkById(2000, function ($rows) use (&$groups): void {
                foreach ($rows as $row) {
                    $key = $row->user_id.'|'.$row->organization_id.'|'.$row->feature;

                    $groups[$key] ??= [
                        'user_id' => (int) $row->user_id,
                        'organization_id' => (int) $row->organization_id,
                        'feature' => (string) $row->feature,
                        'views' => 0,
                        'actions' => 0,
                        'useful_actions' => 0,
                        'items' => 0,
                        'items_created' => 0,
                        'items_changed' => 0,
                        'items_unchanged' => 0,
                        'failures' => 0,
                    ];

                    $kind = UsageEventKind::tryFrom((string) $row->kind);

                    if ($kind === UsageEventKind::View) {
                        $groups[$key]['views']++;
                    } elseif ($kind === UsageEventKind::Failure) {
                        $groups[$key]['failures']++;
                    } elseif ($kind === UsageEventKind::Action) {
                        $groups[$key]['actions']++;
                        $groups[$key]['items'] += (int) $row->item_count;
                        $groups[$key]['items_created'] += (int) $row->items_created;
                        $groups[$key]['items_changed'] += (int) $row->items_changed;
                        $groups[$key]['items_unchanged'] += (int) $row->items_unchanged;

                        if (UsageEventType::tryFrom((string) $row->event)?->isUsefulRecord() === true) {
                            $groups[$key]['useful_actions']++;
                        }
                    }
                }
            });

        DB::transaction(function () use ($date, $next, $groups): void {
            UsageDailyRollup::query()->where('day', '>=', $date)->where('day', '<', $next)->delete();

            foreach (array_chunk(array_values($groups), 500) as $chunk) {
                UsageDailyRollup::query()->insert(array_map(
                    fn (array $group): array => $group + ['day' => $date],
                    $chunk,
                ));
            }
        });
    }

    /**
     * Cria ou atualiza o estado de cada pessoa elegível e apaga o de quem foi
     * anonimizado ou se opôs.
     */
    private function refreshStates(CarbonImmutable $now): int
    {
        UsageUserState::query()
            ->whereIn('user_id', User::query()
                ->where(fn ($query) => $query->whereNotNull('anonymized_at')->orWhereNotNull('usage_opted_out_at'))
                ->select('id'))
            ->delete();

        $touched = 0;

        User::query()
            ->whereNull('anonymized_at')
            ->whereNull('usage_opted_out_at')
            ->chunkById(500, function ($users) use ($now, &$touched): void {
                $ids = $users->pluck('id')->all();

                $available = DB::table('class_teachers')
                    ->whereIn('user_id', $ids)
                    ->groupBy('user_id')
                    ->selectRaw('user_id, MIN(created_at) as since')
                    ->pluck('since', 'user_id');

                /** @var array<string, Collection<int, string|null>> $observed */
                $observed = [];
                foreach (UsageMilestone::cases() as $milestone) {
                    $observed[$milestone->value] = DB::table('usage_events')
                        ->whereIn('user_id', $ids)
                        // Uma reimportação só com alterações não prova que se
                        // adicionaram alunos: sem discriminação (nulo) conta,
                        // com discriminação só se algum foi criado.
                        ->where(fn ($query) => $query->whereNull('items_created')->orWhere('items_created', '>', 0))
                        ->whereIn('event', array_map(fn (UsageEventType $type): string => $type->value, $milestone->events()))
                        ->groupBy('user_id')
                        ->selectRaw('user_id, MIN(occurred_on) as first_on')
                        ->pluck('first_on', 'user_id');
                }

                $existing = UsageUserState::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');

                foreach ($ids as $userId) {
                    $state = $existing->get($userId) ?? new UsageUserState(['user_id' => $userId]);

                    $values = [
                        'class_available_since' => $available->get($userId),
                    ];

                    foreach (UsageMilestone::cases() as $milestone) {
                        $column = $milestone->stateColumn();
                        $stored = $state->{$column}?->toDateString();
                        $seen = $observed[$milestone->value]->get($userId);
                        $seen = is_string($seen) ? substr($seen, 0, 10) : null;

                        // Pegajoso: a primeira data observada nunca anda para a frente.
                        $values[$column] = collect([$stored, $seen])->filter()->min();
                    }

                    $state->forceFill($values);

                    // Só se escreve o que mudou: reescrever todas as pessoas a
                    // cada 15 minutos seriam 96 UPDATE por pessoa e por dia sem
                    // informação nenhuma. `computed_at` é o momento da última
                    // alteração; a hora da agregação está em usage_aggregation_state.
                    if (! $state->exists || $state->isDirty()) {
                        $state->forceFill(['computed_at' => $now])->save();
                        $touched++;
                    }
                }
            });

        return $touched;
    }
}
