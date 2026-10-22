<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\EvaluationSheetExport;
use App\Models\SchoolClass;
use App\Models\SheetMomentKind;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\Export\EvaluationSheetDocument;
use App\Services\Audit\AuditLog;
use App\Services\Documents\DocumentIdentity;
use App\Services\Documents\Pdf\DompdfPrinter;
use App\Services\Documents\Pdf\LetterheadLayout;
use App\Services\Documents\Pdf\PaperOrientation;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGrid;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGridOptions;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The printable grid of a class — the Pauta de Avaliação, live and as kept:
 * the preview and the PDF.
 *
 * A DOCUMENT MADE WHEN IT IS ASKED FOR, NOT A REPORT. Nothing is stored and
 * nothing is written to the assessment. The page receives the structure that
 * `EvaluationSheetGrid` composes, untouched, and the PDF's Blade reads nothing
 * else, so what is on the screen is what is on paper. The PDF is streamed straight
 * back and never kept: it is one person's copy of a list of identifiable minors
 * and their classifications — and its export, unlike the preview, leaves a trail
 * of counts and options, never a name.
 *
 * TWO SOURCES, ONE PAGE, AND THEY NEVER CROSS.
 *
 *  - `live` reads the sheet as it stands today, the way the screen does but
 *    WITHOUT the proposal basis (`withProposalBasis: false`): the grid does not
 *    read «a proposta de hoje» or «desatualizada», and in the last unit of the
 *    year those cost the results of every other unit. The moment (`?momento=`)
 *    only titles the document; both moments read the same sheet.
 *  - `snapshot` reads a kept moment from its frozen payload and from nothing
 *    else. The sheet builder is a parameter of the `live` actions ALONE — the
 *    kept ones are given no builder and no calculator, so they have no way to reach
 *    the present: a kept moment that picked up a later change would be a false
 *    statement about the past (§16). Same four doors as the CSV and the Excel of a
 *    kept moment — a class this teacher may see, a record OF THAT class (a ulid is
 *    not a key to a colleague's class), an intact seal.
 *
 * AUTHORISED LIKE THE PAUTA ITSELF: whoever may see the class
 * (`SchoolClassPolicy::view`), inside the `results` module (Base). A colleague
 * who does not teach it gets a 403; a class, period or record of another
 * organization does not resolve at all, because every binding runs through the
 * tenant scope. GETs, so they also work while the account is read-only.
 *
 * THE OPTIONS ARE PRESENTATION. They arrive in the query string and are read
 * against a whitelist (`EvaluationSheetGridOptions::fromQuery`).
 *
 * WHAT THIS ADDS TO THE GRID is what belongs to the act of printing and not to
 * the data: the school's letterhead and the moment of issue.
 */
class EvaluationSheetGridDocumentController extends Controller
{
    public function __construct(
        protected EvaluationSheetGrid $grid,
        protected DocumentIdentity $identity,
        protected AuditLog $audit,
    ) {}

    /**
     * The sheet as it stands today, for one period and one of its two moments.
     */
    public function live(Request $request, SchoolClass $class, string $period, BuildEvaluationSheet $builder): Response
    {
        [$document, $source] = $this->liveSource($request, $class, $period, $builder);

        return $this->render($request, $class, $document, $source);
    }

    /**
     * The same document as a PDF, streamed straight back and never stored: it is
     * one person's copy of a list of identifiable minors and their classifications.
     */
    public function livePdf(Request $request, SchoolClass $class, string $period, BuildEvaluationSheet $builder, DompdfPrinter $printer): HttpResponse
    {
        [$document, $source] = $this->liveSource($request, $class, $period, $builder);

        return $this->pdf($request, $class, $document, $source, $printer);
    }

    /**
     * A kept moment, exactly as it was kept.
     */
    public function snapshot(Request $request, SchoolClass $class, EvaluationSheetExport $export): Response
    {
        [$document, $source] = $this->snapshotSource($class, $export);

        return $this->render($request, $class, $document, $source);
    }

    /**
     * A kept moment as a PDF — from the frozen payload and nothing else.
     */
    public function snapshotPdf(Request $request, SchoolClass $class, EvaluationSheetExport $export, DompdfPrinter $printer): HttpResponse
    {
        [$document, $source] = $this->snapshotSource($class, $export);

        return $this->pdf($request, $class, $document, $source, $printer);
    }

    /**
     * The live document and what the page needs to know about where it came from.
     * Preview and PDF go through here, so they cannot disagree about who may see
     * what, which period and moment, or how the sheet is read.
     *
     * @return array{EvaluationSheetDocument, array<string, mixed>}
     */
    protected function liveSource(Request $request, SchoolClass $class, string $period, BuildEvaluationSheet $builder): array
    {
        Gate::authorize('view', $class);

        // Only a period of THIS class's academic year: any other ulid — a period
        // of another year or of another organization — is not a valid request.
        $selected = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->where('ulid', $period)
            ->firstOrFail();

        $moment = SheetMomentKind::fromRequest($request->query('momento') === null ? null : (string) $request->query('momento'));

        $class->loadMissing(['subject', 'academicYear', 'organization.identity']);

        $sheet = $builder->for($class, $selected, ClassificationScope::Period, withProposalBasis: false);

        return [
            EvaluationSheetDocument::fromLiveSheet($class, $selected, $sheet, ClassificationScope::Period, $moment),
            [
                'kind' => 'live',
                'moment' => $moment->value,
                'period_ulid' => $selected->ulid,
                'back' => [
                    'href' => "/classes/{$class->ulid}/pauta-avaliacao/{$selected->ulid}".($moment === SheetMomentKind::Final ? '' : "?momento={$moment->value}"),
                    'label' => 'Voltar à pauta',
                ],
                'base_url' => "/classes/{$class->ulid}/pauta-avaliacao/impressao/{$selected->ulid}",
                // Carried by every change of option, so choosing one never changes the moment.
                'fixed_query' => $moment === SheetMomentKind::Final ? [] : ['momento' => $moment->value],
            ],
        ];
    }

    /**
     * The kept document, after the same doors as its CSV and its Excel. No sheet
     * builder in sight: this is the part that must not be able to reach today.
     *
     * @return array{EvaluationSheetDocument, array<string, mixed>}
     */
    protected function snapshotSource(SchoolClass $class, EvaluationSheetExport $export): array
    {
        Gate::authorize('view', $class);

        abort_unless((int) $export->class_id === (int) $class->id, 404);

        // FAIL CLOSED. A payload that no longer matches its own seal is not
        // evidence of anything, and printing it would dress it up as the real one.
        if (! $export->isIntact()) {
            Log::warning('Evaluation sheet snapshot failed its integrity check on preview.', [
                'evaluation_sheet_export_ulid' => $export->ulid,
                'class_id' => (int) $export->class_id,
            ]);

            abort(409, 'Esta pauta guardada já não corresponde ao registo original e por isso não pode ser impressa.');
        }

        $class->loadMissing(['organization.identity']);

        return [
            EvaluationSheetDocument::fromSnapshot($export),
            [
                'kind' => 'snapshot',
                'moment' => null,
                'export_ulid' => $export->ulid,
                'back' => [
                    'href' => "/classes/{$class->ulid}/pauta-avaliacao/historico/{$export->ulid}",
                    'label' => 'Voltar à pauta guardada',
                ],
                'base_url' => "/classes/{$class->ulid}/pauta-avaliacao/historico/{$export->ulid}/impressao",
                'fixed_query' => [],
            ],
        ];
    }

    /**
     * The grid and what belongs to the act of printing and not to the data: the
     * school's letterhead and the moment of issue.
     *
     * @return array<string, mixed>
     */
    protected function compose(Request $request, SchoolClass $class, EvaluationSheetDocument $document): array
    {
        $structure = $this->grid->build($document, EvaluationSheetGridOptions::fromQuery($request->query()));

        $issuedAt = now();
        $identity = $this->identity->for($class->organization);

        return [
            ...$structure,
            'identity' => [
                'name' => $identity['name'],
                'header_lines' => $identity['header_lines'],
                'footer_note' => $identity['footer_note'],
                'is_configured' => $identity['is_configured'],
                'logo_url' => null,
                'logo' => null,
            ],
            'issued_at' => $issuedAt->toIso8601String(),
            'issued_label' => __('Emitido a :moment', [
                'moment' => $issuedAt->copy()->timezone((string) config('app.timezone'))->format('d/m/Y, H:i'),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     */
    protected function render(Request $request, SchoolClass $class, EvaluationSheetDocument $document, array $source): Response
    {
        return Inertia::render('evaluation-sheets/GridDocument', [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label],
            'source' => $source,
            'document' => $this->compose($request, $class, $document),
        ]);
    }

    /**
     * The PDF: the SAME structure the page draws, through the shared printer.
     *
     * @param  array<string, mixed>  $source
     */
    protected function pdf(Request $request, SchoolClass $class, EvaluationSheetDocument $document, array $source, DompdfPrinter $printer): HttpResponse
    {
        $structure = $this->compose($request, $class, $document);

        // A Pauta is wide, and landscape takes the compact letterhead: more
        // columns across and more rows down, which is what a grid is for.
        $orientation = PaperOrientation::from($structure['orientation']);
        $layout = $orientation === PaperOrientation::Landscape ? LetterheadLayout::Compact : LetterheadLayout::Standard;

        $bytes = $printer->print(
            View::make('evaluation-sheets.grid-document', ['document' => $structure, 'layout' => $layout])->render(),
            $orientation,
            $layout,
        );

        // Counts, the options and what identifies the document — never a
        // student's name, a mark or a level. Exporting is the moment a list of
        // identifiable minors and their classifications leaves the application,
        // which is what the trail is for.
        $this->audit->record(
            'evaluation_sheets.grid_document.exported',
            $class,
            summary: "Grelha da Pauta de {$class->label} exportada em PDF.",
            properties: [
                'format' => 'pdf',
                'source' => $source['kind'],
                'academic_period_ulid' => $source['period_ulid'] ?? null,
                'moment' => $source['moment'],
                'evaluation_sheet_export_ulid' => $source['export_ulid'] ?? null,
                'orientation' => $structure['orientation'],
                'options' => $structure['options'],
                'provisional' => $structure['state']['is_provisional'],
                'students' => $structure['summary']['students'],
                'domains' => $structure['summary']['domains'],
                'blocks' => $structure['summary']['blocks'],
                'decided' => $structure['summary']['decided'],
                'pending' => $structure['summary']['pending'],
            ],
        );

        // The class, the moment and the day — never a student's name.
        $name = str("grelha pauta {$class->label} {$structure['title']} ".($structure['meta']['reference_date'] ?? now()->toDateString()))
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
}
