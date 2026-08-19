<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Services\Audit\AuditLog;
use App\Services\Reporting\Export\DocxRenderer;
use App\Services\Reporting\Export\PdfRenderer;
use App\Services\Reporting\Export\ReportDocumentBuilder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Taking a report away as a file (§47, §65).
 *
 * TWO FORMATS, ONE CONTENT. Both handlers build the same structure and hand it
 * to a renderer; neither reads the report itself. A difference between the PDF
 * and the Word file would have to be a difference between the two renderers,
 * not between two ideas of what the document says.
 *
 * NOTHING IS WRITTEN TO A PUBLIC PATH. The bytes are produced in memory and
 * streamed straight back to the browser under an authorized route, so there is
 * no file left anywhere for anyone to find — which is the correct shape for a
 * document summarising the grades of minors. The two temporary files PhpWord
 * needs live in the system temp directory for the duration of one request and
 * are removed in a `finally`.
 *
 * EXPORTING IS AUDITED. A report leaving the application is exactly the kind of
 * event §22.5 wants a trail for, and the pauta export already sets that
 * precedent.
 */
class ReportExportController extends Controller
{
    public function __construct(
        protected ReportDocumentBuilder $builder,
        protected AuditLog $audit,
    ) {}

    public function pdf(Report $report, PdfRenderer $renderer): Response
    {
        Gate::authorize('export', $report);

        $bytes = $renderer->render($this->builder->build($this->loaded($report)));

        $this->record($report, 'pdf');

        return $this->download($bytes, $report, 'pdf', 'application/pdf');
    }

    public function docx(Report $report, DocxRenderer $renderer): Response
    {
        Gate::authorize('export', $report);

        $bytes = $renderer->render($this->builder->build($this->loaded($report)));

        $this->record($report, 'docx');

        return $this->download(
            $bytes,
            $report,
            'docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );
    }

    protected function loaded(Report $report): Report
    {
        $report->load(['sections', 'author', 'schoolClass.subject', 'enrollment.student.identity', 'organization.identity']);

        return $report;
    }

    protected function download(string $bytes, Report $report, string $extension, string $mime): Response
    {
        // The report's own title, slugged — a teacher downloading four reports
        // should be able to tell them apart in their Downloads folder without
        // opening them.
        $name = str($report->title)->slug()->limit(80, '')->value();

        if ($name === '') {
            $name = 'relatorio';
        }

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => "attachment; filename=\"{$name}.{$extension}\"",
            // Never cached by a proxy: this is one person's copy of one
            // document about identifiable minors.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    protected function record(Report $report, string $format): void
    {
        $this->audit->record(
            'report.exported',
            $report,
            summary: "Relatório «{$report->title}» exportado em ".strtoupper($format).'.',
            properties: ['format' => $format, 'status' => $report->status->value],
        );
    }
}
