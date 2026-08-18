<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reporting\BehaviourRating;
use App\Domain\Reporting\ComplementaryIndicator;
use App\Domain\Reporting\IndicatorStanding;
use App\Domain\Reporting\LearningAttitude;
use App\Domain\Reporting\PlanningCompliance;
use App\Http\Controllers\Controller;
use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\InterimAssessment;
use App\Models\Report;
use App\Models\ReportSection;
use App\Models\ReportTone;
use App\Models\ReportType;
use App\Models\SchoolClass;
use App\Models\User;
use App\Rules\BelongsToCurrentOrganization;
use App\Services\Audit\AuditLog;
use App\Services\Documents\DocumentIdentity;
use App\Services\Reporting\ComposeReport;
use App\Services\Reporting\CreateReport;
use App\Services\Reporting\ReportCapabilities;
use App\Services\Reporting\ReportLibraryProvider;
use App\Services\Reporting\ReportListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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
        ]);
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
            'class_id' => ['required', new BelongsToCurrentOrganization(SchoolClass::class)],
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
        $class = SchoolClass::findOrFail((int) $data['class_id']);

        Gate::authorize('view', $class);

        $period = ($data['academic_period_id'] ?? null) === null
            ? null
            : AcademicPeriod::findOrFail((int) $data['academic_period_id']);

        $interim = ($data['interim_assessment_id'] ?? null) === null
            ? null
            : InterimAssessment::findOrFail((int) $data['interim_assessment_id']);

        $this->guardBelongsToClass($class, $period, $interim);

        $tone = ReportTone::tryFrom((string) ($data['tone'] ?? '')) ?? ReportTone::Objective;
        $options = ['name_students' => (bool) ($data['name_students'] ?? false)];

        $report = match ($type) {
            ReportType::Student => $this->creator->forStudent(
                enrollment: $this->enrollmentIn($class, $data['enrollment_id'] ?? null),
                author: $this->user(),
                period: $period,
                sectionKeys: $data['sections'] ?? null,
                tone: $tone,
                options: $options,
                title: $data['title'] ?? null,
            ),
            default => $this->creator->forClass(
                class: $class,
                author: $this->user(),
                period: $period,
                interim: $interim,
                sectionKeys: $data['sections'] ?? null,
                tone: $tone,
                options: $options,
                title: $data['title'] ?? null,
            ),
        };

        return redirect()->route('reports.show', $report);
    }

    /**
     * The report itself: the editor for a draft, the frozen document for a
     * finalized one.
     */
    public function show(Report $report): Response
    {
        Gate::authorize('view', $report);

        $report->load(['sections', 'author', 'finalizer', 'basedOn', 'schoolClass.subject', 'enrollment.student.identity']);

        return Inertia::render('reports/Show', [
            'report' => $this->payload($report),
            'sections' => $report->sections->map(fn (ReportSection $section) => [
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
            ])->all(),
            'identity' => $this->identity->forCurrentOrganization(),
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
            ],
        ]);
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
        if (! $this->capabilities->allowsPedagogicalAnalysis()) {
            return [
                'available' => false,
                // Planning is Base: it is transcription, not analysis (§18).
                'planning' => PlanningCompliance::options(),
            ];
        }

        return [
            'available' => true,
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
