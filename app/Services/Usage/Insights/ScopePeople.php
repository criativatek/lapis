<?php

namespace App\Services\Usage\Insights;

use App\Models\UsageUserState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * As pessoas de um âmbito: as contas (`users`) e o estado agregado
 * (`usage_user_states`). Só `users` e `usage_user_states` são lidos.
 */
final class ScopePeople
{
    private const CHUNK = 800;

    /**
     * Os utilizadores do âmbito que já têm estado agregado (a chave do detalhe),
     * com o estado, ambos indexados por user_id.
     *
     * @return array{users: Collection<int, User>, states: array<int, UsageUserState>}
     */
    public function withState(InsightsScope $scope): array
    {
        $states = [];
        foreach (array_chunk($scope->userIds(), self::CHUNK) as $chunk) {
            foreach (UsageUserState::query()->whereIn('user_id', $chunk)->get() as $state) {
                $states[$state->user_id] = $state;
            }
        }

        $users = collect();
        foreach (array_chunk(array_keys($states), self::CHUNK) as $chunk) {
            foreach (User::query()->whereIn('id', $chunk)->whereNull('anonymized_at')->get() as $user) {
                $users->put($user->id, $user);
            }
        }

        return [
            'users' => $users,
            'states' => array_intersect_key($states, $users->all()),
        ];
    }

    /**
     * Quem do âmbito criou a conta no intervalo (lido no momento, de `users`).
     * `observed_from_creation` é falso para quem retomou a recolha depois de
     * criar a conta: os primeiros passos dessa pessoa não são conhecidos.
     *
     * @return list<array{id: int, created_at: CarbonImmutable, observed_from_creation: bool}>
     */
    public function createdBetween(InsightsScope $scope, CarbonInterface $from, CarbonInterface $toExclusive): array
    {
        $result = [];

        foreach (array_chunk($scope->userIds(), self::CHUNK) as $chunk) {
            $users = User::query()
                ->whereIn('id', $chunk)
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $toExclusive)
                ->get(['id', 'created_at', 'usage_resumed_at']);

            foreach ($users as $user) {
                $result[] = [
                    'id' => $user->id,
                    'created_at' => CarbonImmutable::instance($user->created_at),
                    'observed_from_creation' => $user->usageObservedSince()->equalTo($user->created_at),
                ];
            }
        }

        return $result;
    }
}
