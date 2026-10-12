<?php

namespace App\Services\Usage\Insights;

use App\Models\UsageUserState;
use App\Models\User;
use App\Support\Usage\UsageCoverage;
use App\Support\Usage\UsageFeature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Os sinais de acompanhamento (docs/usage-insights.md): quatro regras que
 * SUGEREM uma verificação, sem diagnosticar ninguém.
 *
 * Avaliam-se sobre TODA a atividade da pessoa (todas as organizações e
 * segmentos); quem decide quem se lista é o âmbito, não esta classe.
 *
 * Recolha insuficiente NÃO gera sinal: a pessoa fica «por avaliar» e conta-se
 * à parte. Contas em encerramento, desativadas ou anonimizadas não geram sinal
 * nem contam como «por avaliar». Quem não tem linha em `usage_user_states` (a
 * agregação ainda não o viu, ou opôs-se à recolha) não é avaliado.
 */
final class FollowUpSignals
{
    public const NO_CLASS = 'no_class';

    public const NO_FIRST_RECORD = 'no_first_record';

    public const WENT_QUIET = 'went_quiet';

    public const TECHNICAL_FAILURES = 'technical_failures';

    public const KEYS = [self::NO_CLASS, self::NO_FIRST_RECORD, self::WENT_QUIET, self::TECHNICAL_FAILURES];

    /** Teto de dias a recuar à procura de dias letivos (a conservação são 365). */
    private const LOOKBACK_CAP_DAYS = 400;

    public function __construct(
        private readonly UsageCoverage $coverage,
        private readonly NonTeachingDays $nonTeachingDays,
    ) {}

    /**
     * A última agregação tem menos de `usage.max_staleness_minutes` minutos.
     */
    public static function aggregationIsFresh(CarbonImmutable $now): bool
    {
        $aggregatedAt = DB::table('usage_aggregation_state')->where('id', 1)->value('aggregated_at');

        if (! is_string($aggregatedAt) || $aggregatedAt === '') {
            return false;
        }

        $at = CarbonImmutable::parse(substr($aggregatedAt, 0, 19), (string) config('app.timezone'));

        return $at->greaterThanOrEqualTo($now->subMinutes((int) config('usage.max_staleness_minutes', 60)));
    }

    public static function label(string $key): string
    {
        return match ($key) {
            self::NO_CLASS => 'Conta sem turma',
            self::NO_FIRST_RECORD => 'Turma sem primeiro registo útil',
            self::WENT_QUIET => 'Sem atividade recente',
            self::TECHNICAL_FAILURES => 'Falhas técnicas repetidas',
            default => $key,
        };
    }

    private static function days(int $count): string
    {
        return $count.($count === 1 ? ' dia' : ' dias');
    }

    private static function nonTeaching(int $count): string
    {
        return $count === 1 ? '1 dia não letivo' : $count.' dias não letivos';
    }

    public static function description(string $key): string
    {
        $config = (array) config('usage.signals.'.$key, []);

        return match ($key) {
            self::NO_CLASS => sprintf('Conta criada há pelo menos %d dias e sem nenhuma turma disponível.', $config['days'] ?? 0),
            self::NO_FIRST_RECORD => sprintf('Turma disponível há pelo menos %d dias e nenhum registo útil observado desde então.', $config['days'] ?? 0),
            self::WENT_QUIET => sprintf(
                'Atividade em pelo menos %d das %d semanas completas anteriores e nenhuma nos últimos %d dias.',
                $config['min_active_weeks'] ?? 0,
                $config['lookback_weeks'] ?? 0,
                $config['quiet_days'] ?? 0,
            ),
            self::TECHNICAL_FAILURES => sprintf('Pelo menos %d falhas técnicas nos últimos %d dias.', $config['threshold'] ?? 0, $config['days'] ?? 0),
            default => '',
        };
    }

    /**
     * As chaves dos sinais ligados em `config/usage.php`.
     *
     * @return list<string>
     */
    public static function enabledKeys(): array
    {
        return array_values(array_filter(
            self::KEYS,
            fn (string $key): bool => (bool) config('usage.signals.'.$key.'.enabled', true),
        ));
    }

    /**
     * @param  iterable<User>  $users
     * @param  array<int, UsageUserState>  $states  indexado por user_id
     */
    public function evaluate(iterable $users, array $states, CarbonImmutable $now): SignalEvaluation
    {
        $now = $now->setTimezone((string) config('app.timezone'));
        $today = $now->startOfDay();
        $enabled = array_flip(self::enabledKeys());

        $eligible = [];
        foreach ($users as $user) {
            if (! isset($states[$user->id])) {
                continue;
            }
            if ($user->closure_requested_at !== null || $user->deactivated_at !== null || $user->anonymized_at !== null) {
                continue;
            }
            $eligible[$user->id] = $user;
        }

        $signals = [];
        $notEvaluated = [];
        $counts = array_fill_keys(self::KEYS, 0);
        $notEvaluatedCounts = array_fill_keys(self::KEYS, 0);

        if ($eligible === [] || $enabled === []) {
            return new SignalEvaluation($signals, $notEvaluated, $counts, $notEvaluatedCounts);
        }

        // Agregação parada = atividade e estado das turmas parados no tempo: os
        // sinais que dependem deles ficam por avaliar (uma vaga de falsos «sem
        // atividade recente» seria pior do que nenhum sinal). As falhas
        // técnicas continuam: dados atrasados só as podem subcontar.
        $fresh = self::aggregationIsFresh($now);

        $userIds = array_keys($eligible);
        $horizon = $today->subDays(self::LOOKBACK_CAP_DAYS);
        $earliest = $horizon;
        foreach ($eligible as $user) {
            $created = UsageDates::day($user->created_at ?? $today);
            if ($created->lessThan($earliest)) {
                $earliest = $created;
            }
        }

        $nonTeaching = $this->nonTeachingDays->forUsers($userIds, $earliest, $today);
        $activeDays = isset($enabled[self::WENT_QUIET]) ? $this->activeDays($userIds, $horizon, $today) : [];
        $failures = isset($enabled[self::TECHNICAL_FAILURES]) ? $this->failures($userIds, $today) : [];

        foreach ($eligible as $userId => $user) {
            $state = $states[$userId];
            $off = $nonTeaching[$userId] ?? [];

            $found = [];
            $pending = [];

            if (! $fresh) {
                foreach ([self::NO_CLASS, self::NO_FIRST_RECORD, self::WENT_QUIET] as $key) {
                    if (isset($enabled[$key])) {
                        $pending[] = $key;
                    }
                }
            }

            if ($fresh && isset($enabled[self::NO_CLASS]) && ($signal = $this->noClass($user, $state, $off, $today)) !== null) {
                $found[] = $signal;
            }

            if ($fresh && isset($enabled[self::NO_FIRST_RECORD])) {
                $result = $this->noFirstRecord($state, $off, $today, $now);
                if ($result instanceof Signal) {
                    $found[] = $result;
                } elseif ($result === false) {
                    $pending[] = self::NO_FIRST_RECORD;
                }
            }

            if ($fresh && isset($enabled[self::WENT_QUIET])) {
                $result = $this->wentQuiet($user, $activeDays[$userId] ?? [], $off, $today, $now);
                if ($result instanceof Signal) {
                    $found[] = $result;
                } elseif ($result === false) {
                    $pending[] = self::WENT_QUIET;
                }
            }

            if (isset($enabled[self::TECHNICAL_FAILURES]) && ($signal = $this->technicalFailures($failures[$userId] ?? [], $today)) !== null) {
                $found[] = $signal;
            }

            foreach ($found as $signal) {
                $counts[$signal->key]++;
            }
            foreach ($pending as $key) {
                $notEvaluatedCounts[$key]++;
            }

            if ($found !== []) {
                $signals[$userId] = $found;
            }
            if ($pending !== []) {
                $notEvaluated[$userId] = $pending;
            }
        }

        return new SignalEvaluation($signals, $notEvaluated, $counts, $notEvaluatedCounts);
    }

    /**
     * @param  array<string, true>  $off
     */
    private function noClass(User $user, UsageUserState $state, array $off, CarbonImmutable $today): ?Signal
    {
        if ($state->class_available_since !== null) {
            return null;
        }

        $created = UsageDates::day($user->created_at ?? $today);
        [$effective, $excluded] = $this->effectiveDays($created, $today, $off);

        if ($effective < (int) config('usage.signals.no_class.days')) {
            return null;
        }

        return new Signal(
            self::NO_CLASS,
            self::label(self::NO_CLASS),
            sprintf(
                'Conta criada a %s e sem nenhuma turma disponível há %s %s.',
                UsageDates::short($created),
                self::days($effective),
                $excluded > 0 ? '(sem contar '.self::nonTeaching($excluded).')' : '(dias de calendário)',
            ),
            UsageDates::iso($created),
            UsageDates::iso($today),
        );
    }

    /**
     * @param  array<string, true>  $off
     * @return Signal|false|null Signal = sinal; false = por avaliar (cobertura); null = nada
     */
    private function noFirstRecord(UsageUserState $state, array $off, CarbonImmutable $today, CarbonImmutable $now): Signal|false|null
    {
        if ($state->class_available_since === null || $state->first_useful_record_on !== null) {
            return null;
        }

        $since = UsageDates::day($state->class_available_since);
        [$effective, $excluded] = $this->effectiveDays($since, $today, $off);

        if ($effective < (int) config('usage.signals.no_first_record.days')) {
            return null;
        }

        // Se a recolha começou DEPOIS de a turma ficar disponível, não se sabe o
        // que aconteceu entre as duas coisas: por avaliar, não sinal.
        if (! $this->coverage->fullyCoveredSince(CarbonImmutable::instance($state->class_available_since), $now)) {
            return false;
        }

        return new Signal(
            self::NO_FIRST_RECORD,
            self::label(self::NO_FIRST_RECORD),
            sprintf(
                'Turma disponível desde %s e nenhum registo útil observado desde então (%s).',
                UsageDates::short($since),
                $excluded > 0
                    ? self::days($effective).', sem contar '.self::nonTeaching($excluded)
                    : self::days($effective).' de calendário',
            ),
            UsageDates::iso($since),
            UsageDates::iso($today),
        );
    }

    /**
     * @param  array<string, true>  $activeDays
     * @param  array<string, true>  $off
     */
    private function wentQuiet(User $user, array $activeDays, array $off, CarbonImmutable $today, CarbonImmutable $now): Signal|false|null
    {
        $quietDays = (int) config('usage.signals.went_quiet.quiet_days');
        $lookbackWeeks = (int) config('usage.signals.went_quiet.lookback_weeks');
        $minActiveWeeks = (int) config('usage.signals.went_quiet.min_active_weeks');

        // A janela de silêncio: os últimos `quiet_days` dias EFETIVOS até hoje,
        // a recuar e a saltar os dias não letivos.
        $windowStart = $today;
        $counted = 0;
        for ($day = $today, $steps = 0; $counted < $quietDays && $steps <= self::LOOKBACK_CAP_DAYS; $day = $day->subDay(), $steps++) {
            $windowStart = $day;
            if (! isset($off[UsageDates::iso($day)])) {
                $counted++;
            }
        }

        foreach (UsageDates::eachDay($windowStart, $today) as $day) {
            if (isset($activeDays[UsageDates::iso($day)])) {
                return null;
            }
        }

        $firstMonday = UsageDates::monday($windowStart)->subWeeks($lookbackWeeks);
        $lastSunday = UsageDates::monday($windowStart)->subDay();

        // Uma conta que ainda nem existia durante as semanas de referência não
        // tem como ter tido atividade nelas.
        if (UsageDates::day($user->created_at ?? $today)->greaterThan($lastSunday)) {
            return null;
        }

        if (! $this->coverage->fullyCoveredSince($firstMonday, $now)) {
            return false;
        }

        $activeWeeks = 0;
        for ($monday = $firstMonday; $monday->lessThanOrEqualTo($lastSunday); $monday = $monday->addWeek()) {
            foreach (UsageDates::eachDay($monday, $monday->addDays(6)) as $day) {
                if (isset($activeDays[UsageDates::iso($day)])) {
                    $activeWeeks++;

                    break;
                }
            }
        }

        if ($activeWeeks < $minActiveWeeks) {
            return null;
        }

        $excluded = 0;
        foreach (UsageDates::eachDay($windowStart, $today) as $day) {
            if (isset($off[UsageDates::iso($day)])) {
                $excluded++;
            }
        }

        return new Signal(
            self::WENT_QUIET,
            self::label(self::WENT_QUIET),
            sprintf(
                'Atividade em %d das %d semanas de %s a %s e nenhuma nos últimos %d dias (desde %s%s).',
                $activeWeeks,
                $lookbackWeeks,
                UsageDates::short($firstMonday),
                UsageDates::short($lastSunday),
                $quietDays,
                UsageDates::short($windowStart),
                $excluded > 0 ? ', sem contar '.self::nonTeaching($excluded) : '',
            ),
            UsageDates::iso($firstMonday),
            UsageDates::iso($today),
        );
    }

    /**
     * @param  array<string, int>  $byFeature  funcionalidade => falhas na janela
     */
    private function technicalFailures(array $byFeature, CarbonImmutable $today): ?Signal
    {
        $days = (int) config('usage.signals.technical_failures.days');
        $threshold = (int) config('usage.signals.technical_failures.threshold');
        $total = array_sum($byFeature);

        if ($total < $threshold || $total === 0) {
            return null;
        }

        $from = $today->subDays($days - 1);
        $startDay = $this->coverage->startDay();
        $startedInside = $startDay !== null && $startDay->greaterThan($from);
        if ($startedInside) {
            $from = $startDay;
        }

        arsort($byFeature);
        $parts = [];
        foreach ($byFeature as $featureKey => $count) {
            $feature = UsageFeature::tryFrom((string) $featureKey);
            $parts[] = ($feature?->label() ?? $featureKey).': '.$count;
        }

        return new Signal(
            self::TECHNICAL_FAILURES,
            self::label(self::TECHNICAL_FAILURES),
            sprintf(
                '%d %s %s (%s).',
                $total,
                $total === 1 ? 'falha técnica' : 'falhas técnicas',
                $startedInside
                    ? 'desde '.UsageDates::short($from).' (início da recolha) até '.UsageDates::short($today)
                    : 'entre '.UsageDates::short($from).' e '.UsageDates::short($today),
                implode(', ', $parts),
            ),
            UsageDates::iso($from),
            UsageDates::iso($today),
        );
    }

    /**
     * Dias efetivos entre `$since` (exclusivo) e `$to` (inclusivo): os dias de
     * calendário menos os não letivos.
     *
     * @param  array<string, true>  $off
     * @return array{0: int, 1: int} [efetivos, dias não letivos excluídos]
     */
    private function effectiveDays(CarbonImmutable $since, CarbonImmutable $to, array $off): array
    {
        $effective = 0;
        $excluded = 0;

        for ($day = $since->addDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            if (isset($off[UsageDates::iso($day)])) {
                $excluded++;
            } else {
                $effective++;
            }
        }

        return [$effective, $excluded];
    }

    /**
     * Os dias com atividade de cada pessoa, sobre toda a atividade dela.
     *
     * @param  list<int>  $userIds
     * @return array<int, array<string, true>>
     */
    private function activeDays(array $userIds, CarbonImmutable $from, CarbonImmutable $today): array
    {
        $result = [];

        foreach (array_chunk($userIds, 500) as $chunk) {
            $rows = DB::table('usage_daily_rollups')
                ->whereIn('user_id', $chunk)
                ->where('day', '>=', UsageDates::iso($from))
                ->where('day', '<', UsageDates::iso($today->addDay()))
                ->where(fn ($query) => $query->where('views', '>', 0)->orWhere('actions', '>', 0))
                ->groupBy('user_id', 'day')
                ->get(['user_id', 'day']);

            foreach ($rows as $row) {
                $result[(int) $row->user_id][substr((string) $row->day, 0, 10)] = true;
            }
        }

        return $result;
    }

    /**
     * As falhas dos últimos dias, por pessoa e funcionalidade.
     *
     * @param  list<int>  $userIds
     * @return array<int, array<string, int>>
     */
    private function failures(array $userIds, CarbonImmutable $today): array
    {
        $from = $today->subDays(((int) config('usage.signals.technical_failures.days')) - 1);
        $result = [];

        foreach (array_chunk($userIds, 500) as $chunk) {
            $rows = DB::table('usage_daily_rollups')
                ->whereIn('user_id', $chunk)
                ->where('day', '>=', UsageDates::iso($from))
                ->where('day', '<', UsageDates::iso($today->addDay()))
                ->where('failures', '>', 0)
                ->selectRaw('user_id, feature, sum(failures) as failures')
                ->groupBy('user_id', 'feature')
                ->get();

            foreach ($rows as $row) {
                $result[(int) $row->user_id][(string) $row->feature] = (int) $row->failures;
            }
        }

        return $result;
    }
}
