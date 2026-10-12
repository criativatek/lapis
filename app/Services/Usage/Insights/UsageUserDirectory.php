<?php

namespace App\Services\Usage\Insights;

use App\Models\UsageUserState;
use App\Models\User;
use App\Support\Commercial\CurrentSubscriptions;
use App\Support\Usage\UsageCoverage;
use App\Support\Usage\UsageFeature;
use App\Support\Usage\UsageMilestone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\URL;

/**
 * As props de `admin/usage/Users` (lista) e `admin/usage/User` (detalhe).
 *
 * A lista mostra quem está no âmbito E já tem estado agregado (é o `ulid` desse
 * estado que abre o detalhe). O `status` é sempre DESCRITIVO: nunca «inativo»,
 * «abandono» ou qualquer juízo. Sem conteúdos, sem lista de eventos.
 */
final class UsageUserDirectory
{
    public const PER_PAGE = 25;

    /** O primeiro é a omissão. */
    public const SORT_VALUES = ['last_activity', 'name', 'active_days', 'actions'];

    public function __construct(
        private readonly CurrentSubscriptions $subscriptions,
        private readonly InsightsChrome $chrome,
        private readonly UsagePeriods $periods,
        private readonly UsageActivity $activity,
        private readonly ScopePeople $people,
        private readonly PersonProfiles $profiles,
        private readonly FollowUpSignals $signals,
        private readonly UsageCoverage $coverage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function list(UsageFilters $filters, CarbonImmutable $now): array
    {
        $scope = new InsightsScope($filters, $this->subscriptions);
        ['users' => $users, 'states' => $states] = $this->people->withState($scope);

        $evaluation = $this->signals->evaluate($users, $states, $now);
        $metrics = $this->activity->perUser($scope, $filters->from, $filters->to, array_keys($states));
        $lastActivity = $this->activity->lastActivity($scope, array_keys($states));
        $observed = new ObservedDays($this->coverage, $filters);

        $entries = [];
        foreach ($users as $userId => $user) {
            if ($filters->search !== '' && ! $this->matches($user, $filters->search)) {
                continue;
            }
            if ($filters->signal !== null && ! $evaluation->has($userId, $filters->signal)) {
                continue;
            }

            $entries[] = [
                'user' => $user,
                'state' => $states[$userId],
                'activity' => $metrics[$userId] ?? UserActivity::none(),
                'last' => $lastActivity[$userId] ?? null,
            ];
        }

        usort($entries, $this->comparator((string) $filters->sort));

        $total = count($entries);
        $page = max(1, $filters->page);
        $slice = array_slice($entries, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $profiles = $this->profiles->for(array_map(fn (array $entry): User => $entry['user'], $slice));

        $rows = array_map(function (array $entry) use ($filters, $evaluation, $observed, $profiles): array {
            /** @var User $user */
            $user = $entry['user'];
            /** @var UserActivity $activity */
            $activity = $entry['activity'];
            $profile = $profiles[$user->id];

            return [
                'ulid' => $entry['state']->ulid,
                'name' => $user->name,
                'email' => $user->email,
                'joined_on' => UsageDates::iso($user->created_at),
                'plans' => $profile['plans'],
                'organizations' => count($profile['organizations']),
                'flags' => $profile['flags'],
                'last_activity_on' => $entry['last'],
                'active_days' => $activity->activeDays,
                'actions' => $activity->actions,
                'items' => $activity->items,
                'features' => $this->featureList($activity->features),
                'signals' => array_map(fn (Signal $signal): array => $signal->toArray(), $evaluation->for($user->id)),
                'status' => $this->status($user, $activity, $filters, $observed->forAccount($user->usageObservedSince())),
                'observation_days' => $observed->forAccount($user->usageObservedSince()),
                'period_days' => $filters->days(),
            ];
        }, $slice);

        $paginator = (new LengthAwarePaginator($rows, $total, self::PER_PAGE, $page, [
            'path' => URL::current(),
            'pageName' => 'page',
        ]))->withQueryString();

        return [
            ...$this->chrome->common($filters),
            'users' => $paginator,
            'sort_options' => [
                ['key' => 'last_activity', 'label' => 'Última atividade observada'],
                ['key' => 'name', 'label' => 'Nome'],
                ['key' => 'active_days', 'label' => 'Dias ativos no período'],
                ['key' => 'actions', 'label' => 'Ações concluídas'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(UsageUserState $state, UsageFilters $filters, CarbonImmutable $now): array
    {
        $user = User::query()->find($state->user_id);

        // Anonimizada ou desaparecida: o detalhe já não existe.
        abort_if($user === null || $user->anonymized_at !== null, 404);

        $result = $this->activity->ofUser($user->id, $filters->from, $filters->to);
        /** @var UserActivity $activity */
        $activity = $result['activity'];
        $observed = new ObservedDays($this->coverage, $filters);
        $profile = $this->profiles->for([$user])[$user->id];

        $evaluation = $this->signals->evaluate([$user], [$user->id => $state], $now);

        $startedAt = $this->coverage->startedAt();
        $precedesCollection = $startedAt !== null && $user->created_at->lessThan($startedAt);
        $resumedAt = $this->resumedAfterCreation($user);

        $firstStepsNote = match (true) {
            $resumedAt !== null => 'Recolha retomada a '.UsageDates::short($resumedAt).' — o que aconteceu antes não é conhecido.',
            $precedesCollection => 'Conta anterior à recolha — pode ter acontecido antes.',
            default => null,
        };

        return [
            ...$this->chrome->common($filters),
            'user' => [
                'ulid' => $state->ulid,
                'name' => $user->name,
                'email' => $user->email,
                'joined_on' => UsageDates::iso($user->created_at),
                'status' => $this->status($user, $activity, $filters, $observed->forAccount($user->usageObservedSince())),
                'organizations' => $profile['organizations'],
                'flags' => $profile['flags'],
            ],
            'first_steps' => array_map(fn (UsageMilestone $milestone): array => [
                'key' => $milestone->value,
                'label' => $milestone->label(),
                'observed_on' => $state->{$milestone->stateColumn()}?->toDateString(),
                'note' => $firstStepsNote,
            ], UsageMilestone::cases()),
            'class_available_since' => [
                'date' => $state->class_available_since === null ? null : UsageDates::iso($state->class_available_since),
                'label' => 'Turma disponível desde — configuração, não prova de utilização',
            ],
            'signals' => array_map(fn (Signal $signal): array => $signal->toArray(), $evaluation->for($user->id)),
            'not_evaluated' => array_map(
                fn (string $key): array => ['key' => $key, 'label' => FollowUpSignals::label($key)],
                $evaluation->notEvaluated[$user->id] ?? [],
            ),
            'summary' => [
                'active_days' => $activity->activeDays,
                'active_weeks' => $activity->activeWeeks,
                'actions' => $activity->actions,
                'items' => $activity->items,
                'failures' => $activity->failures,
                'last_activity_on' => $this->activity->lastActivityOf($user->id),
                'observation_days' => $observed->forAccount($user->usageObservedSince()),
                'period_days' => $filters->days(),
            ],
            'weeks' => $this->weeks($filters, $result['rows'], $resumedAt),
            'features' => $this->features($result['rows']),
        ];
    }

    /**
     * @param  list<RollupRow>  $rows
     * @return list<array<string, mixed>>
     */
    private function weeks(UsageFilters $filters, array $rows, ?CarbonInterface $resumedAt): array
    {
        return array_map(function (UsagePeriod $week) use ($rows): array {
            if (! $week->isObserved()) {
                return [
                    'start' => UsageDates::iso($week->start),
                    'end' => UsageDates::iso($week->end),
                    'status' => $week->status,
                    'active_days' => null,
                    'actions' => null,
                    'items' => null,
                    'failures' => null,
                    'features' => [],
                ];
            }

            $inside = array_values(array_filter($rows, fn (RollupRow $row): bool => $week->contains($row->day)));
            $summary = $this->activity->summarise($inside);
            $activity = reset($summary) ?: UserActivity::none();

            return [
                'start' => UsageDates::iso($week->start),
                'end' => UsageDates::iso($week->end),
                'status' => $week->status,
                'active_days' => $activity->activeDays,
                'actions' => $activity->actions,
                'items' => $activity->items,
                'failures' => $activity->failures,
                'features' => $this->featureList($activity->features),
            ];
        }, $this->periods->weeks($filters, $resumedAt));
    }

    /**
     * O instante em que a recolha voltou a valer para a pessoa, se for depois
     * de criar a conta (oposição retirada ou encerramento cancelado). Nulo para
     * quem é observado desde a criação.
     */
    private function resumedAfterCreation(User $user): ?CarbonInterface
    {
        $since = $user->usageObservedSince();

        return $user->created_at !== null && $since->greaterThan($user->created_at) ? $since : null;
    }

    /**
     * @param  list<RollupRow>  $rows
     * @return list<array<string, mixed>>
     */
    private function features(array $rows): array
    {
        $result = [];

        foreach (UsageFeature::cases() as $feature) {
            $mine = array_filter($rows, fn (RollupRow $row): bool => $row->feature === $feature->value);
            if ($mine === []) {
                continue;
            }

            $activeDays = [];
            $viewDays = [];
            foreach ($mine as $row) {
                if ($row->views > 0) {
                    $viewDays[$row->day] = true;
                }
                if ($row->isActive()) {
                    $activeDays[$row->day] = true;
                }
            }

            $result[] = [
                'key' => $feature->value,
                'label' => $feature->label(),
                'active_days' => count($activeDays),
                'view_days' => count($viewDays),
                'actions' => array_sum(array_map(fn (RollupRow $row): int => $row->actions, $mine)),
                'items' => array_sum(array_map(fn (RollupRow $row): int => $row->items, $mine)),
                'items_created' => array_sum(array_map(fn (RollupRow $row): int => $row->itemsCreated, $mine)),
                'items_changed' => array_sum(array_map(fn (RollupRow $row): int => $row->itemsChanged, $mine)),
                'items_unchanged' => array_sum(array_map(fn (RollupRow $row): int => $row->itemsUnchanged, $mine)),
                'failures' => array_sum(array_map(fn (RollupRow $row): int => $row->failures, $mine)),
            ];
        }

        return $result;
    }

    /**
     * @param  list<UsageFeature>  $features
     * @return list<array{key: string, label: string}>
     */
    private function featureList(array $features): array
    {
        return array_map(fn (UsageFeature $feature): array => ['key' => $feature->value, 'label' => $feature->label()], $features);
    }

    /**
     * Uma descrição do que se observa — nunca um juízo.
     */
    private function status(User $user, UserActivity $activity, UsageFilters $filters, int $observationDays): ?string
    {
        $resumed = $this->resumedAfterCreation($user);

        return match (true) {
            $user->closure_requested_at !== null => 'Conta em encerramento',
            $user->deactivated_at !== null => 'Conta desativada',
            $user->created_at->greaterThanOrEqualTo($filters->from) && $user->created_at->lessThan($filters->toExclusive()) => 'Conta criada durante o período',
            // Não é inatividade: antes da retoma a pessoa não era observada.
            $resumed !== null
                && $resumed->greaterThanOrEqualTo($filters->from)
                && $resumed->lessThan($filters->toExclusive()) => 'Recolha retomada durante o período',
            // Sem um único dia observado não se pode dizer «sem atividade».
            $activity->activeDays === 0 && $observationDays === 0 => 'Sem recolha no período',
            $activity->activeDays === 0 => 'Sem atividade observada no período',
            default => null,
        };
    }

    private function matches(User $user, string $term): bool
    {
        return mb_stripos($user->name, $term) !== false || mb_stripos($user->email, $term) !== false;
    }

    /**
     * @return callable(array<string, mixed>, array<string, mixed>): int
     */
    private function comparator(string $sort): callable
    {
        return function (array $a, array $b) use ($sort): int {
            $byName = fn (): int => strcmp(mb_strtolower($a['user']->name), mb_strtolower($b['user']->name));

            $primary = match ($sort) {
                'name' => 0,
                'active_days' => $b['activity']->activeDays <=> $a['activity']->activeDays,
                'actions' => $b['activity']->actions <=> $a['activity']->actions,
                // Mais recente primeiro; quem não tem atividade vai para o fim.
                default => match (true) {
                    $a['last'] === null && $b['last'] === null => 0,
                    $a['last'] === null => 1,
                    $b['last'] === null => -1,
                    default => strcmp((string) $b['last'], (string) $a['last']),
                },
            };

            return $primary !== 0 ? $primary : $byName();
        };
    }
}
