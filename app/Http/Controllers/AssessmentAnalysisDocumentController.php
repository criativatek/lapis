<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Services\Assessment\Advanced\AdvancedAnalyticsUnavailable;
use App\Services\Assessment\Advanced\BuildAssessmentTimeline;
use App\Services\Assessment\Advanced\Document\AnalysisDocumentOptions;
use App\Services\Assessment\Advanced\Document\BuildAnalysisDocument;
use App\Services\Audit\AuditLog;
use App\Services\Documents\DocumentIdentity;
use App\Services\Documents\Pdf\DompdfPrinter;
use App\Support\Entitlements\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The Pro analysis document — «Análise da Avaliação» on paper: the preview and the PDF.
 *
 * A DOCUMENT MADE WHEN IT IS ASKED FOR, NOT A REPORT. Nothing is stored and nothing is written to the
 * assessment. The page receives the structure that `BuildAnalysisDocument` composes, untouched, and the
 * PDF's Blade reads nothing else, so what is on the screen is what is on paper. The PDF is streamed
 * straight back and never kept: it is one person's copy of results of identifiable minors — and its
 * export, unlike the preview, leaves a trail of counts and options, never a name or a mark.
 *
 * THE SAME MOMENTS AS THE PAGE. The query (`de`, `para`, `momento`) is the analysis page's own and is
 * validated the same way, so the document is the analysis the teacher was looking at; `secoes` and
 * `alunos` are presentation, read against a whitelist (`AnalysisDocumentOptions`) — they choose what is
 * drawn and can never widen what the teacher may see.
 *
 * PRO, AT THE SAME THREE LEVELS AS THE PAGE. The routes carry `module:advanced_analytics`; this controller
 * asks the entitlement again (a suspended organization passes the route's gate on a GET and does not get
 * a new reading); and the service refuses in turn. A Base organization never reaches here.
 *
 * AUTHORISED LIKE THE CLASS ITSELF: whoever may `view` it (its teachers). A colleague who does not teach
 * it gets a 403; a class of another organization does not resolve at all, because the binding runs through
 * the tenant scope. GETs, so they also work while the account is read-only — except the new reading,
 * which a read-only module does not produce.
 */
class AssessmentAnalysisDocumentController extends Controller
{
    /** A point key as the timeline writes it: `period:<ulid>` or `moment:<ulid>`. */
    private const POINT_KEY = '/^(period|moment):[0-9a-z]{26}$/';

    public function __construct(
        protected BuildAssessmentTimeline $timeline,
        protected BuildAnalysisDocument $document,
        protected DocumentIdentity $identity,
        protected AuditLog $audit,
        protected Entitlements $entitlements,
    ) {}

    public function show(Request $request, SchoolClass $class): Response|SymfonyResponse
    {
        $moments = $this->validated($request, $class);

        if (! $this->entitlements->allows(BuildAssessmentTimeline::ANALYTICS_MODULE)) {
            return Inertia::render('ModuleUnavailable', [
                'canManagePlan' => $request->user()?->ownsCurrentOrganization() ?? false,
            ])->toResponse($request)->setStatusCode(HttpResponse::HTTP_FORBIDDEN);
        }

        $options = AnalysisDocumentOptions::fromQuery($request->query());
        $document = $this->compose($class, $moments, $options);

        $query = array_filter($moments, fn (?string $value): bool => $value !== null);

        return Inertia::render('assessment-analysis/Document', [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label],
            'document' => $document,
            'controls' => [
                'moments' => $query,
                'options' => $options->toQuery(),
                'sections' => [
                    ['key' => AnalysisDocumentOptions::COMPARISON, 'label' => __('Comparação entre momentos')],
                    ['key' => AnalysisDocumentOptions::STRENGTHS, 'label' => __('Pontos fortes e dificuldades')],
                    ['key' => AnalysisDocumentOptions::TRENDS, 'label' => __('Tendências e resultados persistentes')],
                ],
                'base_url' => "/classes/{$class->ulid}/analise-avaliacao/documento",
                'back' => ['href' => "/classes/{$class->ulid}/analise-avaliacao".($query === [] ? '' : '?'.http_build_query($query)), 'label' => __('Voltar à análise')],
            ],
        ]);
    }

    /**
     * The same document as a PDF, streamed straight back and never stored.
     */
    public function pdf(Request $request, SchoolClass $class, DompdfPrinter $printer): HttpResponse
    {
        $moments = $this->validated($request, $class);

        abort_unless($this->entitlements->allows(BuildAssessmentTimeline::ANALYTICS_MODULE), 403, __('O seu plano não inclui este módulo.'));

        $options = AnalysisDocumentOptions::fromQuery($request->query());
        $document = $this->compose($class, $moments, $options);

        $bytes = $printer->print(View::make('assessment-analysis.document', ['document' => $document])->render());

        // Counts, the options and what identifies the document — never a student's name or a mark.
        // Exporting is the moment results of identifiable minors leave the application, which is
        // what the trail is for.
        $this->audit->record(
            'assessment_analysis.document.exported',
            $class,
            summary: "Análise da Avaliação de {$class->label} exportada em PDF.",
            properties: [
                'format' => 'pdf',
                'sections' => $options->sections,
                'include_students' => $options->students,
                'comparison_from' => $moments['de'],
                'comparison_to' => $moments['para'],
                'moment' => $moments['momento'],
                ...$document['summary'],
            ],
        );

        // The class and the day — never a student's name.
        $name = str("analise-avaliacao {$class->label} ".now()->toDateString())->slug()->limit(80, '')->value();

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$name}.pdf\"",
            // Never cached by a proxy: this is one person's copy of one document about identifiable minors.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * The doors both actions go through, so the preview and the PDF cannot disagree about who may see what.
     *
     * @return array{de: string|null, para: string|null, momento: string|null}
     */
    protected function validated(Request $request, SchoolClass $class): array
    {
        Gate::authorize('view', $class);

        $validated = $request->validate([
            'de' => ['nullable', 'string', 'regex:'.self::POINT_KEY],
            'para' => ['nullable', 'string', 'regex:'.self::POINT_KEY],
            'momento' => ['nullable', 'string', 'regex:'.self::POINT_KEY],
            // Presentation: the sections drawn and whether the document names students.
            'secoes' => ['nullable', 'string', 'regex:/^[a-z,\-]*$/', 'max:80'],
            'alunos' => ['nullable', Rule::in(['0', '1', 'true', 'false'])],
        ]);

        return [
            'de' => $validated['de'] ?? null,
            'para' => $validated['para'] ?? null,
            'momento' => $validated['momento'] ?? null,
        ];
    }

    /**
     * The structure, and what belongs to the act of printing and not to the data: the school's
     * letterhead and the moment of issue.
     *
     * @param  array{de: string|null, para: string|null, momento: string|null}  $moments
     * @return array<string, mixed>
     *
     * @throws AdvancedAnalyticsUnavailable
     */
    protected function compose(SchoolClass $class, array $moments, AnalysisDocumentOptions $options): array
    {
        $class->loadMissing(['organization.identity']);

        $structure = $this->document->build($class, $this->timeline->for($class), $moments['de'], $moments['para'], $moments['momento'], $options);

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
}
