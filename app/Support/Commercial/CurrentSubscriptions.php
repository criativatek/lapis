<?php

namespace App\Support\Commercial;

use App\Models\OrganizationSubscription;
use Illuminate\Support\Collection;

/**
 * O plano em vigor de cada organização: a subscrição `isInForce()` mais
 * recente, e, se nenhuma estiver em vigor, a mais recente. É a MESMA regra de
 * `AdminAccountController::currentSubscriptions()` — repetida aqui para que o
 * painel de utilização a use sem depender de um método protegido de um
 * controlador.
 *
 * Lê as subscrições de todas as organizações, por isso tira o âmbito de tenant.
 */
final class CurrentSubscriptions
{
    /**
     * @param  iterable<int>|null  $organizationIds  nulo = todas as organizações
     * @return Collection<int, OrganizationSubscription> indexada por organization_id
     */
    public function for(?iterable $organizationIds = null): Collection
    {
        $query = OrganizationSubscription::query()
            ->withoutGlobalScope('organization')
            ->with('plan')
            ->latest('starts_at')
            ->latest('id');

        if ($organizationIds !== null) {
            $query->whereIn('organization_id', collect($organizationIds)->values()->all());
        }

        return $query->get()
            ->groupBy('organization_id')
            ->map(fn (Collection $subscriptions): OrganizationSubscription => $subscriptions->first(
                fn (OrganizationSubscription $subscription): bool => $subscription->isInForce(),
            ) ?? $subscriptions->first());
    }
}
