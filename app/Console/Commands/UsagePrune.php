<?php

namespace App\Console\Commands;

use App\Models\UsageDailyRollup;
use App\Models\UsageEvent;
use App\Models\User;
use App\Support\Retention\RetentionPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Conservação das métricas de utilização (docs/usage-insights.md, «Conservação»):
 * apaga `usage_events` e `usage_daily_rollups` mais antigos que
 * `retention.usage_metrics_days`, e, como rede de segurança, tudo o que seja de
 * quem foi anonimizado ou se opôs à recolha.
 *
 * As datas de primeira observação (`usage_user_states`) NÃO são tocadas: ficam
 * enquanto a conta existir.
 */
class UsagePrune extends Command
{
    protected $signature = 'usage:prune';

    protected $description = 'Delete usage metrics older than the retention window (and those of anonymised or opted-out users)';

    public function handle(RetentionPolicy $retention): int
    {
        $cutoff = now()->startOfDay()->subDays($retention->usageMetricsDays())->toDateString();

        $events = $this->deleteInChunks(UsageEvent::query()->where('occurred_on', '<', $cutoff));
        $rollups = $this->deleteInChunks(UsageDailyRollup::query()->where('day', '<', $cutoff));

        $excluded = User::query()
            ->where(fn ($query) => $query->whereNotNull('anonymized_at')->orWhereNotNull('usage_opted_out_at'))
            ->select('id');

        $events += $this->deleteInChunks(UsageEvent::query()->whereIn('user_id', $excluded));
        $rollups += $this->deleteInChunks(UsageDailyRollup::query()->whereIn('user_id', $excluded));

        $this->info("{$events} evento(s) e {$rollups} agregado(s) de utilização removido(s).");

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
