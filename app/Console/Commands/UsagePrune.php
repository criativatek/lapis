<?php

namespace App\Console\Commands;

use App\Models\UsageDailyRollup;
use App\Models\UsageEvent;
use App\Models\UsageUserState;
use App\Models\User;
use App\Support\Retention\RetentionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Conservação das métricas de utilização (docs/usage-insights.md, «Conservação»).
 * Corre de hora a hora, com a recolha ligada ou desligada.
 *
 * - EVENTOS: apagados quando `occurred_at` fica mais antigo do que
 *   `retention.usage_events_days` — AGREGADOS OU NÃO. O prazo é um limite
 *   efetivo, não um objetivo: nenhum evento sobrevive mais do que o prazo mais
 *   o intervalo entre passagens (uma hora).
 * - LACUNAS: se a agregação esteve parada mais tempo do que o prazo, os dias
 *   cujos eventos expiram sem terem sido agregados ficam em
 *   `usage_coverage_gaps` — só o dia e o motivo, nada do que expirou — e os
 *   agregados parciais desses dias saem. O painel mostra-os como «sem
 *   recolha», nunca como zero, e nenhum sinal os lê. Não há reconstituição: a
 *   agregação nunca volta a calcular um dia de lacuna.
 * - AGREGADOS: apagados quando o dia fica mais antigo do que
 *   `retention.usage_rollups_days` (e as lacunas com ele).
 * - Como rede de segurança, tudo o que seja de quem foi anonimizado, se opôs à
 *   recolha ou pediu o encerramento da conta (eventos, agregados e estado).
 *
 * As datas de primeira observação (`usage_user_states`) de quem continua com
 * conta NÃO são tocadas aqui: ver «Datas dos primeiros passos» na documentação.
 */
class UsagePrune extends Command
{
    protected $signature = 'usage:prune';

    protected $description = 'Delete usage metrics past their retention limit (and those of people excluded from the metrics)';

    public function handle(RetentionPolicy $retention): int
    {
        $now = CarbonImmutable::now((string) config('app.timezone'));
        $eventsCutoff = $now->subDays($retention->usageEventsDays());
        $rollupsCutoff = $now->startOfDay()->subDays($retention->usageRollupsDays())->toDateString();

        $expiring = UsageEvent::query()
            // `occurred_on` primeiro, para o índice por dia servir.
            ->where('occurred_on', '<=', $eventsCutoff->toDateString())
            ->where('occurred_at', '<', $eventsCutoff->format('Y-m-d H:i:s'));

        $gaps = $this->recordGaps(clone $expiring, $now);
        $events = $this->deleteInChunks($expiring);

        $rollups = $this->deleteInChunks(UsageDailyRollup::query()->where('day', '<', $rollupsCutoff));
        DB::table('usage_coverage_gaps')->where('day', '<', $rollupsCutoff)->delete();

        $excluded = User::query()
            ->where(fn ($query) => $query
                ->whereNotNull('anonymized_at')
                ->orWhereNotNull('usage_opted_out_at')
                ->orWhereNotNull('closure_requested_at'))
            ->select('id');

        $events += $this->deleteInChunks(UsageEvent::query()->whereIn('user_id', $excluded));
        $rollups += $this->deleteInChunks(UsageDailyRollup::query()->whereIn('user_id', $excluded));
        $states = UsageUserState::query()->whereIn('user_id', $excluded)->delete();

        $this->info("{$events} evento(s), {$rollups} agregado(s) e {$states} estado(s) de utilização removido(s); {$gaps} dia(s) novo(s) sem dados.");

        return self::SUCCESS;
    }

    /**
     * Marca como lacuna os dias com eventos a expirar que a agregação ainda não
     * fechou. Um dia D está fechado quando `aggregated_through` > D: houve uma
     * passagem depois de D acabar, e essa passagem recalculou D.
     *
     * @param  Builder<UsageEvent>  $expiring
     */
    private function recordGaps(Builder $expiring, CarbonImmutable $now): int
    {
        $through = DB::table('usage_aggregation_state')->where('id', 1)->value('aggregated_through');
        $through = is_string($through) && $through !== '' ? substr($through, 0, 10) : null;

        $days = $expiring
            ->when($through !== null, fn ($query) => $query->where('occurred_on', '>=', $through))
            ->distinct()
            ->pluck('occurred_on')
            ->map(fn (mixed $day): string => substr((string) $day, 0, 10))
            ->unique()
            ->values()
            ->all();

        if ($days === []) {
            return 0;
        }

        $recorded = DB::table('usage_coverage_gaps')->insertOrIgnore(array_map(fn (string $day): array => [
            'day' => $day,
            'reason' => 'expired_unaggregated',
            'recorded_at' => $now->format('Y-m-d H:i:s'),
        ], $days));

        // O que a agregação escreveu desses dias está incompleto.
        UsageDailyRollup::query()->whereIn('day', $days)->delete();

        // Só a contagem e o último dia agregado — nunca o conteúdo.
        Log::warning('usage.coverage_gap_recorded', ['days' => count($days), 'aggregated_through' => $through]);

        return $recorded;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function deleteInChunks(Builder $query): int
    {
        $total = 0;

        do {
            $ids = (clone $query)->limit(5000)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $total += $query->getModel()->newQuery()->whereIn('id', $ids)->delete();
        } while (true);

        return $total;
    }
}
