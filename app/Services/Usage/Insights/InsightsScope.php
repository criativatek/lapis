<?php

namespace App\Services\Usage\Insights;

use App\Models\Organization;
use App\Support\Commercial\CurrentSubscriptions;
use App\Support\Usage\UsageSegment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * O âmbito de uma consulta: que organizações e que pessoas entram nos números.
 *
 * - Organizações: os filtros de organização e de plano. O plano é o plano EM
 *   VIGOR hoje (`CurrentSubscriptions`); mudanças passadas não se reconstroem.
 * - Segmento `main`: organização que não é conta de teste E pessoa que não é
 *   pessoal interno. `test` é o complemento exato.
 * - Contas anonimizadas ficam sempre de fora.
 * - Utilizadores do âmbito: membros de organizações do âmbito que cumprem o
 *   segmento.
 *
 * O segmento aplica-se POR LINHA (par utilizador/organização): é a atividade
 * numa organização de teste que é «de teste», não a pessoa em abstrato.
 */
final class InsightsScope
{
    /** @var list<int>|null */
    private ?array $organizationIds = null;

    private bool $organizationIdsResolved = false;

    /** @var list<int>|null */
    private ?array $userIds = null;

    public function __construct(
        private readonly UsageFilters $filters,
        private readonly CurrentSubscriptions $subscriptions,
    ) {}

    public function filters(): UsageFilters
    {
        return $this->filters;
    }

    /**
     * As organizações que os filtros de organização e de plano deixam passar;
     * nulo quando nenhum dos dois está ativo (todas).
     *
     * @return list<int>|null
     */
    public function organizationIds(): ?array
    {
        if ($this->organizationIdsResolved) {
            return $this->organizationIds;
        }

        $this->organizationIdsResolved = true;
        $ids = null;

        if ($this->filters->organization !== null) {
            $ids = Organization::query()
                ->where('ulid', $this->filters->organization)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
        }

        if ($this->filters->plan !== null) {
            $onPlan = $this->subscriptions->for()
                ->filter(fn ($subscription): bool => $subscription->plan?->key === $this->filters->plan)
                ->keys()
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $ids = $ids === null ? array_values($onPlan) : array_values(array_intersect($ids, $onPlan));
        }

        return $this->organizationIds = $ids === null ? null : array_values($ids);
    }

    /**
     * A tabela de agregados, com utilizador e organização juntos, já
     * restringida ao âmbito. Colunas: `r.*`, `u.*`, `o.*` à escolha de quem
     * chama. Datas: quem chama usa intervalos (`day >= ... and day < ...`).
     */
    public function rollups(): Builder
    {
        $query = DB::table('usage_daily_rollups as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->join('organizations as o', 'o.id', '=', 'r.organization_id');

        $this->restrict($query, 'r.organization_id');

        return $query;
    }

    /**
     * As pertenças a organizações do âmbito, com utilizador e organização.
     */
    private function memberships(): Builder
    {
        $query = DB::table('organization_memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->join('organizations as o', 'o.id', '=', 'm.organization_id');

        $this->restrict($query, 'm.organization_id');

        return $query;
    }

    /**
     * Os utilizadores do âmbito, uma só vez cada.
     *
     * @return list<int>
     */
    public function userIds(): array
    {
        if ($this->userIds !== null) {
            return $this->userIds;
        }

        return $this->userIds = array_values($this->memberships()
            ->distinct()
            ->pluck('m.user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    private function restrict(Builder $query, string $organizationColumn): void
    {
        $organizationIds = $this->organizationIds();

        if ($organizationIds !== null) {
            $query->whereIn($organizationColumn, $organizationIds);
        }

        $query->whereNull('u.anonymized_at');

        if ($this->filters->segment === UsageSegment::Main) {
            $query->where('o.is_test_account', false)
                ->where('u.is_platform_admin', false)
                ->where('u.is_support_technician', false);

            return;
        }

        $query->where(fn (Builder $inner) => $inner
            ->where('o.is_test_account', true)
            ->orWhere('u.is_platform_admin', true)
            ->orWhere('u.is_support_technician', true));
    }
}
