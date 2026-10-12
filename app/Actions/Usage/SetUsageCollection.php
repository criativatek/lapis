<?php

namespace App\Actions\Usage;

use App\Models\UsageCollectionPeriod;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * O ÚNICO sítio que liga ou desliga a recolha de métricas de utilização
 * (docs/usage-insights.md, «Ativação e desativação»).
 *
 * A fonte de verdade é `usage_collection_periods`: a recolha está ligada
 * enquanto houver um período aberto. Não há chave de configuração nenhuma a
 * dizer outra coisa — por isso nem `config:cache` nem um processo antigo podem
 * contradizer o que aqui se decide.
 *
 * - `start`: abre um período NESTE instante — não espera pelo primeiro evento
 *   nem pela agregação. É também o instante em que a Política de Privacidade
 *   passa a descrever a recolha (App\Support\Legal\PrivacyVersion).
 * - `stop`: fecha o período aberto NESTE instante. A partir do commit, nenhum
 *   evento é gravado: o recorder só escreve se, no momento da escrita, houver
 *   um período aberto (App\Support\Usage\UsageRecorder).
 * - Repetir não duplica nada: `start` com a recolha ligada e `stop` com ela
 *   desligada não escrevem nem auditam. Dois `start` em simultâneo esbarram no
 *   índice único de `is_open` e só um abre período.
 * - Religar abre um período NOVO: o intervalo desligado fica como «sem
 *   recolha», nunca preenchido.
 *
 * Cada transição fica na auditoria de PLATAFORMA, com o operador e a nota.
 */
class SetUsageCollection
{
    public function __construct(protected AuditLog $audit) {}

    /**
     * @return array{changed: bool, at: CarbonImmutable}
     */
    public function start(User $operator, ?string $note = null): array
    {
        return DB::transaction(function () use ($operator, $note): array {
            $open = $this->openPeriod();

            if ($open !== null) {
                return ['changed' => false, 'at' => CarbonImmutable::instance($open->started_at)];
            }

            $now = CarbonImmutable::now((string) config('app.timezone'));

            $inserted = UsageCollectionPeriod::query()->insertOrIgnore([
                'started_at' => $now->format('Y-m-d H:i:s'),
                'ended_at' => null,
                'is_open' => true,
            ]);

            if ($inserted === 0) {
                // Outro `start` ganhou a corrida: a recolha já está ligada.
                $open = $this->openPeriod();

                return ['changed' => false, 'at' => $open === null ? $now : CarbonImmutable::instance($open->started_at)];
            }

            $this->audit->recordPlatform(
                'admin.usage_collection_started',
                $operator,
                'Recolha de métricas de utilização ligada.',
                ['started_at' => $now->format('Y-m-d H:i:s'), 'note' => $this->clean($note)],
            );

            return ['changed' => true, 'at' => $now];
        });
    }

    /**
     * @return array{changed: bool, at: CarbonImmutable|null}
     */
    public function stop(User $operator, ?string $note = null): array
    {
        return DB::transaction(function () use ($operator, $note): array {
            $now = CarbonImmutable::now((string) config('app.timezone'));

            $closed = UsageCollectionPeriod::query()
                ->where('is_open', true)
                ->update(['ended_at' => $now->format('Y-m-d H:i:s'), 'is_open' => null]);

            if ($closed === 0) {
                return ['changed' => false, 'at' => null];
            }

            $this->audit->recordPlatform(
                'admin.usage_collection_stopped',
                $operator,
                'Recolha de métricas de utilização desligada.',
                ['ended_at' => $now->format('Y-m-d H:i:s'), 'note' => $this->clean($note)],
            );

            return ['changed' => true, 'at' => $now];
        });
    }

    private function openPeriod(): ?UsageCollectionPeriod
    {
        return UsageCollectionPeriod::query()->where('is_open', true)->lockForUpdate()->first();
    }

    private function clean(?string $note): ?string
    {
        return $note === null || trim($note) === '' ? null : trim($note);
    }
}
