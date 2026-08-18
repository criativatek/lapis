<?php

namespace App\Services\Reporting;

use App\Models\Report;
use App\Services\Reporting\Source\ReportSource;

/**
 * The source for a report type whose own source has not been built yet.
 *
 * IT REPORTS ABSENCE, WHICH IS THE HONEST ANSWER. `available => false` makes
 * every composer take the «não existem dados» path, so a half-built type
 * produces an empty draft the teacher can see is empty — never a document full
 * of confident sentences assembled from defaults (§41, §67).
 */
class NullReportSource implements ReportSource
{
    /**
     * @return array<string, mixed>
     */
    public function factsFor(Report $report): array
    {
        return ['available' => false, 'origin' => 'none'];
    }
}
