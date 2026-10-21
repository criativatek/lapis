<?php

namespace App\Http\Controllers;

use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Services\Audit\AuditLog;
use App\Services\Documents\Pdf\DompdfPrinter;
use App\Services\Documents\Pdf\LetterheadLayout;
use App\Services\Documents\Pdf\PaperOrientation;
use App\Services\Reporting\ClassDocuments\InstrumentGrid;
use App\Services\Reporting\ClassDocuments\InstrumentGridOptions;
use App\Services\Reporting\ClassDocuments\InstrumentGridUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The printable grid of one instrument — the preview and the PDF.
 *
 * A DOCUMENT MADE WHEN IT IS ASKED FOR, NOT A REPORT. Nothing is stored and
 * nothing is written to the assessment: both the page and the PDF are built on
 * the spot from the instrument as it stands (InstrumentGrid), from the SAME
 * structure — the page receives it untouched and the PDF's Blade reads nothing
 * else — so what is on the screen is what is printed. A teacher who corrects
 * another cell and asks again gets the new grid.
 *
 * AUTHORIZED LIKE THE CLASS ITSELF, the same gate the correction grid and
 * Resultados use: whoever teaches the class (SchoolClassPolicy::view). A
 * colleague who does not gets a 403; an instrument of another organization does
 * not resolve at all, because the route binding runs through the tenant scope.
 * Both are GETs, so they also work while the account is read-only.
 *
 * THE OPTIONS ARE PRESENTATION. They arrive in the query string and are read
 * against a whitelist (InstrumentGridOptions::fromQuery): a forged value is the
 * default, and none of them ever reaches a query that could widen what the
 * teacher may see.
 *
 * WHERE IT DOES NOT APPLY. An instrument still being prepared has no grid to
 * print — the editor is where it goes on, as the correction grid itself does. An
 * annulled one has no results to put on paper: back to its page, with the reason.
 * The same for the PDF, which a browser reaches through a plain link.
 */
class InstrumentGridDocumentController extends Controller
{
    public function __construct(
        protected InstrumentGrid $grid,
        protected AuditLog $audit,
    ) {}

    public function show(Request $request, Instrument $instrument): Response|RedirectResponse
    {
        $document = $this->document($request, $instrument);

        if ($document instanceof RedirectResponse) {
            return $document;
        }

        return Inertia::render('instruments/GridDocument', [
            // What the page needs to link back — no numeric id leaves here.
            'instrument' => [
                'ulid' => $instrument->ulid,
                'title' => $instrument->title,
                'class_ulid' => $instrument->schoolClass->ulid,
            ],
            'document' => $document,
        ]);
    }

    /**
     * The same document as a PDF, streamed straight back and never stored: it is
     * one person's copy of a list of identifiable minors and their marks.
     */
    public function pdf(Request $request, Instrument $instrument, DompdfPrinter $printer): HttpResponse|RedirectResponse
    {
        $document = $this->document($request, $instrument);

        if ($document instanceof RedirectResponse) {
            return $document;
        }

        // A wide grid is landscape, and landscape takes the compact letterhead:
        // more columns across and more rows down, which is what a grid is for.
        $orientation = PaperOrientation::from($document['orientation']);
        $layout = $orientation === PaperOrientation::Landscape ? LetterheadLayout::Compact : LetterheadLayout::Standard;

        $bytes = $printer->print(
            View::make('instruments.grid-document', ['document' => $document, 'layout' => $layout])->render(),
            $orientation,
            $layout,
        );

        // Counts, the options and the instrument's own title — never a student's
        // name or mark. Exporting is the moment a list of students and their
        // results leaves the application, which is what the trail is for.
        $this->audit->record(
            'instruments.grid_document.exported',
            $instrument,
            summary: "Grelha «{$instrument->title}» ({$instrument->schoolClass->label}) exportada em PDF.",
            properties: [
                'format' => 'pdf',
                'orientation' => $document['orientation'],
                'options' => $document['options'],
                'provisional' => $document['state']['is_provisional'],
                'students' => $document['summary']['students'],
                'excluded' => $document['summary']['excluded'],
                'questions' => $document['summary']['questions'],
                'blocks' => $document['summary']['blocks'],
            ],
        );

        // The class, the title and the day — never a student's name.
        $name = str("grelha {$instrument->schoolClass->label} {$instrument->title} {$instrument->applied_on->toDateString()}")
            ->slug()
            ->limit(80, '')
            ->value();

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$name}.pdf\"",
            // Never cached by a proxy: this is one person's copy of one
            // document about identifiable minors.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * The authorised structure for this request, or where to send the teacher
     * instead. Preview and PDF go through here, so they cannot disagree about
     * who may see what, about the options, or about what has no grid.
     *
     * @return array<string, mixed>|RedirectResponse
     */
    protected function document(Request $request, Instrument $instrument): array|RedirectResponse
    {
        Gate::authorize('view', $instrument->schoolClass);

        if ($instrument->status === InstrumentStatus::Draft) {
            return to_route('instruments.edit', $instrument->ulid);
        }

        try {
            return $this->grid->build($instrument, InstrumentGridOptions::fromQuery($request->query()));
        } catch (InstrumentGridUnavailable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return to_route('instruments.show', $instrument->ulid);
        }
    }
}
