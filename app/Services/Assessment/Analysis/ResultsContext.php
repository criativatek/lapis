<?php

namespace App\Services\Assessment\Analysis;

use App\Domain\Assessment\Analysis\AnalysisBand;
use App\Domain\Assessment\Analysis\Observation;

/**
 * What any results-analysis context (instrument, interim, period, semester —
 * design spec §2/§7) has to deliver to `ResultsAnalyzer` and
 * `DescriptiveReport`. Only `InstrumentResultsContext` exists in this phase;
 * the interim (B) and period/semester (C) contexts implement this same
 * interface later, without touching the analyzer or the report.
 */
interface ResultsContext
{
    /**
     * Everything the report's identification section and the payload's
     * `context` need: title, class, period, type, status, purpose, dates.
     *
     * @return array<string, mixed>
     */
    public function identification(): array;

    /**
     * Whether this context's results contribute to a classificatory average.
     * False for a diagnostic instrument (design spec §4).
     */
    public function classificatory(): bool;

    /**
     * The dimensions this context offers: `global` first, then one per domain
     * actually touched. Each entry carries the key, a label, and the list of
     * `Observation` for that dimension — one per student in the universe.
     *
     * @return list<array{key: string, label: string, observations: list<Observation>}>
     */
    public function dimensions(): array;

    /**
     * The scale's bands, in sequence order — empty when the scale has none.
     *
     * @return list<AnalysisBand>
     */
    public function bands(): array;

    /**
     * Methodological notes, already written in pt-PT, for the payload and the
     * report to show as-is.
     *
     * @return list<string>
     */
    public function notes(): array;
}
