<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\EvaluationSheetExport;
use App\Models\SchoolClass;
use App\Models\SheetMomentKind;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\Export\EvaluationSheetDocument;
use App\Services\Documents\DocumentIdentity;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGrid;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGridOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The printable grid of a class — the preview of the Pauta de Avaliação, live
 * and as kept.
 *
 * A DOCUMENT MADE WHEN IT IS ASKED FOR, NOT A REPORT. Nothing is stored and
 * nothing is written to the assessment. The page receives the structure that
 * `EvaluationSheetGrid` composes, untouched, so what is on the screen is what the
 * next steps will put on paper.
 *
 * TWO SOURCES, ONE PAGE, AND THEY NEVER CROSS.
 *
 *  - `live` reads the sheet as it stands today, the way the screen does but
 *    WITHOUT the proposal basis (`withProposalBasis: false`): the grid does not
 *    read «a proposta de hoje» or «desatualizada», and in the last unit of the
 *    year those cost the results of every other unit. The moment (`?momento=`)
 *    only titles the document; both moments read the same sheet.
 *  - `snapshot` reads a kept moment from its frozen payload and from nothing
 *    else. The sheet builder is a parameter of `live` ALONE — this action is
 *    given no builder and no calculator, so it has no way to reach the present: a kept moment that picked up a later change would be a
 *    false statement about the past (§16). Same four doors as the CSV and the
 *    Excel of a kept moment — a class this teacher may see, a record OF THAT
 *    class (a ulid is not a key to a colleague's class), an intact seal.
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
    ) {}

    /**
     * The sheet as it stands today, for one period and one of its two moments.
     */
    public function live(Request $request, SchoolClass $class, string $period, BuildEvaluationSheet $builder): Response
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
        $document = EvaluationSheetDocument::fromLiveSheet($class, $selected, $sheet, ClassificationScope::Period, $moment);

        return $this->render($request, $class, $document, [
            'kind' => 'live',
            'moment' => $moment->value,
            'back' => [
                'href' => "/classes/{$class->ulid}/pauta-avaliacao/{$selected->ulid}".($moment === SheetMomentKind::Final ? '' : "?momento={$moment->value}"),
                'label' => 'Voltar à pauta',
            ],
            'base_url' => "/classes/{$class->ulid}/pauta-avaliacao/impressao/{$selected->ulid}",
            // Carried by every change of option, so choosing one never changes the moment.
            'fixed_query' => $moment === SheetMomentKind::Final ? [] : ['momento' => $moment->value],
        ]);
    }

    /**
     * A kept moment, exactly as it was kept.
     */
    public function snapshot(Request $request, SchoolClass $class, EvaluationSheetExport $export): Response
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

        return $this->render($request, $class, EvaluationSheetDocument::fromSnapshot($export), [
            'kind' => 'snapshot',
            'moment' => null,
            'back' => [
                'href' => "/classes/{$class->ulid}/pauta-avaliacao/historico/{$export->ulid}",
                'label' => 'Voltar à pauta guardada',
            ],
            'base_url' => "/classes/{$class->ulid}/pauta-avaliacao/historico/{$export->ulid}/impressao",
            'fixed_query' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    protected function render(Request $request, SchoolClass $class, EvaluationSheetDocument $document, array $source): Response
    {
        $structure = $this->grid->build($document, EvaluationSheetGridOptions::fromQuery($request->query()));

        $issuedAt = now();
        $identity = $this->identity->for($class->organization);

        return Inertia::render('evaluation-sheets/GridDocument', [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label],
            'source' => $source,
            'document' => [
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
            ],
        ]);
    }
}
