<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reporting\BehaviourRating;
use App\Domain\Reporting\ComplementaryIndicator;
use App\Domain\Reporting\IndicatorStanding;
use App\Domain\Reporting\LearningAttitude;
use App\Domain\Reporting\PlanningCompliance;
use App\Domain\Reporting\SectionCatalogue;
use App\Domain\Reporting\SectionKey;
use App\Http\Controllers\Controller;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\InterimAssessment;
use App\Models\Report;
use App\Models\ReportSection;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateKind;
use App\Models\ReportTone;
use App\Models\ReportType;
use App\Models\SchoolClass;
use App\Models\User;
use App\Rules\BelongsToCurrentOrganization;
use App\Services\Audit\AuditLog;
use App\Services\Documents\DocumentIdentity;
use App\Services\Documents\SchoolLogoService;
use App\Services\Reporting\ComposeReport;
use App\Services\Reporting\CreateReport;
use App\Services\Reporting\DeriveReport;
use App\Services\Reporting\FinalizeReport;
use App\Services\Reporting\ReportCapabilities;
use App\Services\Reporting\ReportComparison;
use App\Services\Reporting\ReportLibraryProvider;
use App\Services\Reporting\ReportListing;
use App\Services\Reporting\Templates\TemplateResolver;
use App\Services\Reporting\Writing\ReportWritingAssistant;
use App\Services\Reporting\Writing\WritingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relatórios — the module's own home (§53, §54, §60).
 *
 * A report here is an object with a life: created, edited, finalized, exported,
 * reused. The pauta — the sheet of decided grades — lives beside it under
 * `pautas.*` and is a different artifact: a table of classifications, not a
 * document with sections and an author.
 *
 * THE FLOW IS ONE PAGE WITH STEPS, NOT A WIZARD (§60). Choosing a type, a
 * context and the sections happens on a single screen; everything after that is
 * editing what came out. A teacher writing their fourth report of the term
 * should not be walked through eight screens to say «7.º A, 2.º Semestre».
 */
class ReportController extends Controller
{
    public function __construct(
        protected ReportListing $listing,
        protected ReportCapabilities $capabilities,
        protected CreateReport $creator,
        protected ComposeReport $composer,
        protected DocumentIdentity $identity,
        protected ReportLibraryProvider $library,
        protected TemplateResolver $templates,
        protected ReportWritingAssistant $assistant,
        protected AuditLog $audit,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Report::class);

        $filters = [
            'type' => $request->query('type'),
            'status' => $request->query('status'),
            'class_id' => $request->query('class_id') !== null ? (int) $request->query('class_id') : null,
        ];

        $listing = $this->listing->for($this->user(), $filters);

        return Inertia::render('reports/Index', [
            'reports' => $listing['rows'],
            'total' => $listing['total'],
            'filters' => $filters,
            // Which kinds this school's plan allows, so the "Novo relatório"
            // menu offers exactly what will actually be accepted (§4).
            'availableTypes' => array_map(
                fn (ReportType $type) => ['value' => $type->value, 'label' => $type->label()],
                $this->capabilities->availableTypes(),
            ),
            'statuses' => ReportListing::statusOptions(),
            'classes' => $this->teachingClasses(),
        ]);
    }

    /**
     * The creation screen: type, context, sections — in that order, on one page.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', Report::class);

        $types = $this->capabilities->availableTypes();
        $requested = ReportType::tryFrom((string) $request->query('type'));
        $type = $requested !== null && in_array($requested, $types, strict: true)
            ? $requested
            : ($types[0] ?? ReportType::SchoolClass);

        return Inertia::render('reports/Create', [
            'type' => $type->value,
            'availableTypes' => array_map(
                fn (ReportType $available) => ['value' => $available->value, 'label' => $available->label()],
                $types,
            ),
            'classes' => $this->teachingClasses(),
            // The whole catalogue, including what this plan cannot produce, so
            // the checklist explains the boundary instead of hiding it (§4).
            'catalogue' => $this->capabilities->catalogueFor($type),
            'tones' => array_map(
                fn (ReportTone $tone) => [
                    'value' => $tone->value,
                    'label' => $tone->label(),
                    'description' => $tone->description(),
                ],
                $this->capabilities->availableTones(),
            ),
            // A school-wide report has no class to take its year from, so the
            // year is chosen directly (§24).
            'academicYears' => array_values(AcademicYear::query()
                ->orderByDesc('starts_on')
                ->get()
                ->map(fn (AcademicYear $year) => ['id' => $year->id, 'label' => $year->label])
                ->all()),
            // §21: the filters a Registos report is built with. Sent for every
            // type — the form only shows them for the one that uses them.
            'recordKinds' => array_map(
                fn (EvidenceKind $kind) => [
                    'value' => $kind->value,
                    'label' => $kind->label(),
                    'group' => $kind->group()->label(),
                ],
                EvidenceKind::cases(),
            ),
            // §16, §17: which arrangements this teacher may start from, and
            // which one is pre-selected so a common case needs no choice.
            'templates' => $this->templates->optionsFor($this->user(), $type),
            'preferredTemplate' => $this->templates->preferredFor($this->user(), $type)?->ulid,
            // Richer prefill hints — the same mechanism `type` already is,
            // taught to carry more. Arriving from Acompanhamento do Aluno's
            // «Gerar relatório», this is how the teacher's turma/aluno/período
            // reach the form without being re-picked by hand.
            'preselected' => $this->preselectedFrom($request),
        ]);
    }

    /**
     * Turma, aluno and período from a query string, resolved and checked
     * against each other — never trusted as-is.
     *
     * A QUERY PARAMETER IS A SUGGESTION FROM A LINK, NOT AN INSTRUCTION. A
     * ulid or id that does not resolve, that does not belong to this
     * teacher, or that does not belong to the class it arrived with, is
     * silently dropped — the screen still opens, with less prefilled, rather
     * than refusing to load or trusting an id the teacher does not actually
     * have (the same principle InterventionController::prefillFrom already
     * applies to `aluno`).
     *
     * @return array<string, mixed>|null
     */
    protected function preselectedFrom(Request $request): ?array
    {
        $classUlid = $request->query('class');
        $class = is_string($classUlid) && $classUlid !== ''
            ? SchoolClass::where('ulid', $classUlid)->first()
            : null;

        if ($class === null || ! Gate::allows('view', $class)) {
            return null;
        }

        $enrollmentUlid = $request->query('enrollment');
        $enrollment = is_string($enrollmentUlid) && $enrollmentUlid !== ''
            ? Enrollment::where('ulid', $enrollmentUlid)->where('class_id', $class->id)->first()
            : null;

        $periodId = $request->query('period');
        $period = is_numeric($periodId)
            ? AcademicPeriod::where('academic_year_id', $class->academic_year_id)->find((int) $periodId)
            : null;

        return [
            'class_id' => $class->id,
            'enrollment_id' => $enrollment?->id,
            'academic_period_id' => $period?->id,
        ];
    }

    /**
     * Periods, enrolments and kept photographs for one class — fetched when the
     * teacher picks a class, so the creation screen does not ship every class's
     * context up front.
     */
    public function context(SchoolClass $class): JsonResponse
    {
        Gate::authorize('view', $class);

        return response()->json([
            'periods' => AcademicPeriod::query()
                ->where('academic_year_id', $class->academic_year_id)
                ->orderBy('sequence')
                ->get()
                ->map(fn (AcademicPeriod $period) => ['id' => $period->id, 'label' => $period->label])
                ->all(),
            'enrollments' => $class->activeEnrollments()
                ->with('student.identity')
                ->orderBy('class_number')
                ->get()
                ->map(fn ($enrollment) => [
                    'id' => $enrollment->id,
                    'class_number' => $enrollment->class_number,
                    'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                ])
                ->all(),
            // §30: a report may be built ON a photograph rather than on live
            // data, and then it never reconstructs it.
            'interimAssessments' => InterimAssessment::query()
                ->where('class_id', $class->id)
                ->orderByDesc('reference_date')
                ->get()
                ->map(fn (InterimAssessment $interim) => [
                    'id' => $interim->id,
                    'name' => $interim->name,
                    'reference_date' => $interim->reference_date->toDateString(),
                ])
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Report::class);

        $data = $request->validate([
            'type' => ['required', Rule::enum(ReportType::class)],
            // BelongsToCurrentOrganization, never a bare `exists:` — that rule
            // runs on the query builder and never sees the tenant scope.
            // A Registos report may span every class this teacher has, and a
            // school-wide one has no class at all. Every other type needs one.
            'class_id' => [
                Rule::requiredIf(fn () => ! in_array(
                    $request->input('type'),
                    [ReportType::Records->value, ReportType::School->value],
                    strict: true,
                )),
                'nullable',
                new BelongsToCurrentOrganization(SchoolClass::class),
            ],
            'academic_year_id' => ['nullable', new BelongsToCurrentOrganization(AcademicYear::class)],
            // §21: the filters a Registos report is built with.
            'kinds' => ['nullable', 'array'],
            'kinds.*' => [Rule::enum(EvidenceKind::class)],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'detailed' => ['nullable', 'boolean'],
            // §16: a template is a starting point. Resolved against what this
            // teacher may actually use, so a ulid from elsewhere is ignored
            // rather than trusted.
            'template' => ['nullable', 'string', 'max:40'],
            'academic_period_id' => ['nullable', new BelongsToCurrentOrganization(AcademicPeriod::class)],
            'enrollment_id' => ['nullable', new BelongsToCurrentOrganization(Enrollment::class)],
            'interim_assessment_id' => ['nullable', new BelongsToCurrentOrganization(InterimAssessment::class)],
            'tone' => ['nullable', Rule::enum(ReportTone::class)],
            'title' => ['nullable', 'string', 'max:200'],
            'sections' => ['nullable', 'array'],
            'sections.*' => ['string', 'max:64'],
            // §28, §57: naming students on a class-wide document is an explicit
            // decision, never a default.
            'name_students' => ['nullable', 'boolean'],
        ]);

        $type = ReportType::from($data['type']);

        // Cast to int before finding: an array id would resolve a collection,
        // which is a different (and unauthorised) thing to hand a policy.
        $class = ($data['class_id'] ?? null) === null
            ? null
            : SchoolClass::findOrFail((int) $data['class_id']);

        if ($class !== null) {
            Gate::authorize('view', $class);
        }

        $period = ($data['academic_period_id'] ?? null) === null
            ? null
            : AcademicPeriod::findOrFail((int) $data['academic_period_id']);

        $interim = ($data['interim_assessment_id'] ?? null) === null
            ? null
            : InterimAssessment::findOrFail((int) $data['interim_assessment_id']);

        if ($class !== null) {
            $this->guardBelongsToClass($class, $period, $interim);
        }

        $tone = ReportTone::tryFrom((string) ($data['tone'] ?? '')) ?? ReportTone::Objective;
        $options = ['name_students' => (bool) ($data['name_students'] ?? false)];
        $template = $this->templates->resolve($this->user(), $type, $data['template'] ?? null);

        $report = match ($type) {
            ReportType::Student => $this->creator->forStudent(
                enrollment: $this->enrollmentIn($this->requireClass($class), $data['enrollment_id'] ?? null),
                author: $this->user(),
                period: $period,
                sectionKeys: $data['sections'] ?? null,
                tone: $tone,
                options: $options,
                title: $data['title'] ?? null,
                template: $template,
            ),
            ReportType::School => $this->creator->forSchool(
                year: $this->yearFor($class, $data['academic_year_id'] ?? null),
                author: $this->user(),
                period: $period,
                sectionKeys: $data['sections'] ?? null,
                tone: $tone,
                title: $data['title'] ?? null,
                template: $template,
            ),
            ReportType::Records => $this->creator->forRecords(
                year: $this->yearFor($class, $data['academic_year_id'] ?? null),
                author: $this->user(),
                class: $class,
                enrollment: ($data['enrollment_id'] ?? null) === null || $class === null
                    ? null
                    : $this->enrollmentIn($class, $data['enrollment_id']),
                period: $period,
                startsOn: ($data['starts_on'] ?? null) === null ? null : Carbon::parse($data['starts_on']),
                endsOn: ($data['ends_on'] ?? null) === null ? null : Carbon::parse($data['ends_on']),
                sectionKeys: $data['sections'] ?? null,
                tone: $tone,
                options: [
                    ...$options,
                    'kinds' => array_values($data['kinds'] ?? []),
                    'detailed' => (bool) ($data['detailed'] ?? false),
                ],
                title: $data['title'] ?? null,
                template: $template,
            ),
            default => $this->creator->forClass(
                class: $this->requireClass($class),
                author: $this->user(),
                period: $period,
                interim: $interim,
                sectionKeys: $data['sections'] ?? null,
                tone: $tone,
                options: $options,
                title: $data['title'] ?? null,
                template: $template,
            ),
        };

        return redirect()->route('reports.show', $report);
    }

    /**
     * The report itself: the editor for a draft, the frozen document for a
     * finalized one.
     */
    public function show(Request $request, Report $report, ReportComparison $comparison): Response
    {
        Gate::authorize('view', $report);

        $report->load(['sections', 'author', 'finalizer', 'basedOn', 'schoolClass.subject', 'enrollment.student.identity']);

        return Inertia::render('reports/Show', [
            'report' => $this->payload($report),
            'sections' => $this->sectionsPayload($report),
            // A FINALIZED REPORT SHOWS ITS OWN LETTERHEAD, not the school's
            // current one. That is the whole point of freezing it (§39).
            'identity' => $report->isFinalized()
                ? (array) data_get($report->document, 'identity', [])
                : $this->identity->forCurrentOrganization(),
            // §35: what moved since the report this one started from. Facts, and
            // no causation.
            //
            // PRO AND INSTITUCIONAL. «Comparação entre períodos» is one of the
            // rows Matriz Mestre §6 reserves for them, and «Desde o relatório
            // anterior» is exactly that reading: two documents, two moments,
            // and the movement between them. Computed only when the plan
            // includes it, so a Base report's props carry no comparison at all
            // rather than one the page would have to hide.
            'comparison' => $this->capabilities->allowsAnalytics()
                ? $comparison->for($report)
                : null,
            'characterisation' => $this->characterisationOptions($report),
            // §14: what a difficulty can be, and which strategies answer each
            // one. Only sent when the plan includes the sections that use it.
            'library' => $this->capabilities->allowsPedagogicalAnalysis() ? [
                'difficulties' => $this->library->difficulties($report->schoolClass?->subject_id),
                'strategies' => $this->library->strategiesByDifficulty(),
                // The domains a difficulty may be associated with are this
                // subject's own, not a free-text field: the association is what
                // lets the report quote a figure beside it.
                'domains' => $this->domainsOf($report),
            ] : null,
            // The roster, for the section that may name students — and only for
            // that. Nothing else on this page needs it.
            'enrollments' => $this->capabilities->allowsPedagogicalAnalysis()
                ? $this->rosterOf($report)
                : [],
            'can' => [
                'update' => Gate::allows('update', $report),
                'finalize' => Gate::allows('finalize', $report),
                'delete' => Gate::allows('delete', $report),
                'export' => Gate::allows('export', $report),
                'derive' => Gate::allows('derive', $report),
            ],
            // §18: which kinds of template this teacher may save the draft's
            // arrangement into. Both false on Base, where the panel is hidden.
            'canSaveTemplate' => [
                'personal' => Gate::allows('createKind', [ReportTemplate::class, ReportTemplateKind::Personal]),
                'institutional' => Gate::allows('createKind', [ReportTemplate::class, ReportTemplateKind::Institutional]),
            ],
            // The writing assistant, and why it is off when it is off. A button
            // that can only fail is never shown (§41 of the IA brief).
            'ai' => [
                'available' => $this->assistant->isAvailable(),
                'reason' => $this->assistant->unavailableReason(),
                'modes' => WritingMode::options(),
            ],
            // A suggestion is a transient answer to one click, so it travels in
            // the session and is gone on the next visit. It is never stored on
            // the report: nothing is written until the teacher accepts (§19).
            'rewrite' => $request->session()->get('rewrite'),
            'rewriteError' => $request->session()->get('rewriteError'),
        ]);
    }

    /**
     * The sections a screen should show.
     *
     * A DRAFT SHOWS ITS ROWS; A FINALIZED REPORT SHOWS ITS DOCUMENT. Reading
     * the rows of a finished report would put today's regenerated text on a
     * page that was signed months ago — the rows are how it was assembled, the
     * document is what it says (§37).
     *
     * @return list<array<string, mixed>>
     */
    protected function sectionsPayload(Report $report): array
    {
        if ($report->isFinalized()) {
            return array_values(array_map(fn ($section): array => [
                'ulid' => null,
                'key' => (string) ($section['key'] ?? ''),
                'heading' => (string) ($section['heading'] ?? ''),
                'position' => (int) ($section['position'] ?? 0),
                'included' => true,
                'body' => $section['body'] ?? null,
                'edited' => (bool) ($section['edited'] ?? false),
                'can_restore' => false,
                'has_content' => true,
                'sources' => $section['sources'] ?? [],
                'data' => $section['data'] ?? null,
            ], (array) data_get($report->document, 'sections', [])));
        }

        return array_values($report->sections->map(fn (ReportSection $section) => [
            'ulid' => $section->ulid,
            'key' => $section->key,
            'heading' => $section->heading,
            'position' => $section->position,
            'included' => $section->included,
            'body' => $section->body,
            'edited' => $section->edited,
            'can_restore' => $section->canRestore(),
            'has_content' => $section->hasContent(),
            'sources' => $section->sources ?? [],
            'data' => $section->data,
            // Per section, because the answer differs per section: an
            // identification block, a scope statement and a chronological
            // listing are not prose to be reworded, and one with no text yet has
            // nothing to reword (§34 of the IA brief).
            'can_rewrite' => $this->assistant->mayRewrite($report, $section),
            // Names in this section leave as «Aluno A» — said out loud on the
            // screen, because sending them has to be a decision somebody made
            // (§10).
            'may_name_students' => $this->mayNameStudents($report, $section),
        ])->all());
    }

    /**
     * Whether this section is one of the two that can put a name on a document.
     *
     * Read from the catalogue rather than by looking for names in the text: the
     * question is what the section IS ALLOWED to contain, not what it happens to
     * contain today.
     */
    protected function mayNameStudents(Report $report, ReportSection $section): bool
    {
        $key = SectionKey::tryFrom($section->key);

        if ($key === null) {
            return false;
        }

        return SectionCatalogue::find($report->type, $key)->mayNameStudents ?? false;
    }

    /**
     * Title, tone, the teacher's characterisation, and which sections print.
     *
     * The characterisation is stored whole rather than field by field: it is one
     * document, it is read whole, and its shape is versioned (§8).
     */
    public function update(Request $request, Report $report): RedirectResponse
    {
        Gate::authorize('update', $report);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'tone' => ['sometimes', Rule::enum(ReportTone::class)],
            'teacher_input' => ['sometimes', 'array'],
            'teacher_input.behaviour' => ['nullable', Rule::enum(BehaviourRating::class)],
            'teacher_input.attitude' => ['nullable', Rule::enum(LearningAttitude::class)],
            'teacher_input.indicators' => ['nullable', 'array'],
            'teacher_input.indicators.*.indicator' => ['required', Rule::enum(ComplementaryIndicator::class)],
            'teacher_input.indicators.*.standing' => ['required', Rule::enum(IndicatorStanding::class)],
            'teacher_input.observation' => ['nullable', 'string', 'max:2000'],
            'teacher_input.planning' => ['nullable', 'array'],
            'teacher_input.planning.compliance' => ['nullable', Rule::enum(PlanningCompliance::class)],
            'teacher_input.planning.pending_content' => ['nullable', 'array'],
            'teacher_input.planning.pending_content.*' => ['string', 'max:200'],
            'teacher_input.planning.postponed_content' => ['nullable', 'array'],
            'teacher_input.planning.postponed_content.*' => ['string', 'max:200'],
            'teacher_input.planning.reason' => ['nullable', 'string', 'max:500'],
            'teacher_input.planning.recovery_plan' => ['nullable', 'string', 'max:500'],
            'teacher_input.planning.note' => ['nullable', 'string', 'max:1000'],
            'teacher_input.final_note' => ['nullable', 'string', 'max:2000'],
            // §14: difficulties, and the strategies chosen against each one.
            'teacher_input.difficulties' => ['nullable', 'array'],
            'teacher_input.difficulties.*.code' => ['nullable', 'string', 'max:64'],
            'teacher_input.difficulties.*.label' => ['nullable', 'string', 'max:200'],
            'teacher_input.difficulties.*.domain' => ['nullable', 'string', 'max:120'],
            'teacher_input.difficulties.*.note' => ['nullable', 'string', 'max:500'],
            'teacher_input.difficulties.*.strategies' => ['nullable', 'array'],
            'teacher_input.difficulties.*.strategies.*.code' => ['nullable', 'string', 'max:64'],
            'teacher_input.difficulties.*.strategies.*.label' => ['nullable', 'string', 'max:200'],
            'teacher_input.difficulties.*.strategies.*.objective' => ['nullable', 'string', 'max:300'],
            // §57: the students the teacher flagged. Listing them is one
            // decision; letting the document name them is a separate one.
            'teacher_input.students_requiring_attention' => ['nullable', 'array'],
            'teacher_input.students_requiring_attention.*.enrollment_id' => ['required', new BelongsToCurrentOrganization(Enrollment::class)],
            'teacher_input.students_requiring_attention.*.note' => ['nullable', 'string', 'max:500'],
            'name_students' => ['sometimes', 'boolean'],
        ]);

        // A chosen library entry becomes a COPY of its words, resolved here and
        // never looked up again: rewording the library next year must not
        // rewrite a report written this year (§33, §38).
        if (isset($data['teacher_input']['difficulties']) && is_array($data['teacher_input']['difficulties'])) {
            $data['teacher_input']['difficulties'] = $this->library
                ->resolveDifficulties($data['teacher_input']['difficulties']);
        }

        $attributes = [];

        if (array_key_exists('title', $data)) {
            $attributes['title'] = $data['title'];
        }

        if (array_key_exists('tone', $data)) {
            $tone = ReportTone::from($data['tone']);

            // A tone the plan does not include is refused rather than applied
            // quietly — the server decides, not the form (§4).
            if ($this->capabilities->allowsTone($tone)) {
                $attributes['tone'] = $tone;
            }
        }

        if (array_key_exists('teacher_input', $data)) {
            $attributes['teacher_input'] = array_replace(
                $report->teacher_input ?? [],
                $data['teacher_input'],
            );
            $attributes['teacher_input_version'] = Report::CURRENT_TEACHER_INPUT_VERSION;
        }

        if (array_key_exists('name_students', $data)) {
            $attributes['options'] = array_replace(
                $report->options ?? [],
                ['name_students' => (bool) $data['name_students']],
            );
        }

        if ($attributes !== []) {
            $report->update($attributes);
        }

        // The teacher's answers are half the content of a pedagogical report,
        // so saving them regenerates — without touching any section they have
        // typed over themselves.
        if (array_key_exists('teacher_input', $data) || array_key_exists('name_students', $data)) {
            $this->composer->generate($report->fresh() ?? $report);
        }

        return back();
    }

    /** Regenerate every section that the teacher has not rewritten. */
    public function regenerate(Report $report): RedirectResponse
    {
        Gate::authorize('update', $report);

        $this->composer->generate($report);

        return back();
    }

    /**
     * Finalize (§37). From here the report holds its own copy of everything it
     * said, and no later change to a grade or a logo rewrites it.
     */
    public function finalize(Report $report, FinalizeReport $finalizer): RedirectResponse
    {
        Gate::authorize('finalize', $report);

        $finalizer->finalize($report, $this->user());

        return back();
    }

    /**
     * Derive a new draft from this report (§31, §32). The original is never
     * touched — what comes out is the reader's own document.
     */
    public function derive(Request $request, Report $report, DeriveReport $deriver): RedirectResponse
    {
        Gate::authorize('derive', $report);

        $data = $request->validate([
            'academic_period_id' => ['nullable', new BelongsToCurrentOrganization(AcademicPeriod::class)],
            'interim_assessment_id' => ['nullable', new BelongsToCurrentOrganization(InterimAssessment::class)],
        ]);

        $period = ($data['academic_period_id'] ?? null) === null
            ? null
            : AcademicPeriod::findOrFail((int) $data['academic_period_id']);

        $interim = ($data['interim_assessment_id'] ?? null) === null
            ? null
            : InterimAssessment::findOrFail((int) $data['interim_assessment_id']);

        if ($report->schoolClass !== null) {
            $this->guardBelongsToClass($report->schoolClass, $period, $interim);
        }

        $draft = $deriver->derive($report, $this->user(), $period, $interim);

        return redirect()->route('reports.show', $draft);
    }

    /**
     * The logo frozen into a finalized report (§39).
     *
     * Served by an authorizing controller from the private disk, exactly as the
     * live one is: a file whose URL is its filename is a file anybody can
     * enumerate.
     */
    public function logo(Report $report): StreamedResponse
    {
        Gate::authorize('view', $report);

        $path = data_get($report->document, 'identity.logo_path');

        abort_if(! is_string($path), 404);

        $disk = Storage::disk(SchoolLogoService::DISK);

        abort_unless($disk->exists($path), 404);

        return $disk->response($path);
    }

    public function destroy(Report $report): RedirectResponse
    {
        Gate::authorize('delete', $report);

        $title = $report->title;
        $report->delete();

        $this->audit->record('report.deleted', null, $this->user(), summary: "Rascunho «{$title}» eliminado.");

        return redirect()->route('reports.index');
    }

    // ------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    protected function payload(Report $report): array
    {
        return [
            'ulid' => $report->ulid,
            'title' => $report->title,
            'type' => $report->type->value,
            'type_label' => $report->type->label(),
            'status' => $report->status->value,
            'status_label' => $report->status->label(),
            'tone' => $report->tone->value,
            'scope_label' => $report->scope_label,
            'scope_kind' => $report->scope_kind->value,
            'subject_label' => $this->listing->row($report)['subject_label'],
            'teacher_input' => $report->teacher_input ?? [],
            'name_students' => $report->namesStudents(),
            'author' => $report->author?->name,
            'created_at' => $report->created_at->toIso8601String(),
            'updated_at' => $report->updated_at->toIso8601String(),
            'finalized_at' => $report->finalized_at?->toIso8601String(),
            'finalized_by' => $report->finalizer?->name,
            'based_on' => $report->basedOn === null ? null : [
                'ulid' => $report->basedOn->ulid,
                'title' => $report->basedOn->title,
            ],
        ];
    }

    /**
     * The vocabulary the characterisation step offers (§9, §10, §11, §18).
     *
     * Sent only when the plan includes the sections that use it — a Base
     * teacher is never shown a form whose answers nothing would print.
     *
     * @return array<string, mixed>|null
     */
    protected function characterisationOptions(Report $report): ?array
    {
        // WHICH QUESTIONS THIS REPORT WOULD ACTUALLY PRINT. A school-wide
        // report has no «comportamento da turma» section, so asking its author
        // to characterise one would be collecting an answer nothing uses — and
        // they would reasonably expect to see it in the document.
        $keys = array_map(
            fn ($definition) => $definition->key,
            $this->capabilities->sectionsFor($report->type),
        );

        $asks = fn (SectionKey $key): bool => in_array($key, $keys, strict: true);

        if (! $this->capabilities->allowsPedagogicalAnalysis()) {
            return [
                'available' => false,
                // Planning is Base: it is transcription, not analysis (§18).
                'asks_planning' => $asks(SectionKey::PlanningCompliance),
                'planning' => PlanningCompliance::options(),
            ];
        }

        return [
            'available' => true,
            'asks_behaviour' => $asks(SectionKey::BehaviourAttitude),
            'asks_difficulties' => $asks(SectionKey::Difficulties),
            'asks_attention' => $asks(SectionKey::StudentsRequiringAttention),
            'asks_planning' => $asks(SectionKey::PlanningCompliance),
            'behaviour' => BehaviourRating::options(),
            'attitude' => LearningAttitude::options(),
            'indicators' => ComplementaryIndicator::options(),
            'standings' => IndicatorStanding::options(),
            'planning' => PlanningCompliance::options(),
        ];
    }

    /**
     * The subject's domains, by name.
     *
     * @return list<string>
     */
    protected function domainsOf(Report $report): array
    {
        $subjectId = $report->schoolClass?->subject_id;

        if ($subjectId === null) {
            return [];
        }

        return array_values(Domain::query()
            ->where('subject_id', $subjectId)
            ->orderBy('name')
            ->pluck('name')
            ->all());
    }

    /**
     * The class roster, for the one section that may name a student.
     *
     * @return list<array<string, mixed>>
     */
    protected function rosterOf(Report $report): array
    {
        $class = $report->schoolClass;

        if ($class === null) {
            return [];
        }

        return array_values($class->activeEnrollments()
            ->with('student.identity')
            ->orderBy('class_number')
            ->get()
            ->map(fn (Enrollment $enrollment) => [
                'id' => $enrollment->id,
                'class_number' => $enrollment->class_number,
                'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
            ])
            ->all());
    }

    /**
     * The year a Registos report belongs to.
     *
     * Taken from the chosen class when there is one, so the two can never
     * disagree; asked for explicitly only when the report spans every class.
     */
    protected function yearFor(?SchoolClass $class, mixed $yearId): AcademicYear
    {
        if ($class !== null) {
            return AcademicYear::findOrFail((int) $class->academic_year_id);
        }

        abort_if($yearId === null, 422, 'Um relatório por registos precisa de um ano letivo.');

        return AcademicYear::findOrFail((int) $yearId);
    }

    protected function requireClass(?SchoolClass $class): SchoolClass
    {
        abort_if($class === null, 422, 'Este tipo de relatório precisa de uma turma.');

        return $class;
    }

    protected function guardBelongsToClass(SchoolClass $class, ?AcademicPeriod $period, ?InterimAssessment $interim): void
    {
        if ($period !== null) {
            abort_if((int) $period->academic_year_id !== (int) $class->academic_year_id, 404);
        }

        if ($interim !== null) {
            abort_if((int) $interim->class_id !== (int) $class->id, 404);
        }
    }

    protected function enrollmentIn(SchoolClass $class, mixed $enrollmentId): Enrollment
    {
        abort_if($enrollmentId === null, 422, 'Um relatório individual precisa de um aluno.');

        $enrollment = Enrollment::findOrFail((int) $enrollmentId);

        abort_if((int) $enrollment->class_id !== (int) $class->id, 404);

        return $enrollment;
    }

    /**
     * The classes this teacher can build a report about — also the filter's
     * options, so the two can never offer different sets.
     *
     * @return list<array<string, mixed>>
     */
    protected function teachingClasses(): array
    {
        return array_values(SchoolClass::query()
            ->whereHas('teachers', fn ($query) => $query->whereKey($this->user()->getKey()))
            ->with(['subject', 'academicYear'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SchoolClass $class) => [
                'id' => $class->id,
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
            ])
            ->all());
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
