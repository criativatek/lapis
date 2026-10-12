<?php

namespace App\Services\Usage\Insights;

use App\Models\User;
use App\Support\Commercial\CurrentSubscriptions;
use Illuminate\Support\Facades\DB;

/**
 * As organizações, os planos e as marcas de cada pessoa: o que as linhas e os
 * cabeçalhos do painel mostram além dos números.
 *
 * Considera TODAS as organizações de que a pessoa é membro, não só as do
 * âmbito: é uma descrição da pessoa, não um filtro.
 */
final class PersonProfiles
{
    public function __construct(private readonly CurrentSubscriptions $subscriptions) {}

    /**
     * @param  iterable<User>  $users
     * @return array<int, array{organizations: list<array{name: string, plan: string|null, classification: string}>, plans: list<string>, flags: array{test: bool, internal: bool, unclassified: bool}}>
     */
    public function for(iterable $users): array
    {
        $users = collect($users)->keyBy('id');
        $memberships = [];

        foreach ($users->keys()->chunk(500) as $chunk) {
            $rows = DB::table('organization_memberships as m')
                ->join('organizations as o', 'o.id', '=', 'm.organization_id')
                ->whereIn('m.user_id', $chunk->values()->all())
                ->orderBy('o.name')
                ->get(['m.user_id', 'o.id as organization_id', 'o.name', 'o.usage_classification']);

            foreach ($rows as $row) {
                $memberships[(int) $row->user_id][] = $row;
            }
        }

        $organizationIds = collect($memberships)->flatten(1)->pluck('organization_id')->unique()->values()->all();
        $plans = $this->subscriptions->for($organizationIds);

        $result = [];

        foreach ($users as $userId => $user) {
            $organizations = [];
            $planNames = [];
            $classifications = [];

            foreach ($memberships[$userId] ?? [] as $row) {
                $plan = $plans->get((int) $row->organization_id)?->plan?->name;
                $organizations[] = [
                    'name' => (string) $row->name,
                    'plan' => $plan,
                    'classification' => (string) $row->usage_classification,
                ];
                if ($plan !== null) {
                    $planNames[$plan] = true;
                }
                $classifications[(string) $row->usage_classification] = true;
            }

            $result[$userId] = [
                'organizations' => $organizations,
                'plans' => array_keys($planNames),
                'flags' => [
                    'test' => isset($classifications['test']),
                    'internal' => $user->is_platform_admin || $user->is_support_technician || isset($classifications['internal']),
                    'unclassified' => isset($classifications['unclassified']),
                ],
            ];
        }

        return $result;
    }
}
