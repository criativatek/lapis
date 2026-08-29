<?php

namespace App\Services\Ai\Gateway;

use App\Models\AiUsageEvent;
use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Reading the meter. One place, two audiences.
 *
 * THE PLATFORM OPERATOR AND THE INSTITUTIONAL ADMINISTRATOR ASK THE SAME
 * QUESTION OF DIFFERENT ROWS. «How many calls, of what kind, how many failed,
 * how many tokens» is one aggregation; whose rows it runs over is the only
 * difference, and it is a scope rather than a second implementation. Two copies
 * would drift the day somebody adds a status.
 *
 * WHAT IT CAN RETURN IS BOUNDED BY THE SCHEMA, NOT BY THIS CLASS'S RESTRAINT.
 * `ai_usage_events` has no column for a prompt, an answer, a name or a
 * pedagogical fact, so no query written here could surface one — which is why
 * an institutional governance screen built on it cannot become surveillance
 * even by accident (§20 of the brief: «governação = consumo e configuração;
 * não = vigilância do conteúdo»).
 *
 * IT NEVER BREAKS CONSUMPTION DOWN BY PERSON, AND THAT IS A PRODUCT DECISION
 * RATHER THAN A MISSING FEATURE. The table has `user_id` and an index on it,
 * because `AiQuota` needs both to answer «has this teacher had their day's
 * allowance». Turning that into a screen — «os professores que mais usaram IA
 * este mês» — would be a ranking of colleagues, which the Matriz Mestre rules
 * out and which no ceiling actually requires: an organization that has run out
 * needs a bigger pool or a per-member ceiling, and both are settings rather
 * than names. A future release that genuinely needs a per-member figure should
 * argue for it in an ADR, not discover it here.
 *
 * A MONTH IS THE DEFAULT WINDOW because it is the window the organization quota
 * and the pool are counted in. A summary over a different period than the
 * ceiling it is shown beside is a number nobody can act on.
 */
class AiUsageSummary
{
    /**
     * Everything one organization spent since a moment.
     *
     * @return array<string, mixed>
     */
    public function forOrganization(Organization $organization, CarbonInterface $since): array
    {
        return $this->summarise(
            AiUsageEvent::query()
                ->forOrganization($organization->getKey())
                ->where('created_at', '>=', $since)
                ->get(),
            $since,
        );
    }

    /**
     * Everything the whole installation spent since a moment, tenants and the
     * operator's own connection tests together.
     *
     * `withoutGlobalScope` IS NOT NEEDED AND IS NOT USED. `AiUsageEvent`
     * deliberately does not use `BelongsToOrganization` — the trait would
     * refuse to write the backoffice's own rows — so this table has no global
     * scope to escape. Isolation here is `scopeForOrganization()` being the
     * only way a tenant-facing caller reads it, which is exactly what
     * `forOrganization()` above does and what this method, being
     * platform-admin-only, deliberately does not.
     *
     * @return array<string, mixed>
     */
    public function platform(CarbonInterface $since): array
    {
        return $this->summarise(
            AiUsageEvent::query()->where('created_at', '>=', $since)->get(),
            $since,
        );
    }

    /**
     * @param  Collection<int, AiUsageEvent>  $events
     * @return array<string, mixed>
     */
    protected function summarise(Collection $events, CarbonInterface $since): array
    {
        return [
            'since' => $since->toIso8601String(),
            'totals' => $this->totals($events),
            'by_capability' => $this->byCapability($events),
            'by_use_case' => $this->byUseCase($events),
            // Why calls were refused, so «o limite está mal posto» is visible
            // as a number rather than as a complaint. Never who was refused.
            'blocked_reasons' => $this->blockedReasons($events),
        ];
    }

    /**
     * @param  Collection<int, AiUsageEvent>  $events
     * @return array<string, int>
     */
    protected function totals(Collection $events): array
    {
        return [
            'calls' => $events->count(),
            'succeeded' => $events->where('status', AiUsageEvent::SUCCEEDED)->count(),
            'failed' => $events->where('status', AiUsageEvent::FAILED)->count(),
            'blocked' => $events->where('status', AiUsageEvent::BLOCKED)->count(),
            // Billable = succeeded + failed, the same definition the quota
            // counts by, so a screen and a ceiling never disagree about how
            // much has been used.
            'billable' => $events->whereIn('status', [AiUsageEvent::SUCCEEDED, AiUsageEvent::FAILED])->count(),
            'total_tokens' => (int) $events->sum(fn (AiUsageEvent $event): int => (int) $event->total_tokens),
        ];
    }

    /**
     * Per capability, INCLUDING THE ONES WITH NO TRAFFIC.
     *
     * A row of zeros is the answer to «porque é que não aparece nada» — it says
     * the capability exists, is enabled, and nobody has used it. Omitting it
     * would make an unused capability indistinguishable from one that is not
     * installed.
     *
     * @param  Collection<int, AiUsageEvent>  $events
     * @return list<array<string, mixed>>
     */
    protected function byCapability(Collection $events): array
    {
        $rows = [];

        foreach (AiCapability::metered() as $capability) {
            $rows[] = [
                'capability' => $capability->value,
                'label' => $capability->label(),
                ...$this->totals($events->where('capability', $capability->value)),
            ];
        }

        // The operator's own connection tests, which belong to no capability
        // and no plan. Shown separately rather than folded into a tenant's
        // figures — see `AiUseCase::capabilityColumn()`.
        $platform = $events->where('capability', 'platform');

        if ($platform->isNotEmpty()) {
            $rows[] = [
                'capability' => 'platform',
                'label' => 'Plataforma (testes de ligação)',
                ...$this->totals($platform),
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, AiUsageEvent>  $events
     * @return list<array<string, mixed>>
     */
    protected function byUseCase(Collection $events): array
    {
        $rows = [];

        foreach (AiUseCase::cases() as $useCase) {
            $matching = $events->where('use_case', $useCase);

            if ($matching->isEmpty()) {
                continue;
            }

            $rows[] = [
                'use_case' => $useCase->value,
                'label' => $useCase->label(),
                ...$this->totals($matching),
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, AiUsageEvent>  $events
     * @return list<array{reason: string, label: string, count: int}>
     */
    protected function blockedReasons(Collection $events): array
    {
        $rows = [];

        foreach ($events->where('status', AiUsageEvent::BLOCKED)->groupBy('error_category') as $reason => $group) {
            $reason = (string) $reason;

            $rows[] = [
                'reason' => $reason,
                'label' => self::blockedLabel($reason),
                'count' => $group->count(),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $rows;
    }

    /**
     * A blocking reason in words an operator can act on.
     *
     * NOT THE RAW SLUG. `organization_pool_monthly` on a screen sends somebody
     * to the source; «o plafond da organização esgotou-se» sends them to the
     * setting.
     */
    public static function blockedLabel(string $reason): string
    {
        return match ($reason) {
            'not_entitled' => 'Plano não inclui a funcionalidade',
            'provider_unavailable' => 'Motor de IA não configurado',
            'rate_limited' => 'Demasiados pedidos por minuto',
            'user_daily' => 'Limite diário do utilizador',
            'organization_monthly' => 'Limite mensal da organização',
            'organization_pool_monthly' => 'Plafond mensal da organização esgotado',
            'user_pool_monthly' => 'Teto individual dentro do plafond',
            default => $reason,
        };
    }
}
