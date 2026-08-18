<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\EvaluationAdaptationCode;
use App\Models\Intervention;
use App\Models\InterventionContext;
use App\Models\InterventionDescriptionSource;
use App\Models\InterventionDomainRelation;
use App\Models\InterventionEffectiveness;
use App\Models\InterventionReview;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\InterventionType;
use App\Models\LegalMappingMode;
use App\Models\LegalMappingSource;
use App\Models\SchoolClass;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Models\User;
use App\Support\Interventions\InterventionLegalFramework;
use App\Support\Interventions\LegalFrameworkResolver;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Interventions (§14): what the teacher DID, as opposed to the Registos module's
 * record of what the teacher OBSERVED. Never part of the calculation (§14.3).
 *
 * The whole point of the module is that registering one is fast: pick who, pick
 * the type, done. Everything else — duration, status, legal framing,
 * effectiveness — is optional and stays out of the way until asked for.
 */
class InterventionController extends Controller
{
    public function __construct(protected LegalFrameworkResolver $frameworks) {}

    /**
     * The framework that applies to an intervention: the current
     * organization's jurisdiction, read at the intervention's own date.
     *
     * The date is always `started_on` and never today, so editing a 2026
     * intervention in 2027 keeps reading it under the regime in force when it
     * happened.
     */
    protected function frameworkFor(CarbonInterface $date): InterventionLegalFramework
    {
        return $this->frameworks->for(app(CurrentOrganization::class)->get(), $date);
    }

    public function index(): Response
    {
        $classes = SchoolClass::query()
            ->whereHas('teachers', fn ($query) => $query->whereKey($this->user()->getKey()))
            ->with(['subject', 'academicYear'])
            ->withCount('interventions')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SchoolClass $class) => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'interventions_count' => $class->interventions_count,
            ]);

        return Inertia::render('interventions/Index', ['classes' => $classes]);
    }

    public function show(Request $request, SchoolClass $class): Response
    {
        Gate::authorize('view', $class);

        $filters = $request->validate([
            'enrollment_id' => ['nullable', 'integer'],
            'intervention_type' => ['nullable', Rule::enum(InterventionType::class)],
            'context' => ['nullable', Rule::enum(InterventionContext::class)],
            'domain_id' => ['nullable', 'integer'],
            'period_id' => ['nullable', 'integer'],
            // Not the 'boolean' rule: it accepts only 1/0, and a query string
            // built by the front end carries "true"/"false". Spelling out both
            // forms keeps the filter working without silently accepting junk.
            'available_for_reports' => ['nullable', Rule::in(['0', '1', 'true', 'false'])],
            'support_measure_level' => ['nullable', Rule::enum(SupportMeasureLevel::class)],
        ]);

        $period = ($filters['period_id'] ?? null) !== null
            ? AcademicPeriod::where('academic_year_id', $class->academic_year_id)->whereKey((int) $filters['period_id'])->first()
            : null;

        // The framework offered by the FORM, for interventions about to be
        // created: today's date is the right one here because a new
        // intervention defaults to today. Each listed intervention is presented
        // under the framework of its own started_on (see presentIntervention).
        $framework = $this->frameworkFor(Carbon::now());

        $interventions = Intervention::query()
            ->forClass($class->id)
            ->when($filters['enrollment_id'] ?? null, fn ($query, $enrollmentId) => $query->forEnrollment((int) $enrollmentId))
            ->when($filters['intervention_type'] ?? null, fn ($query, $type) => $query->where('intervention_type', $type))
            ->when($filters['context'] ?? null, fn ($query, $context) => $query->byContext(InterventionContext::from($context)))
            ->when($filters['domain_id'] ?? null, fn ($query, $domainId) => $query->byDomain((int) $domainId))
            ->when($period, fn ($query, $academicPeriod) => $query->inPeriod($academicPeriod))
            // filter_var, not a (bool) cast: the value arrives from a query
            // string, and (bool) "false" is true — which would silently invert
            // this filter for anyone asking for the non-available ones.
            ->when(($filters['available_for_reports'] ?? null) !== null,
                fn ($query) => $query->where(
                    'available_for_reports',
                    filter_var($filters['available_for_reports'], FILTER_VALIDATE_BOOLEAN),
                ))
            ->when($filters['support_measure_level'] ?? null,
                fn ($query, $level) => $query->bySupportMeasureLevel(SupportMeasureLevel::from($level)))
            ->with(['participants.student.identity', 'domain', 'reviews'])
            ->orderByDesc('started_on')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (Intervention $intervention) => $this->presentIntervention($intervention));

        return Inertia::render('interventions/Show', [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label, 'subject' => $class->subject->name],
            // EVERYONE WHO WAS EVER ON THIS ROLL, because the list also feeds
            // the filter and the edit form: an intervention recorded in
            // November belongs to the students of November, and neither
            // finding it nor correcting it may depend on them still being here.
            'enrollments' => $class->enrollments()->with('student.identity')->orderBy('class_number')->get()
                ->map(fn (Enrollment $enrollment) => [
                    'id' => $enrollment->id,
                    'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                ]),
            // The class as it stands — the only students a NEW intervention may
            // name. The form picks from this one and falls back to the list
            // above only for participants an intervention already has (§3.1).
            'activeEnrollmentIds' => $class->activeEnrollments()->pluck('id'),
            'domains' => Domain::where('subject_id', $class->subject_id)->orderBy('name')->get(['id', 'name']),
            'periods' => AcademicPeriod::where('academic_year_id', $class->academic_year_id)
                ->orderBy('sequence')->get(['id', 'label']),
            'types' => InterventionType::catalogue($framework),
            'contexts' => array_map(
                fn (InterventionContext $context) => ['value' => $context->value, 'label' => $context->label()],
                InterventionContext::cases(),
            ),
            'domainRelations' => array_map(
                fn (InterventionDomainRelation $relation) => ['value' => $relation->value, 'label' => $relation->label()],
                InterventionDomainRelation::cases(),
            ),
            'targetTypes' => array_map(
                fn (InterventionTargetType $target) => ['value' => $target->value, 'label' => $target->label()],
                InterventionTargetType::cases(),
            ),
            // Always present, possibly empty: the UI's contract keeps the same
            // shape in every jurisdiction, so a page never breaks over a
            // missing property — with no framework it simply has nothing to
            // render, and never borrows another country's taxonomy.
            'legalFramework' => $framework->hasLegalTaxonomy() ? ['code' => $framework->code()] : null,
            'supportMeasureLevels' => $framework->supportMeasureLevels(),
            'evaluationAdaptations' => $framework->evaluationAdaptations(),
            'effectivenessOptions' => array_map(
                fn (InterventionEffectiveness $option) => ['value' => $option->value, 'label' => $option->label()],
                InterventionEffectiveness::cases(),
            ),
            'filters' => $filters,
            'interventions' => $interventions,
        ]);
    }

    public function store(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $validated = $this->validatePayload($request);
        $type = InterventionType::from($validated['intervention_type']);
        $this->guardCrossReferences($class, $validated, isNew: true);

        // Resolved at the intervention's own date, not today's.
        $framework = $this->frameworkFor(Carbon::parse($validated['started_on']));
        $framing = $this->resolveLegalFraming($framework, $type, $validated);

        DB::transaction(function () use ($class, $validated, $type, $framing): void {
            $participantIds = $validated['enrollment_ids'] ?? [];

            $intervention = Intervention::create([
                'class_id' => $class->id,
                // Kept in step with the pivot for the single-student case, so
                // the pre-existing column never goes stale (see the model).
                'enrollment_id' => $validated['target_type'] === InterventionTargetType::Student->value
                    ? $participantIds[0]
                    : null,
                'domain_id' => $validated['domain_relation'] === InterventionDomainRelation::Specific->value
                    ? $validated['domain_id']
                    : null,
                'target_type' => $validated['target_type'],
                'intervention_type' => $type,
                'domain_relation' => $validated['domain_relation'],
                // The type's own label is the title: the teacher is never asked
                // to invent one (§3.1).
                'title' => $type->label(),
                'description' => $validated['description'] ?? null,
                'description_source' => InterventionDescriptionSource::Manual,
                'status' => InterventionStatus::New,
                'started_on' => $validated['started_on'],
                'available_for_reports' => $validated['available_for_reports'] ?? true,
                // Legacy column, kept in step so nothing that still reads it
                // sees a different answer than the new one.
                'include_in_report' => $validated['available_for_reports'] ?? true,
                ...$framing,
                'created_by' => $this->user()->getKey(),
            ]);

            $intervention->participants()->sync($participantIds);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Intervenção registada.')]);

        return back();
    }

    /**
     * A full edit. Status changes keep their own lighter endpoint below, so the
     * quick "Concluir" action does not have to resend the whole record.
     */
    public function update(Request $request, Intervention $intervention): RedirectResponse
    {
        $class = $intervention->schoolClass;
        Gate::authorize('update', $class);

        $validated = $this->validatePayload($request);
        $type = InterventionType::from($validated['intervention_type']);
        $this->guardCrossReferences($class, $validated);

        // The submitted started_on, not today: an intervention edited years
        // later is still read under the law in force when it happened.
        $framework = $this->frameworkFor(Carbon::parse($validated['started_on']));
        $this->guardLegalFramingConflict($framework, $intervention, $type, $validated);

        $framing = $this->resolveLegalFraming($framework, $type, $validated, $intervention);

        DB::transaction(function () use ($intervention, $validated, $type, $framing): void {
            $participantIds = $validated['enrollment_ids'] ?? [];

            $intervention->fill([
                'enrollment_id' => $validated['target_type'] === InterventionTargetType::Student->value
                    ? $participantIds[0]
                    : null,
                'domain_id' => $validated['domain_relation'] === InterventionDomainRelation::Specific->value
                    ? $validated['domain_id']
                    : null,
                'target_type' => $validated['target_type'],
                'intervention_type' => $type,
                'domain_relation' => $validated['domain_relation'],
                'title' => $type->label(),
                'description' => $validated['description'] ?? null,
                'started_on' => $validated['started_on'],
                'available_for_reports' => $validated['available_for_reports'] ?? true,
                'include_in_report' => $validated['available_for_reports'] ?? true,
                ...$framing,
            ])->save();

            $intervention->participants()->sync($participantIds);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Intervenção atualizada.')]);

        return back();
    }

    /**
     * The lifecycle, kept from the original module: an intervention may be
     * one-off (registered and never touched again) or followed over time. This
     * is deliberately a separate, optional action — never part of registering.
     */
    public function updateStatus(Request $request, Intervention $intervention): RedirectResponse
    {
        Gate::authorize('update', $intervention->schoolClass);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(InterventionStatus::class)],
        ]);

        $status = InterventionStatus::from($validated['status']);

        $intervention->fill([
            'status' => $status,
            // Stamp the conclusion date the moment it is concluded; clear it if
            // the intervention is reopened or cancelled.
            'concluded_on' => $status === InterventionStatus::Concluded ? now()->toDateString() : null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Estado atualizado.')]);

        return back();
    }

    public function addReview(Request $request, Intervention $intervention): RedirectResponse
    {
        Gate::authorize('update', $intervention->schoolClass);

        $validated = $request->validate([
            'reviewed_on' => ['required', 'date'],
            'effectiveness' => ['nullable', Rule::enum(InterventionEffectiveness::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $intervention->reviews()->create([
            'reviewed_on' => $validated['reviewed_on'],
            'effectiveness' => $validated['effectiveness'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'reviewed_by' => $this->user()->getKey(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Apreciação adicionada.')]);

        return back();
    }

    public function destroy(Intervention $intervention): RedirectResponse
    {
        Gate::authorize('update', $intervention->schoolClass);

        $intervention->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Intervenção removida.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatePayload(Request $request): array
    {
        $domainRelation = $request->input('domain_relation');
        $type = $request->input('intervention_type');

        return $request->validate([
            'target_type' => ['required', Rule::enum(InterventionTargetType::class)],
            // A class-wide intervention names nobody; a group needs at least
            // two, otherwise it is a student intervention wearing a group's
            // clothes. Counts are enforced by the enum so the rule lives in one
            // place.
            'enrollment_ids' => ['present', 'array'],
            'enrollment_ids.*' => ['integer'],
            'intervention_type' => ['required', Rule::enum(InterventionType::class)],
            'domain_relation' => ['required', Rule::enum(InterventionDomainRelation::class)],
            'domain_id' => [
                Rule::requiredIf($domainRelation === InterventionDomainRelation::Specific->value),
                Rule::prohibitedIf($domainRelation !== InterventionDomainRelation::Specific->value),
                'nullable', 'integer',
            ],
            // «Outro» says nothing on its own, so it is the one type where the
            // teacher must write what was done (§8).
            'description' => [
                Rule::requiredIf($type === InterventionType::Other->value),
                'nullable', 'string', 'max:5000',
            ],
            'started_on' => ['required', 'date'],
            'available_for_reports' => ['boolean'],
            // How the legal framing should be settled. Never the framing's
            // source — that is the server's to decide (see resolveLegalFraming).
            'legal_framing' => ['nullable', Rule::in(['auto', 'manual', 'none'])],
            'confirm_suggested_framing' => ['boolean'],
            'support_measure_level' => ['nullable', Rule::enum(SupportMeasureLevel::class)],
            'support_measure_code' => ['nullable', Rule::enum(SupportMeasureCode::class)],
            'evaluation_adaptation_code' => ['nullable', Rule::enum(EvaluationAdaptationCode::class)],
        ], [], [
            'enrollment_ids' => __('alunos'),
        ]);
    }

    /**
     * Everything the request claims must belong to the class the route names —
     * ids from the client are never taken on trust (§21).
     *
     * @param  array<string, mixed>  $validated
     */
    protected function guardCrossReferences(SchoolClass $class, array $validated, bool $isNew = false): void
    {
        $targetType = InterventionTargetType::from($validated['target_type']);
        /** @var list<int> $participantIds */
        $participantIds = array_values(array_unique($validated['enrollment_ids'] ?? []));

        if (! $targetType->acceptsParticipantCount(count($participantIds))) {
            throw ValidationException::withMessages([
                'enrollment_ids' => match ($targetType) {
                    InterventionTargetType::Student => __('Escolha exatamente um aluno.'),
                    InterventionTargetType::Group => __('Um grupo precisa de pelo menos dois alunos.'),
                    InterventionTargetType::SchoolClass => __('Uma intervenção de turma não seleciona alunos individuais.'),
                },
            ]);
        }

        if ($participantIds !== []) {
            // MEMBERSHIP IS CHECKED AGAINST EVERYONE WHO WAS EVER ON THIS ROLL.
            // An intervention recorded in November belongs to the students of
            // November, and editing it — to correct a date, a description, a
            // domain — must not fail because one of them has since moved class.
            $belonging = $class->enrollments()->whereKey($participantIds)->count();

            if ($belonging !== count($participantIds)) {
                throw ValidationException::withMessages([
                    'enrollment_ids' => __('Aluno inválido para esta turma.'),
                ]);
            }

            // A NEW record is about the class as it stands, so it may only name
            // students who are in it. Only on create: this must never be able
            // to reject an edit to something already recorded (§3.2, §3.3).
            if ($isNew && $class->activeEnrollments()->whereKey($participantIds)->count() !== count($participantIds)) {
                throw ValidationException::withMessages([
                    'enrollment_ids' => __('Um aluno que já não integra a turma não pode entrar num registo novo.'),
                ]);
            }
        }

        if (($validated['domain_id'] ?? null) !== null
            && ! Domain::where('subject_id', $class->subject_id)->whereKey($validated['domain_id'])->exists()) {
            throw ValidationException::withMessages([
                'domain_id' => __('Domínio inválido para esta disciplina.'),
            ]);
        }

        if (($validated['support_measure_code'] ?? null) !== null
            && ($validated['support_measure_level'] ?? null) !== null
            && SupportMeasureCode::from($validated['support_measure_code'])->level()->value !== $validated['support_measure_level']) {
            throw ValidationException::withMessages([
                'support_measure_code' => __('A medida não pertence ao nível selecionado.'),
            ]);
        }
    }

    /**
     * Changing the type must not quietly overwrite a framing the teacher chose
     * by hand. When the new type carries an unambiguous framing of its own that
     * disagrees with the stored manual one, the edit stops and asks (§20).
     *
     * @param  array<string, mixed>  $validated
     */
    protected function guardLegalFramingConflict(InterventionLegalFramework $framework, Intervention $intervention, InterventionType $type, array $validated): void
    {
        $typeChanged = $intervention->intervention_type !== $type;
        $storedIsManual = $intervention->legal_mapping_source === LegalMappingSource::Manual;
        $decisionProvided = ($validated['legal_framing'] ?? null) !== null;

        if (! $typeChanged || ! $storedIsManual || $decisionProvided) {
            return;
        }

        $mapping = $framework->mappingFor($type);

        if ($mapping->isAppliedAutomatically() && $mapping->measure !== $intervention->support_measure_code) {
            throw ValidationException::withMessages([
                'intervention_type' => __('Este tipo tem um enquadramento próprio que difere do que definiu manualmente. Confirme qual pretende manter na secção «Enquadramento pedagógico/legal».'),
            ]);
        }
    }

    /**
     * Turns the teacher's intent into the four stored framing columns.
     *
     * The source is decided here and never accepted from the client, because it
     * is what tells a future report whether a framing was the app's unambiguous
     * reading or a human decision. A contextual suggestion that nobody
     * confirmed is stored as nothing at all (§12.2) — it may be shown, but it
     * must never be readable later as if the teacher had agreed to it.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function resolveLegalFraming(InterventionLegalFramework $framework, InterventionType $type, array $validated, ?Intervention $existing = null): array
    {
        $none = [
            'support_measure_level' => null,
            'support_measure_code' => null,
            'evaluation_adaptation_code' => null,
            'legal_mapping_source' => null,
        ];

        $decision = $validated['legal_framing'] ?? null;

        // An edit that says nothing about framing keeps whatever was decided
        // before — silence is not a request to clear it. This covers a
        // confirmed suggestion as much as a hand-picked one: both are the
        // teacher's decision, and only the mode of arriving at it differs.
        //
        // The exception is a framing the system derived for a type that has
        // since changed: that one belonged to the old type and is recomputed.
        // A manual framing survives even then, because the teacher chose it
        // independently of the type — and guardLegalFramingConflict() has
        // already stopped the edit if the new type disagrees with it.
        if ($decision === null && $existing !== null && $existing->legal_mapping_source !== null) {
            $typeUnchanged = $existing->intervention_type === $type;

            if ($typeUnchanged || $existing->legal_mapping_source === LegalMappingSource::Manual) {
                return [
                    'support_measure_level' => $existing->support_measure_level,
                    'support_measure_code' => $existing->support_measure_code,
                    'evaluation_adaptation_code' => $existing->evaluation_adaptation_code,
                    'legal_mapping_source' => $existing->legal_mapping_source,
                ];
            }
        }

        if ($decision === 'none') {
            return $none;
        }

        if ($decision === 'manual') {
            $measure = ($validated['support_measure_code'] ?? null) !== null
                ? SupportMeasureCode::from($validated['support_measure_code'])
                : null;
            $level = ($validated['support_measure_level'] ?? null) !== null
                ? SupportMeasureLevel::from($validated['support_measure_level'])
                : $measure?->level();
            $adaptation = ($validated['evaluation_adaptation_code'] ?? null) !== null
                ? EvaluationAdaptationCode::from($validated['evaluation_adaptation_code'])
                : null;

            if ($level === null && $measure === null && $adaptation === null) {
                return $none;
            }

            return [
                'support_measure_level' => $level,
                'support_measure_code' => $measure,
                'evaluation_adaptation_code' => $adaptation,
                'legal_mapping_source' => LegalMappingSource::Manual,
            ];
        }

        // Automatic: only what the framework can assert on its own. With no
        // framework this is always LegalMapping::none(), so an organization in
        // an unsupported jurisdiction simply records no legal framing.
        $mapping = $framework->mappingFor($type);

        if ($mapping->isAppliedAutomatically()) {
            return [
                // Deliberately null for an assessment adaptation: using one
                // says nothing about the student's measure level (§12.3).
                'support_measure_level' => $mapping->level(),
                'support_measure_code' => $mapping->measure,
                'evaluation_adaptation_code' => $mapping->evaluationAdaptation,
                'legal_mapping_source' => LegalMappingSource::SystemDirect,
            ];
        }

        if ($mapping->mode === LegalMappingMode::Contextual
            && ($validated['confirm_suggested_framing'] ?? false)) {
            return [
                'support_measure_level' => $mapping->level(),
                'support_measure_code' => $mapping->measure,
                'evaluation_adaptation_code' => null,
                'legal_mapping_source' => LegalMappingSource::SystemSuggestedConfirmed,
            ];
        }

        return $none;
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentIntervention(Intervention $intervention): array
    {
        return [
            'ulid' => $intervention->ulid,
            'title' => $intervention->title,
            'description' => $intervention->description,
            'intervention_type' => $intervention->intervention_type?->value,
            'context' => $intervention->context()?->value,
            'context_label' => $intervention->context()?->label(),
            'target_type' => $intervention->target_type->value,
            'target_label' => $this->targetLabel($intervention),
            'participant_ids' => $intervention->participants->pluck('id')->all(),
            'domain_relation' => $intervention->domain_relation->value,
            'domain_id' => $intervention->domain_id,
            'domain' => $intervention->domain?->name,
            'status' => $intervention->status->value,
            'status_label' => $intervention->status->label(),
            'is_closed' => $intervention->status->isClosed(),
            'started_on' => $intervention->started_on->toDateString(),
            'expected_end_on' => $intervention->expected_end_on?->toDateString(),
            'concluded_on' => $intervention->concluded_on?->toDateString(),
            'available_for_reports' => $intervention->available_for_reports,
            'legal_framing' => $intervention->hasConfirmedLegalFraming() ? [
                'level' => $intervention->support_measure_level?->value,
                'level_label' => $intervention->support_measure_level?->label(),
                'measure' => $intervention->support_measure_code?->value,
                'measure_label' => $intervention->support_measure_code?->label(),
                'evaluation_adaptation' => $intervention->evaluation_adaptation_code?->value,
                'evaluation_adaptation_label' => $intervention->evaluation_adaptation_code?->label(),
                'source' => $intervention->legal_mapping_source?->value,
                'source_label' => $intervention->legal_mapping_source?->label(),
            ] : null,
            'reviews' => $intervention->reviews->map(fn (InterventionReview $review) => [
                'ulid' => $review->ulid,
                'reviewed_on' => $review->reviewed_on->toDateString(),
                'effectiveness' => $review->effectiveness?->value,
                'effectiveness_label' => $review->effectiveness?->label(),
                'notes' => $review->notes,
            ])->all(),
        ];
    }

    /**
     * Who the intervention was for, in one line: the student's name, the number
     * of students in the group, or simply the class.
     */
    protected function targetLabel(Intervention $intervention): string
    {
        return match ($intervention->target_type) {
            InterventionTargetType::SchoolClass => __('Turma inteira'),
            InterventionTargetType::Student => $intervention->participants
                ->map(fn (Enrollment $enrollment) => optional($enrollment->student->identity)->display_name ?? __('(sem identidade)'))
                ->first() ?? __('(sem aluno)'),
            InterventionTargetType::Group => __(':count alunos', ['count' => $intervention->participants->count()]),
        };
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
