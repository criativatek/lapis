<?php

namespace App\Services\Reporting;

use App\Models\Report;
use App\Models\ReportType;
use App\Services\Documents\DocumentIdentity;
use App\Services\Reporting\Source\ClassReportSource;
use App\Services\Reporting\Source\RecordsReportSource;
use App\Services\Reporting\Source\ReportSource;
use App\Services\Reporting\Source\SchoolReportSource;
use App\Services\Reporting\Source\StudentReportSource;

/**
 * Assembles the one context a whole generation runs on.
 *
 * WHICH SOURCE ANSWERS FOR WHICH TYPE, in one place. Adding a report type means
 * adding a source and a line here — not a branch inside every composer.
 *
 * THE LETTERHEAD COMES FROM DocumentIdentity AND NOWHERE ELSE (§39, §50). A
 * report never queries `organization_identities`, never decides what to do when
 * a field is blank and never re-derives a display name: that service already
 * answers all of it, and its answer is the one that will be frozen into the
 * document at finalization.
 */
class ReportContextFactory
{
    public function __construct(
        protected ClassReportSource $classSource,
        protected StudentReportSource $studentSource,
        protected RecordsReportSource $recordsSource,
        protected SchoolReportSource $schoolSource,
        protected DocumentIdentity $identity,
        protected ReportCapabilities $capabilities,
    ) {}

    public function for(Report $report): ReportContext
    {
        return new ReportContext(
            report: $report,
            facts: $this->sourceFor($report->type)->factsFor($report),
            identity: $this->identity->forCurrentOrganization(),
            capabilities: $this->capabilities,
        );
    }

    protected function sourceFor(ReportType $type): ReportSource
    {
        return match ($type) {
            ReportType::SchoolClass => $this->classSource,
            // The individual report reads the same class facts and then narrows
            // to one student: a student's numbers and their class's numbers come
            // from the same single read, and must agree.
            ReportType::Student => $this->studentSource,
            ReportType::Records => $this->recordsSource,
            ReportType::School => $this->schoolSource,
        };
    }
}
