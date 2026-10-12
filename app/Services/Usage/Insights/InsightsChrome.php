<?php

namespace App\Services\Usage\Insights;

use App\Models\Organization;
use App\Models\Plan;
use App\Support\Retention\RetentionPolicy;
use App\Support\Usage\UsageCoverage;
use App\Support\Usage\UsageSegment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * As props que todas as páginas do painel partilham: filtros, opções dos
 * filtros, cobertura e definições.
 */
final class InsightsChrome
{
    public function __construct(
        private readonly UsageCoverage $coverage,
        private readonly RetentionPolicy $retention,
    ) {}

    /**
     * @return array{filters: array<string, mixed>, options: array<string, mixed>, coverage: array<string, mixed>, definitions: list<array{term: string, text: string}>}
     */
    public function common(UsageFilters $filters): array
    {
        return [
            'filters' => $filters->toArray(),
            'options' => $this->options(),
            'coverage' => $this->coverage($filters),
            'definitions' => $this->definitions(),
        ];
    }

    /**
     * @return array{plans: list<array{key: string, label: string}>, organizations: list<array{ulid: string, name: string, is_test_account: bool}>, segments: list<array{key: string, label: string}>, signal_options: list<array{key: string, label: string}>}
     */
    public function options(): array
    {
        return [
            'plans' => array_values(Plan::query()->orderBy('sort_order')->orderBy('id')->get(['key', 'name'])
                ->map(fn (Plan $plan): array => ['key' => $plan->key, 'label' => $plan->name])
                ->all()),
            'organizations' => array_values(Organization::query()->orderBy('name')->get(['ulid', 'name', 'is_test_account'])
                ->map(fn (Organization $organization): array => [
                    'ulid' => $organization->ulid,
                    'name' => $organization->name,
                    'is_test_account' => (bool) $organization->is_test_account,
                ])
                ->all()),
            'segments' => array_map(
                fn (UsageSegment $segment): array => ['key' => $segment->value, 'label' => $segment->label()],
                UsageSegment::cases(),
            ),
            'signal_options' => array_map(
                fn (string $key): array => ['key' => $key, 'label' => FollowUpSignals::label($key)],
                FollowUpSignals::enabledKeys(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function coverage(UsageFilters $filters): array
    {
        $startedAt = $this->coverage->startedAt();
        $startDay = $this->coverage->startDay();
        $cutoff = $filters->today->subDays($this->retention->usageMetricsDays());
        $aggregatedAt = DB::table('usage_aggregation_state')->where('id', 1)->value('aggregated_at');

        $days = UsageDates::eachDay($filters->from, $filters->to);
        $covered = array_values(array_filter($days, fn ($day): bool => $this->coverage->isDayCovered($day)));
        $fullyCovered = array_filter($days, fn ($day): bool => $this->coverage->isDayFullyCovered($day));

        $periodStatus = match (true) {
            $covered === [] => 'none',
            count($fullyCovered) === count($days) => 'complete',
            default => 'partial',
        };

        $notes = [];

        if ($startedAt !== null && $startDay !== null && $filters->from->lessThanOrEqualTo($startDay) && $filters->to->greaterThanOrEqualTo($startDay)) {
            $notes[] = 'A recolha de métricas começou a '.$startedAt->format('d/m/Y').' às '.$startedAt->format('H:i').'. Esse dia é parcial e não há dados de utilização anteriores.';
        }

        if ($startDay === null) {
            $notes[] = 'Ainda não há recolha de métricas: todos os dias aparecem como «Sem recolha».';
        } elseif ($filters->from->lessThan($startDay)) {
            $lastBefore = $startDay->subDay()->min($filters->to);
            $notes[] = 'O período começa antes da recolha: de '.UsageDates::short($filters->from).' a '.UsageDates::short($lastBefore).' não há recolha e os dias aparecem como «Sem recolha», nunca como zero.';
        }

        if ($startDay !== null && $filters->from->greaterThanOrEqualTo($startDay) && count($covered) < count($days)) {
            $notes[] = 'Há dias do período em que a recolha esteve suspensa; aparecem como «Sem recolha».';
        }

        if ($filters->adjustedToRetention) {
            $notes[] = 'O período foi ajustado ao prazo de conservação dos dados ('.$this->retention->usageMetricsDays().' dias): começa a '.UsageDates::short($filters->from).'.';
        }

        // Uma conta que a agregação ainda não viu não tem estado: não entra na
        // lista, no ranking nem nos sinais. Diz-se, em vez de a deixar sumir.
        $unseen = DB::table('users')
            ->whereNull('anonymized_at')
            ->whereNull('usage_opted_out_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('usage_user_states')->whereColumn('usage_user_states.user_id', 'users.id'))
            ->count();

        if ($unseen > 0) {
            $notes[] = $unseen === 1
                ? '1 conta ainda não foi vista pela agregação (é feita a cada 15 minutos) e por agora não aparece no acompanhamento, no ranking nem nos sinais.'
                : $unseen.' contas ainda não foram vistas pela agregação (é feita a cada 15 minutos) e por agora não aparecem no acompanhamento, no ranking nem nos sinais.';
        }

        if ($aggregatedAt === null) {
            $notes[] = 'A agregação diária ainda não correu: os números de atividade podem estar vazios.';
        } elseif (! FollowUpSignals::aggregationIsFresh(CarbonImmutable::now((string) config('app.timezone')))) {
            $notes[] = 'A última agregação tem mais de '.(int) config('usage.max_staleness_minutes', 60).' minutos ('.substr((string) $aggregatedAt, 8, 2).'/'.substr((string) $aggregatedAt, 5, 2).' às '.substr((string) $aggregatedAt, 11, 5).'): pode estar parada. Os números param nessa hora e os sinais que dependem da atividade ficam por avaliar.';
        }

        return [
            'collection_started_at' => $startedAt?->format('Y-m-d H:i'),
            'start_day' => $startDay === null ? null : UsageDates::iso($startDay),
            'retention_cutoff' => UsageDates::iso($cutoff),
            'aggregated_at' => is_string($aggregatedAt) ? substr($aggregatedAt, 0, 16) : null,
            'observed_from' => $covered === [] ? null : UsageDates::iso($covered[0]),
            'observed_to' => $covered === [] ? null : UsageDates::iso($covered[array_key_last($covered)]),
            'period_status' => $periodStatus,
            'notes' => $notes,
        ];
    }

    /**
     * @return list<array{term: string, text: string}>
     */
    public function definitions(): array
    {
        return [
            ['term' => 'Utilizador ativo', 'text' => 'Pessoa com pelo menos uma interação elegível no intervalo: consultou deliberadamente uma funcionalidade ou concluiu uma ação. Conta uma só vez, mesmo em várias organizações. Uma falha técnica sozinha não torna ninguém ativo.'],
            ['term' => 'Dia ativo', 'text' => 'Dia (de calendário, no fuso da aplicação) com pelo menos uma interação elegível. As semanas vão de segunda a domingo.'],
            ['term' => 'Última atividade observada', 'text' => 'O dia da última interação elegível registada. Não é o último login: o Lapispro não guarda essa data e o painel não a deduz de nada.'],
            ['term' => 'Ação concluída', 'text' => 'Operação confirmada pelo servidor sobre um registo, contada uma vez por registo e por dia. Gravar sem alterar nada, uma tentativa falhada ou um erro de validação não contam.'],
            ['term' => 'Elementos afetados', 'text' => 'Quantos registos uma ação tocou (alunos importados, células alteradas). Mostra-se à parte e nunca se soma às ações.'],
            ['term' => 'Falha técnica', 'text' => 'Operação que terminou com erro do servidor. Erros de validação, recusas de permissão, sessões expiradas e limites de pedidos não são falhas técnicas.'],
            ['term' => 'Segmentos', 'text' => '«Principal» exclui as organizações marcadas como conta de teste e o pessoal interno; «Teste e internas» mostra exatamente o que ficou de fora.'],
            ['term' => 'Sinais', 'text' => 'Sugestões de verificação para a equipa interna, não diagnósticos: não dizem que alguém abandonou ou precisa de formação. Com recolha insuficiente para a janela da regra não há sinal: a pessoa fica «por avaliar».'],
        ];
    }
}
