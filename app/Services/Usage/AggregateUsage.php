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
use Illuminate\Database\Query\Builder;
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

        // A agregação NÃO liga nem desliga a recolha: só lê o estado. A
        // transição é de `lapis:usage-collection`, no instante em que acontece.
        $days = 0;
        foreach ($this->daysToRecompute($today) as $day) {
            $this->aggregateDay($day);
            $days++;
        }

        $this->forgetExcludedPeople();

        // Com a recolha desligada, os eventos que já existem continuam a ser
        // agregados (não se perde o que foi observado), mas a agregação NÃO lê
        // dados de pessoas (turmas, primeiros passos) nem se declara em dia:
        // os sinais que dependem disso ficam por avaliar.
        if (! $this->coverage->isActive()) {
            // Os dias até hoje foram recalculados nesta passagem, por isso o
            // marcador avança — senão ficava congelado, `usage:prune` deixava
            // de apagar eventos e cada passagem recalculava 180 dias. A HORA da
            // agregação (`aggregated_at`, que diz se os sinais estão em dia)
            // não muda: sem recolha, os sinais não estão em dia.
            DB::table(self::TABLE_STATE)->updateOrInsert(['id' => 1], [
                'aggregated_through' => $today->toDateString(),
            ]);

            return ['days' => $days, 'states' => 0];
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
        // Nunca antes do limite dos AGREGADOS: um dia mais antigo já não pode
        // ter agregados, e recalculá-lo reconstituiria o que a conservação
        // apagou. E nunca um dia de `usage_coverage_gaps`: os eventos desse dia
        // expiraram antes de serem agregados (`usage:prune` apaga-os ao prazo,
        // agregados ou não), e recalculá-lo daria zeros onde houve atividade.
        $cutoff = $today->subDays($this->retention->usageRollupsDays());
        $gaps = array_flip($this->coverage->gaps());
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
            if (! isset($gaps[$day->toDateString()])) {
                $days[] = $day;
            }
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
            // Quem se opôs ou foi anonimizado nunca volta a ter agregados, mesmo
            // que esta passagem tenha começado antes da eliminação.
            ->whereNotIn('user_id', $this->excludedPeople())
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

            // `usage:prune` pode ter marcado o dia como lacuna enquanto os
            // eventos eram lidos: nesse caso o que se leu está incompleto.
            if (DB::table('usage_coverage_gaps')->where('day', '>=', $date)->where('day', '<', $next)->exists()) {
                return;
            }

            foreach (array_chunk(array_values($groups), 500) as $chunk) {
                UsageDailyRollup::query()->insert(array_map(
                    fn (array $group): array => $group + ['day' => $date],
                    $chunk,
                ));
            }
        });
    }

    /**
     * Quem não pode ter métricas: anonimizado, que se opôs à recolha ou que
     * pediu o encerramento da conta (ForgetUsageData já apagou tudo dessa
     * pessoa; isto impede que uma passagem o reconstitua).
     */
    private function excludedPeople(): Builder
    {
        return DB::table('users')
            ->select('id')
            ->where(fn (Builder $query) => $query
                ->whereNotNull('anonymized_at')
                ->orWhereNotNull('usage_opted_out_at')
                ->orWhereNotNull('closure_requested_at'));
    }

    /**
     * Apaga, em cada passagem, qualquer agregado ou estado que reste de quem foi
     * eliminado — fecha a janela em que uma agregação a decorrer em paralelo
     * com `ForgetUsageData` voltaria a escrever o que acabou de ser apagado. E
     * os agregados de um dia que entretanto ficou lacuna (ver aggregateDay()).
     */
    private function forgetExcludedPeople(): void
    {
        UsageDailyRollup::query()->whereIn('user_id', $this->excludedPeople())->delete();
        UsageUserState::query()->whereIn('user_id', $this->excludedPeople())->delete();
        UsageDailyRollup::query()->whereIn('day', DB::table('usage_coverage_gaps')->select('day'))->delete();
    }

    /**
     * Cria ou atualiza o estado de cada pessoa elegível e apaga o de quem não
     * pode ter métricas.
     */
    private function refreshStates(CarbonImmutable $now): int
    {
        UsageUserState::query()->whereIn('user_id', $this->excludedPeople())->delete();

        $touched = 0;

        User::query()
            ->whereNull('anonymized_at')
            ->whereNull('usage_opted_out_at')
            ->whereNull('closure_requested_at')
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
                        // Só o marco dos primeiros alunos depende de haver alunos CRIADOS: uma
                        // reimportação só com alterações não é «primeiros alunos adicionados».
                        ->when($milestone === UsageMilestone::FirstStudentsAdded, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('items_created')->orWhere('items_created', '>', 0)))
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
