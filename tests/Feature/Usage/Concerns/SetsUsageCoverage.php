<?php

namespace Tests\Feature\Usage\Concerns;

use App\Support\Usage\UsageEventType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fixa a cobertura da recolha e semeia eventos de utilização, para os testes
 * desta funcionalidade.
 *
 * A migração insere um período de recolha com a hora REAL em que correu, que
 * depende do dia em que a suite corre; cada teste tem de dizer quando a
 * recolha começou, senão a cobertura seria uma bomba-relógio.
 */
trait SetsUsageCoverage
{
    /**
     * @param  string|null  $endedAt  fim do período, ou nulo se em curso
     */
    protected function startUsageCollectionAt(string $instant, ?string $endedAt = null): void
    {
        // A recolha tem de estar ligada para os testes que a exercitam,
        // qualquer que seja o valor por omissão da configuração.
        config(['usage.enabled' => true]);

        DB::table('usage_collection_periods')->delete();
        DB::table('usage_collection_periods')->insert([
            'started_at' => $instant,
            'ended_at' => $endedAt,
        ]);
    }

    /**
     * Insere um evento diretamente, sem passar pelo recorder.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function seedUsageEvent(int $userId, int $organizationId, string $day, UsageEventType $type, array $overrides = []): void
    {
        $kind = $type->kind()->value;

        DB::table('usage_events')->insert(array_merge([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'occurred_at' => $day.' 10:00:00',
            'occurred_on' => $day,
            'feature' => ($overrides['feature'] ?? $type->feature()?->value) ?? 'lessons',
            'event' => $type->value,
            'kind' => $kind,
            'item_count' => $kind === 'action' ? 1 : null,
            'failure_code' => $kind === 'failure' ? 'unexpected' : null,
            'dedupe_key' => hash('sha256', Str::uuid()->toString()),
            'created_at' => $day.' 10:00:00',
        ], $overrides));
    }
}
