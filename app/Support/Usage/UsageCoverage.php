<?php

namespace App\Support\Usage;

use App\Models\UsageCollectionPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Em que dias houve recolha (docs/usage-insights.md, «Cobertura dos dados»).
 *
 * Um dia fora de qualquer período de `usage_collection_periods` é «sem recolha»
 * e nunca zero: quem lê esta classe devolve `null`, não `0`. O dia em que a
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

    /**
     * Abre um período se a recolha está ligada e nenhum está aberto; fecha o
     * aberto se a recolha foi desligada.
     */
    public function sync(CarbonInterface $now): void
    {
        $now = $this->local($now);
        $open = UsageCollectionPeriod::query()->whereNull('ended_at')->get();

        if (config('usage.enabled')) {
            if ($open->isEmpty()) {
                UsageCollectionPeriod::query()->create(['started_at' => $now]);
            }
        } else {
            foreach ($open as $period) {
                $period->forceFill(['ended_at' => $now])->save();
            }
        }

        $this->intervals = null;
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable|null}> [início, fim|null se em curso], por ordem, com os contíguos fundidos
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

        return $this->intervals = $merged;
    }

    /** O instante em que a recolha começou pela primeira vez. */
    public function startedAt(): ?CarbonImmutable
    {
        return $this->periods()[0][0] ?? null;
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
