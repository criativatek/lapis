<?php

namespace App\Services\Usage\Insights;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Aritmética de DIAS DE CALENDÁRIO no fuso da aplicação.
 *
 * Nunca se divide uma diferença de horas por 24: o dia da mudança de hora tem
 * 23 ou 25, e uma contagem por horas dá um dia a menos (ou a mais) nessas
 * semanas. Aqui conta-se sempre dia a dia, com `addDay()` sobre datas — o
 * intervalo máximo do painel são 366 dias, por isso o custo é irrelevante.
 */
final class UsageDates
{
    public static function day(CarbonInterface|string $moment): CarbonImmutable
    {
        $timezone = (string) config('app.timezone');

        if (is_string($moment)) {
            return CarbonImmutable::parse(substr($moment, 0, 10), $timezone)->startOfDay();
        }

        return CarbonImmutable::instance($moment)->setTimezone($timezone)->startOfDay();
    }

    public static function today(): CarbonImmutable
    {
        return self::day(CarbonImmutable::now());
    }

    /**
     * Os dias do intervalo, inclusive, como meias-noites locais.
     *
     * @return list<CarbonImmutable>
     */
    public static function eachDay(CarbonInterface $from, CarbonInterface $to): array
    {
        $days = [];
        $last = self::day($to);

        for ($day = self::day($from); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $days[] = $day;
        }

        return $days;
    }

    /** Quantos dias tem o intervalo, inclusive. */
    public static function countInclusive(CarbonInterface $from, CarbonInterface $to): int
    {
        return count(self::eachDay($from, $to));
    }

    /** Quantos dias passaram de `$from` até `$to` (0 se for o mesmo dia). */
    public static function elapsed(CarbonInterface $from, CarbonInterface $to): int
    {
        $start = self::day($from);
        $end = self::day($to);

        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        return count(self::eachDay($start, $end)) - 1;
    }

    public static function monday(CarbonInterface $moment): CarbonImmutable
    {
        return self::day($moment)->startOfWeek(CarbonInterface::MONDAY);
    }

    /** Domingo da semana (segunda a domingo) que contém o dia. */
    public static function sunday(CarbonInterface $moment): CarbonImmutable
    {
        return self::monday($moment)->addDays(6);
    }

    /** `dd/mm`. */
    public static function short(CarbonInterface $moment): string
    {
        return self::day($moment)->format('d/m');
    }

    /** `Y-m-d` — a data tal como as props a levam. */
    public static function iso(CarbonInterface $moment): string
    {
        return self::day($moment)->toDateString();
    }
}
