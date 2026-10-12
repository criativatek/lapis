<?php

namespace App\Console\Commands;

use App\Actions\Usage\SetUsageCollection;
use App\Console\Commands\Concerns\ResolvesUsageOperator;
use App\Models\UsageCollectionPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Liga, desliga ou mostra a recolha de métricas de utilização
 * (docs/usage-insights.md, «Ativação e desativação»). `status` só lê.
 *
 * O efeito é imediato em todos os processos: o estado vive na base de dados e
 * é lido em cada pedido, por isso não há `config:cache` a refazer nem workers a
 * reiniciar.
 */
class UsageCollection extends Command
{
    use ResolvesUsageOperator;

    protected $signature = 'lapis:usage-collection
        {action : status, start ou stop}
        {--operator= : Email do administrador com a autorização de controlar a recolha (start e stop)}
        {--note= : Porquê, nas palavras do operador}';

    protected $description = 'Show, start or stop the collection of usage metrics (explicit and audited)';

    public function handle(SetUsageCollection $collection): int
    {
        $action = (string) $this->argument('action');

        if ($action === 'status') {
            return $this->status();
        }

        if (! in_array($action, ['start', 'stop'], true)) {
            $this->components->error('Ação inválida. Use status, start ou stop.');

            return self::FAILURE;
        }

        // A autorização de CONTROLO, não a de consultar o painel.
        $operator = $this->resolveOperator(control: true);

        if ($operator === null) {
            return self::FAILURE;
        }

        $note = $this->option('note');
        $note = is_string($note) ? $note : null;

        if ($action === 'start') {
            $result = $collection->start($operator, $note);

            $this->components->info($result['changed']
                ? 'Recolha ligada a '.$result['at']->format('d/m/Y H:i:s').'. A Política de Privacidade descreve-a a partir deste instante.'
                : 'Sem alterações: a recolha já estava ligada desde '.$result['at']->format('d/m/Y H:i:s').'.');

            return self::SUCCESS;
        }

        $result = $collection->stop($operator, $note);

        $this->components->info($result['changed'] && $result['at'] !== null
            ? 'Recolha desligada a '.$result['at']->format('d/m/Y H:i:s').'. Nenhum evento é gravado a partir de agora; os dados já recolhidos seguem os prazos de conservação.'
            : 'Sem alterações: a recolha já estava desligada.');

        return self::SUCCESS;
    }

    private function status(): int
    {
        $periods = UsageCollectionPeriod::query()->orderBy('started_at')->get();

        if ($periods->isEmpty()) {
            $this->components->info('A recolha nunca foi ligada.');

            return self::SUCCESS;
        }

        $open = $periods->firstWhere('ended_at', null);
        $lastEnd = $periods->max('ended_at');

        $this->components->info($open !== null
            ? 'Recolha LIGADA desde '.$open->started_at->format('d/m/Y H:i:s').'.'
            : 'Recolha DESLIGADA desde '.($lastEnd instanceof \DateTimeInterface ? $lastEnd->format('d/m/Y H:i:s') : '—').'.');

        $this->table(['Início', 'Fim'], $periods->map(fn (UsageCollectionPeriod $period): array => [
            $period->started_at->format('Y-m-d H:i:s'),
            $period->ended_at?->format('Y-m-d H:i:s') ?? '(em curso)',
        ])->all());

        $gaps = DB::table('usage_coverage_gaps')->orderBy('day')->pluck('day')->all();

        if ($gaps !== []) {
            $this->components->warn(count($gaps).' dia(s) sem dados: os eventos expiraram antes de serem agregados ('.implode(', ', array_map(fn (mixed $day): string => substr((string) $day, 0, 10), $gaps)).').');
        }

        return self::SUCCESS;
    }
}
