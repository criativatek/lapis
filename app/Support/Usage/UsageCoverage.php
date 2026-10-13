<?php

namespace App\Support\Usage;

use App\Models\UsageCollectionPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Em que dias houve recolha (docs/usage-insights.md, «Cobertura dos dados»).
 *
 * A recolha está LIGADA quando há um período aberto em
 * `usage_collection_periods` — é a única fonte de verdade, sem nenhuma chave de
 * configuração a contradizê-la. Só `App\Actions\Usage\SetUsageCollection`
 * (`lapis:usage-collection start|stop`) abre e fecha períodos, no instante da
 * transição: nenhum evento, pedido ou agregação o faz.
 *
 * Um dia fora de qualquer período é «sem recolha» e nunca zero: quem lê esta
 * classe devolve `null`, não `0`. O mesmo vale para um dia de
 * `usage_coverage_gaps` — houve recolha, mas os eventos expiraram antes de
 * serem agregados, e o que não se sabe não se mostra como zero. O dia em que a
 * recolha começa (ou termina) está coberto mas não COMPLETAMENTE coberto.
 *
 * Tudo no fuso da aplicação, e os dias são dias de calendário locais: soma-se
 * `addDay()` a uma data, nunca `addHours(24)` — o dia da mudança de hora tem 23
 * ou 25 horas e ainda assim é um dia.
 */
class UsageCoverage
{
    /** @var list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>|null */
    private ?array $intervals = null;

    /** @var list<string>|null */
    private ?array $gapDays = null;

    /**
     * A recolha está ligada: há um período aberto. Lido sempre da base, sem a
     * memória da instância — um `stop` a meio de um processo longo conta já.
     */
    public function isActive(): bool
    {
        try {
            return UsageCollectionPeriod::query()->where('is_open', true)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** O fim do último período fechado, ou nulo se nenhum foi fechado. */
    public function lastEndedAt(): ?CarbonImmutable
    {
        $ended = UsageCollectionPeriod::query()->max('ended_at');

        return is_string($ended) && $ended !== '' ? CarbonImmutable::parse($ended, (string) config('app.timezone')) : null;
    }

    /** O início do período aberto, ou nulo com a recolha desligada. */
    public function openSince(): ?CarbonImmutable
    {
        $started = UsageCollectionPeriod::query()->where('is_open', true)->value('started_at');

        return $started === null ? null : $this->local(CarbonImmutable::parse($started, (string) config('app.timezone')));
    }

    /**
     * O instante da PRIMEIRA ativação, ou nulo se a recolha nunca esteve ativa.
     * É o que a Política de Privacidade lê para saber se descreve a recolha e
     * desde quando (App\Support\Legal\PrivacyVersion). Sem tabela (uma base
     * por migrar), a resposta é «nunca»: não há recolha sem tabela onde gravar.
     */
    public static function firstActivation(): ?CarbonImmutable
    {
        try {
            $first = UsageCollectionPeriod::query()->min('started_at');
        } catch (\Throwable) {
            return null;
        }

        return is_string($first) && $first !== ''
            ? CarbonImmutable::parse($first, (string) config('app.timezone'))
            : null;
    }

    /**
     * Os períodos de recolha, por ordem, com os contíguos fundidos e SEM os dias
     * de `usage_coverage_gaps` (cada um parte o período em que cai).
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable|null}> [início, fim|null se em curso]
     */
    public function periods(): array
    {
        if ($this->intervals !== null) {
            return $this->intervals;
        }

        $merged = [];

        foreach (UsageCollectionPeriod::query()->orderBy('started_at')->get() as $period) {
            $start = $this->local($period->started_at);
            $end = $period->ended_at === null ? null : $this->local($period->ended_at);
            $lastIndex = array_key_last($merged);

            if ($lastIndex !== null) {
                $previousEnd = $merged[$lastIndex][1];

                // Um período aberto já cobre tudo o que vem depois; um fechado
                // só se funde se tocar neste.
                if ($previousEnd === null || $start->lessThanOrEqualTo($previousEnd)) {
                    $merged[$lastIndex][1] = ($previousEnd === null || $end === null) ? null : $this->latest($previousEnd, $end);

                    continue;
                }
            }

            $merged[] = [$start, $end];
        }

        return $this->intervals = $this->withoutGaps($merged);
    }

    /**
     * Os dias (AAAA-MM-DD) em que houve recolha mas os eventos expiraram antes
     * de serem agregados.
     *
     * @return list<string>
     */
    public function gaps(): array
    {
        if ($this->gapDays !== null) {
            return $this->gapDays;
        }

        try {
            $days = DB::table('usage_coverage_gaps')->orderBy('day')->pluck('day')->all();
        } catch (\Throwable) {
            $days = [];
        }

        return $this->gapDays = array_values(array_map(fn (mixed $day): string => substr((string) $day, 0, 10), $days));
    }

    /**
     * O instante em que a recolha começou pela primeira vez — a ativação, não o
     * primeiro dia com dados (que uma lacuna pode ter levado).
     */
    public function startedAt(): ?CarbonImmutable
    {
        $first = self::firstActivation();

        return $first === null ? null : $this->local($first);
    }

    /** O dia (meia-noite local) em que a recolha começou pela primeira vez. */
    public function startDay(): ?CarbonImmutable
    {
        return $this->startedAt()?->startOfDay();
    }

    /** Algum período toca este dia. */
    public function isDayCovered(CarbonInterface $day): bool
    {
        $start = $this->day($day);
        $end = $start->addDay();

        foreach ($this->periods() as [$from, $to]) {
            if ($from->lessThan($end) && ($to === null || $to->greaterThan($start))) {
                return true;
            }
        }

        return false;
    }

    /** Há recolha em todo o dia, da meia-noite à meia-noite seguinte. */
    public function isDayFullyCovered(CarbonInterface $day): bool
    {
        $start = $this->day($day);

        return $this->coversWholeSpan($start, $start->addDay());
    }

    /**
     * Os dias do intervalo (inclusive) que têm recolha, pelo menos em parte.
     *
     * @return list<CarbonImmutable>
     */
    public function coveredDaysIn(CarbonInterface $from, CarbonInterface $to): array
    {
        $days = [];
        $last = $this->day($to);

        for ($day = $this->day($from); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            if ($this->isDayCovered($day)) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /** Não há nenhum buraco na recolha entre `$instant` e `$to`. */
    public function fullyCoveredSince(CarbonInterface $instant, CarbonInterface $to): bool
    {
        return $this->coversWholeSpan($this->local($instant), $this->local($to));
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>  $intervals
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>
     */
    private function withoutGaps(array $intervals): array
    {
        $gaps = $this->gaps();

        if ($gaps === []) {
            return $intervals;
        }

        $timezone = (string) config('app.timezone');

        foreach ($gaps as $gap) {
            $gapStart = CarbonImmutable::parse($gap, $timezone)->startOfDay();
            $gapEnd = $gapStart->addDay();
            $split = [];

            foreach ($intervals as [$from, $to]) {
                $overlaps = $from->lessThan($gapEnd) && ($to === null || $to->greaterThan($gapStart));

                if (! $overlaps) {
                    $split[] = [$from, $to];

                    continue;
                }

                if ($from->lessThan($gapStart)) {
                    $split[] = [$from, $gapStart];
                }

                if ($to === null || $to->greaterThan($gapEnd)) {
                    $split[] = [$gapEnd, $to];
                }
            }

            $intervals = $split;
        }

        return $intervals;
    }

    private function coversWholeSpan(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($this->periods() as [$from, $until]) {
            if ($from->lessThanOrEqualTo($start) && ($until === null || $until->greaterThanOrEqualTo($end))) {
                return true;
            }
        }

        return false;
    }

    private function local(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone((string) config('app.timezone'));
    }

    private function day(CarbonInterface $moment): CarbonImmutable
    {
        return $this->local($moment)->startOfDay();
    }

    private function latest(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->greaterThan($b) ? $a : $b;
    }
}
