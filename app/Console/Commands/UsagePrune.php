<?php

namespace App\Console\Commands;

use App\Models\UsageDailyRollup;
use App\Models\UsageEvent;
use App\Models\UsageUserState;
use App\Models\User;
use App\Support\Retention\RetentionPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Conservação das métricas de utilização (docs/usage-insights.md, «Conservação»):
 *
 * - eventos detalhados mais antigos que `retention.usage_events_days` — mas só
 *   de dias JÁ AGREGADOS: a agregação recalcula a partir de
 *   `aggregated_through − 1`, e apagar antes disso faria um dia observado
 *   aparecer sem atividade;
 * - agregados diários mais antigos que `retention.usage_rollups_days`;
 * - como rede de segurança, tudo o que seja de quem foi anonimizado ou se opôs
 *   à recolha (eventos, agregados e estado).
 *
 * As datas de primeira observação (`usage_user_states`) de quem continua com
 * conta NÃO são tocadas: ficam enquanto a conta existir.
 */
class UsagePrune extends Command
{
    protected $signature = 'usage:prune';

    protected $description = 'Delete usage metrics older than the retention window (and those of anonymised or opted-out users)';

    public function handle(RetentionPolicy $retention): int
    {
        $today = now()->startOfDay();
        $eventsCutoff = $today->copy()->subDays($retention->usageEventsDays())->toDateString();
        $rollupsCutoff = $today->copy()->subDays($retention->usageRollupsDays())->toDateString();

        // Só dias já agregados: o último dia que a agregação ainda pode voltar
        // a ler é `aggregated_through − 1`. Sem agregação nenhuma, não se apaga
        // nenhum evento — e diz-se.
        $through = DB::table('usage_aggregation_state')->where('id', 1)->value('aggregated_through');
        $events = 0;

        if (is_string($through) && $through !== '') {
            $lastReadable = Carbon::parse(substr($through, 0, 10))->subDay()->toDateString();
            $eventsLimit = min($eventsCutoff, $lastReadable);
            $events = $this->deleteInChunks(UsageEvent::query()->where('occurred_on', '<', $eventsLimit));

            if ($eventsLimit < $eventsCutoff) {
                Log::warning('usage.prune_waiting_for_aggregation', ['aggregated_through' => substr($through, 0, 10)]);
            }
        } elseif (UsageEvent::query()->exists()) {
            Log::warning('usage.prune_waiting_for_aggregation', ['aggregated_through' => null]);
        }

        $rollups = $this->deleteInChunks(UsageDailyRollup::query()->where('day', '<', $rollupsCutoff));

        $excluded = User::query()
            ->where(fn ($query) => $query->whereNotNull('anonymized_at')->orWhereNotNull('usage_opted_out_at'))
            ->select('id');

        $events += $this->deleteInChunks(UsageEvent::query()->whereIn('user_id', $excluded));
        $rollups += $this->deleteInChunks(UsageDailyRollup::query()->whereIn('user_id', $excluded));
        $states = UsageUserState::query()->whereIn('user_id', $excluded)->delete();

        $this->info("{$events} evento(s), {$rollups} agregado(s) e {$states} estado(s) de utilização removido(s).");

        return self::SUCCESS;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function deleteInChunks($query): int
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
