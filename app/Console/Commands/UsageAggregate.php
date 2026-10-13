<?php

namespace App\Console\Commands;

use App\Services\Usage\AggregateUsage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Agrega as métricas de utilização (a cada 15 minutos). Idempotente: ver
 * `App\Services\Usage\AggregateUsage`.
 */
class UsageAggregate extends Command
{
    protected $signature = 'usage:aggregate';

    protected $description = 'Aggregate usage events into daily rollups and per-user first-step states';

    public function handle(AggregateUsage $aggregate): int
    {
        $result = $aggregate->run(CarbonImmutable::now());

        $this->info("{$result['days']} dia(s) agregado(s); {$result['states']} estado(s) de utilizador atualizado(s).");

        return self::SUCCESS;
    }
}
