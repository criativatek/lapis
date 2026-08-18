<?php

namespace App\Models;

/**
 * WHICH STRETCH OF TIME A REPORT SPEAKS ABOUT (§29).
 *
 * Every report has one, always, and it is never inferred at print time. A
 * sentence like «a média da turma foi de 66,4%» is meaningless — and quietly
 * wrong — without knowing whether it describes one period, the year so far, or
 * a fortnight of logbook entries.
 *
 * The distinction between Period and Accumulated is the same one the assessment
 * core already makes and must not be blurred here: Média Ponderada is a period
 * read alone, Média Ponderada Acumulada is the continuous reading (§7). This
 * enum only records which of the two a report was asked for; it never decides
 * it. PrimaryResultScope does that, from the profile version's own periods.
 */
enum ReportScopeKind: string
{
    /** One period, read alone. */
    case Period = 'period';

    /** The continuous reading up to and including a period. */
    case Accumulated = 'accumulated';

    /** A kept photograph — an avaliação intercalar (§30). */
    case Interim = 'interim';

    /** An explicit pair of dates, as the logbook reports use. */
    case DateRange = 'date_range';

    /** The whole academic year. */
    case Year = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Period => __('Período isolado'),
            self::Accumulated => __('Acumulado'),
            self::Interim => __('Avaliação intercalar'),
            self::DateRange => __('Intervalo de datas'),
            self::Year => __('Ano letivo'),
        };
    }

    /**
     * Whether this scope reads a snapshot instead of live data. A report built
     * on a photograph must never be reconstructed from today's numbers (§30).
     */
    public function readsSnapshot(): bool
    {
        return $this === self::Interim;
    }

    public function needsDates(): bool
    {
        return $this === self::DateRange;
    }
}
