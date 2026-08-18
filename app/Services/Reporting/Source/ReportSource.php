<?php

namespace App\Services\Reporting\Source;

use App\Models\Report;

/**
 * WHERE A REPORT'S FACTS COME FROM.
 *
 * The single rule of this module is that reports do not compute (§1): the
 * pipeline is canonical data → structured interpretation → text → human review
 * → document, and never data → a second calculation inside the report. A source
 * is the first arrow. It reads the read models that already exist — for a class
 * report, exactly one call to BuildClassStatistics, which itself makes exactly
 * one call to BuildResultsProgression — and hands back facts.
 *
 * IT NORMALISES, WHICH IS NOT THE SAME AS CALCULATING. Live statistics and a
 * kept photograph have different shapes: one carries `label`, the other
 * `label_snapshot`; one knows which reading is primary at this moment, the
 * other never recorded that. A source flattens both into one vocabulary so that
 * composers do not each grow a branch for it — and where a snapshot simply did
 * not record something, the fact is null and the sentence says «não registado»
 * rather than inventing a zero (§30, §41).
 *
 * NOTHING HERE PRODUCES A SENTENCE. Facts in, facts out.
 */
interface ReportSource
{
    /**
     * @return array<string, mixed>
     */
    public function factsFor(Report $report): array;
}
