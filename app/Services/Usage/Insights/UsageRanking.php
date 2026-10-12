<?php

namespace App\Services\Usage\Insights;

use App\Models\User;
use App\Support\Commercial\CurrentSubscriptions;
use App\Support\Usage\UsageCoverage;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\URL;

/**
 * As props de `admin/usage/Ranking`.
 *
 * Compara regularidade e ações concluídas na aplicação. Não há pontuação
 * composta: cada métrica é mostrada à parte e a ordenação é por UM critério.
 * Empates partilham a posição (posição densa). Quem não teve atividade no
 * período não é ordenado — conta-se à parte.
 */
final class UsageRanking
{
    public const PER_PAGE = 50;

    /** O primeiro é a omissão. */
    public const SORT_VALUES = ['active_days', 'actions', 'active_weeks', 'features'];

    public const AGE_VALUES = ['all', 'full', 'lt30', '30to90', 'gt90'];

    public const SUPPORT_TEXT = 'Compara a regularidade e as ações concluídas na aplicação. Não avalia a qualidade do trabalho pedagógico.';

    public function __construct(
        private readonly CurrentSubscriptions $subscriptions,
        private readonly InsightsChrome $chrome,
        private readonly UsageActivity $activity,
        private readonly ScopePeople $people,
        private readonly PersonProfiles $profiles,
        private readonly UsageCoverage $coverage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(UsageFilters $filters): array
    {
        $scope = new InsightsScope($filters, $this->subscriptions);
        ['users' => $users, 'states' => $states] = $this->people->withState($scope);
        $metrics = $this->activity->perUser($scope, $filters->from, $filters->to, array_keys($states));
        $observed = new ObservedDays($this->coverage, $filters);
        $periodDays = $filters->days();
        $sort = (string) $filters->sort;

        $ranked = [];
        $unranked = 0;

        foreach ($users as $userId => $user) {
            $accountAge = UsageDates::elapsed($user->created_at, $filters->today);
            $observationDays = $observed->forAccount($user->created_at);

            $complete = $observed->isCompleteForAccount($user->created_at);

            if (! $this->passesAge($filters->age, $accountAge, $complete)) {
                continue;
            }

            $activity = $metrics[$userId] ?? UserActivity::none();

            if ($activity->activeDays === 0) {
                $unranked++;

                continue;
            }

            $ranked[] = [
                'user' => $user,
                'activity' => $activity,
                'account_age_days' => $accountAge,
                'observation_days' => $observationDays,
                'complete' => $complete,
                'observed_weeks' => $observed->weeksForAccount($user->created_at),
                'value' => $this->criterion($sort, $activity),
            ];
        }

        usort($ranked, fn (array $a, array $b): int => ($b['value'] <=> $a['value'])
            ?: strcmp(mb_strtolower($a['user']->name), mb_strtolower($b['user']->name)));

        // Posição densa: 1, 1, 2 — empates partilham-na.
        $occurrences = array_count_values(array_map(fn (array $entry): int => $entry['value'], $ranked));
        $position = 0;
        $previous = null;
        foreach ($ranked as $index => $entry) {
            if ($previous === null || $entry['value'] !== $previous) {
                $position++;
                $previous = $entry['value'];
            }
            $ranked[$index]['position'] = $position;
            $ranked[$index]['tied'] = $occurrences[$entry['value']] > 1;
        }

        $total = count($ranked);
        $page = max(1, $filters->page);
        $slice = array_slice($ranked, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $profiles = $this->profiles->for(array_map(fn (array $entry): User => $entry['user'], $slice));

        $rows = array_map(function (array $entry) use ($states, $profiles, $periodDays): array {
            /** @var User $user */
            $user = $entry['user'];
            /** @var UserActivity $activity */
            $activity = $entry['activity'];

            return [
                'position' => $entry['position'],
                'tied' => $entry['tied'],
                'ulid' => $states[$user->id]->ulid,
                'name' => $user->name,
                'email' => $user->email,
                'plans' => $profiles[$user->id]['plans'],
                'joined_on' => UsageDates::iso($user->created_at),
                'account_age_days' => $entry['account_age_days'],
                'observation_days' => $entry['observation_days'],
                'period_days' => $periodDays,
                'partial_observation' => ! $entry['complete'],
                'active_days' => $activity->activeDays,
                'actions' => $activity->actions,
                'items' => $activity->items,
                'active_weeks' => $activity->activeWeeks,
                'observed_weeks' => $entry['observed_weeks'],
                'features' => count($activity->features),
            ];
        }, $slice);

        $paginator = (new LengthAwarePaginator($rows, $total, self::PER_PAGE, $page, [
            'path' => URL::current(),
            'pageName' => 'page',
        ]))->withQueryString();

        return [
            ...$this->chrome->common($filters),
            'support_text' => self::SUPPORT_TEXT,
            'ranking' => $paginator,
            'total_ranked' => $total,
            // Sem um único dia com recolha no período, ninguém «não teve
            // atividade»: não se sabe. Nada se ordena e nada se conta.
            'unranked' => $this->coverage->coveredDaysIn($filters->from, $filters->to) === [] ? null : $unranked,
            'sort_options' => [
                ['key' => 'active_days', 'label' => 'Dias ativos no período'],
                ['key' => 'actions', 'label' => 'Ações concluídas'],
                ['key' => 'active_weeks', 'label' => 'Semanas com atividade'],
                ['key' => 'features', 'label' => 'Funcionalidades utilizadas (indicador complementar)'],
            ],
            'age_options' => [
                ['key' => 'all', 'label' => 'Todas as contas'],
                ['key' => 'full', 'label' => 'Com observação completa'],
                ['key' => 'lt30', 'label' => 'Menos de 30 dias'],
                ['key' => '30to90', 'label' => '30 a 90 dias'],
                ['key' => 'gt90', 'label' => 'Mais de 90 dias'],
            ],
        ];
    }

    private function criterion(string $sort, UserActivity $activity): int
    {
        return match ($sort) {
            'actions' => $activity->actions,
            'active_weeks' => $activity->activeWeeks,
            'features' => count($activity->features),
            default => $activity->activeDays,
        };
    }

    private function passesAge(string $age, int $accountAge, bool $complete): bool
    {
        return match ($age) {
            'full' => $complete,
            'lt30' => $accountAge < 30,
            '30to90' => $accountAge >= 30 && $accountAge <= 90,
            'gt90' => $accountAge > 90,
            default => true,
        };
    }
}
