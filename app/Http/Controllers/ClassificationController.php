<?php

namespace App\Http\Controllers;

use App\Http\Requests\RectifyClassificationRequest;
use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Enrollment;
use App\Models\RectificationReason;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ClassCohort;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\DecisionScaleReader;
use App\Services\Assessment\ExternalCommunicationNotice;
use App\Services\Assessment\OpenClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\PublishClassifications;
use App\Services\Assessment\RectifyClassification;
use App\Services\Assessment\ScaleProposalResolver;
use App\Support\Assessment\ClassificationDecisionException;
use App\Support\Assessment\DecisionScale;
use App\Support\Assessment\RectificationPayload;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The decision layer over the engine's results (§7): the teacher generates
 * proposals, reviews them, and confirms — accepting the deterministic value or
 * overriding it with a reason. The system never confirms on its own (§3.3).
 */
class ClassificationController extends Controller
{
    public function __construct(
        protected ProposeClassifications $proposer,
        protected ConfirmClassification $confirmer,
        protected PublishClassifications $publisher,
        protected ScaleProposalResolver $proposals,
        protected BuildResultsProgression $progression,
        protected OpenClassification $opener,
        protected DecisionScaleReader $decisionScales,
    ) {}

    public function show(Request $request, SchoolClass $class, ?string $period = null): Response
    {
        Gate::authorize('view', $class);

        $scope = ClassificationScope::tryFrom((string) $request->query('scope')) ?? ClassificationScope::Period;

        $periods = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->orderBy('sequence')->get();

        $selected = $period !== null
            ? $periods->firstWhere('ulid', $period)
            : $periods->first();

        // ClassCohort::for() — o resolver único de «que alunos entram na
        // análise desta disciplina» (ver o seu docblock). AttendingOnly: a
        // classificação é a decisão sobre o resultado calculado, e um aluno
        // que não frequenta esta disciplina não tem proposta nenhuma para
        // decidir.
        //
        // H5: lido à data DO PERÍODO SELECIONADO, não «hoje» — um período de
        // março consultado hoje não pode reescrever a história com uma janela
        // de não-frequência que só abriu depois de março. Sem período
        // selecionado (turma ainda sem períodos), mantém-se hoje.
        $enrollments = ClassCohort::for(
            $class,
            $selected === null ? null : ClassCohort::asOfPeriod($selected),
        )->attending();

        $live = $selected !== null
            ? Classification::query()
                ->with('finalScaleLevel')
                ->where('academic_period_id', $selected->id)
                ->where('scope', $scope)
                ->whereNot('status', ClassificationStatus::Superseded)
                ->whereIn('enrollment_id', $enrollments->pluck('id'))
                ->get()
                ->keyBy('enrollment_id')
            : collect();

        // Same scale and same rounding the Results page reads, so the proposal a
        // teacher sees here is the one they saw there — never 4 in one place and
        // 80% in the other.
        $version = $class->profileVersion;
        $scale = $version?->scale()->with('levels')->first();
        $roundingMode = $version->rounding_mode ?? 'half_up';
        $roundingScale = $version->rounding_scale ?? 0;

        // The same longitudinal read Resultados uses, so the two screens cannot
        // disagree about the same student in the same period (§10). Nothing is
        // recomputed here and nothing is computed in the browser.
        $alongside = $selected === null ? [] : $this->alongsideResults($class, $selected);

        // What the teacher is being asked for, said in the terms of the scale
        // itself. Shared with Resultados, which offers the same decision (§3).
        $decision = DecisionScale::for($scale);

        // Rights for every row, worked out once (see RectificationPayload).
        $rectification = $selected === null ? [] : RectificationPayload::for($this->user(), $class, $selected, $scope, $live);

        return Inertia::render('classifications/Show', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'has_profile' => $class->assessment_profile_version_id !== null,
                'scale_name' => $scale?->name,
                'decision_label' => $decision->label(),
                'classifies_by_level' => $decision->classifiesByLevel(),
                // The closed list to choose from, or the interval to write in.
                'levels' => $decision->levels(),
                'min_value' => $scale === null ? null : (string) $scale->min_value,
                'max_value' => $scale === null ? null : (string) $scale->max_value,
            ],
            'scope' => $scope->value,
            'rectificationReasons' => RectificationReason::options(),
            'periods' => $periods->map(fn (AcademicPeriod $academicPeriod) => [
                'ulid' => $academicPeriod->ulid,
                'label' => $academicPeriod->label,
                'selected' => $selected !== null && $academicPeriod->id === $selected->id,
            ]),
            'rows' => $enrollments->map(function ($enrollment) use ($live, $scale, $roundingMode, $roundingScale, $alongside, $scope, $rectification) {
                /** @var Classification|null $classification */
                $classification = $live->get($enrollment->id);
                $beside = $alongside[$enrollment->id] ?? null;

                return [
                    'enrollment_ulid' => (string) $enrollment->ulid,
                    'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                    'photo_url' => $enrollment->student->photoUrl(),
                    'class_number' => $enrollment->class_number,
                    // Read straight from the results screen's own model, so the
                    // teacher sees the same figures they just left.
                    'weighted_average' => $scope === ClassificationScope::Accumulated
                        ? ($beside['accumulated_average'] ?? null)
                        : ($beside['weighted_average'] ?? null),
                    'self_assessment' => $beside['self_assessment'] ?? null,
                    'classification' => $classification === null ? null : [
                        'ulid' => $classification->ulid,
                        'status' => $classification->status->value,
                        'status_label' => $classification->status->label(),
                        // The proposal read on the profile's scale. The level was
                        // already stored when the proposal was made — this only
                        // reads it, and changes nothing that gets confirmed.
                        'proposal' => $this->proposals->resolve(
                            $scale,
                            $classification->proposed_scale_level_id,
                            $classification->proposed_normalized_value,
                            $classification->proposed_value,
                            $roundingMode,
                            $roundingScale,
                        )->toPayload(),
                        'proposed_scale_level_id' => $classification->proposed_scale_level_id,
                        // The decision, on the scale. Null while it is still only
                        // a proposal — and never filled in from one (§6).
                        'decision' => $this->decisionPayload($classification, $scale),
                        'overridden' => $classification->wasOverridden(),
                        'observation' => $classification->override_reason,
                        // «Usar proposta» is how a FIRST decision is made, so it
                        // belongs to a proposal alone…
                        'can_confirm' => $classification->status === ClassificationStatus::Proposed,
                        // …while «Alterar» stays available until publication.
                        'can_change' => $classification->status->allowsDecision(),
                        'is_published' => $classification->status->isPublished(),
                        // «Retificar», for a published decision (the only one that can be).
                        'rectification' => $rectification[(int) $enrollment->id] ?? null,
                    ],
                ];
            })->values(),
        ]);
    }

    /**
     * What the teacher decided, ready to read: the level's own code with its
     * qualitative mention, or the value written on an interval scale.
     *
     * @return array<string, mixed>|null
     */
    protected function decisionPayload(Classification $classification, ?Scale $currentScale): ?array
    {
        $level = $classification->finalScaleLevel;

        if ($level !== null) {
            return [
                'code' => (string) $level->code,
                'label' => (string) $level->label,
                'scale_level_id' => (int) $level->id,
                // A level is read as the level it is, on whatever scale.
                'is_percentage' => false,
                'scale_name' => null,
            ];
        }

        if ($classification->final_value === null) {
            return null;
        }

        // An interval scale has no band to name: the number is the whole answer,
        // on the scale the decision was TAKEN on — which after a profile
        // migration is not necessarily the class's scale today.
        $taken = $this->decisionScales->read($classification, $currentScale);

        return [
            'code' => (string) $classification->final_value,
            'label' => null,
            'scale_level_id' => null,
            'is_percentage' => $taken['is_percentage'],
            // Filled only when it differs from the class's scale now.
            'scale_name' => $taken['foreign_scale'],
        ];
    }

    /**
     * The figures Resultados shows for this period, keyed by enrolment.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function alongsideResults(SchoolClass $class, AcademicPeriod $selected): array
    {
        $byEnrollment = [];

        foreach ($this->progression->for($class)['students'] as $student) {
            foreach ($student['periods'] as $period) {
                if ($period['period_id'] !== $selected->id) {
                    continue;
                }

                $byEnrollment[$student['enrollment_id']] = [
                    'weighted_average' => $period['weighted_average'],
                    'accumulated_average' => $period['accumulated_average'],
                    'self_assessment' => $period['self_assessment'],
                ];
            }
        }

        return $byEnrollment;
    }

    public function propose(Request $request, SchoolClass $class, string $period): RedirectResponse
    {
        Gate::authorize('update', $class);

        $scope = ClassificationScope::tryFrom((string) $request->input('scope')) ?? ClassificationScope::Period;

        $selected = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->where('ulid', $period)->firstOrFail();

        $counts = $this->proposer->forPeriod($class, $selected, $scope);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(
            ':created propostas geradas, :updated atualizadas, :frozen confirmadas mantidas, :none sem resultado.',
            [
                'created' => $counts['created'],
                'updated' => $counts['updated'],
                'frozen' => $counts['skipped_frozen'],
                'none' => $counts['no_value'],
            ],
        )]);

        return back();
    }

    public function publish(Request $request, SchoolClass $class, string $period, UsageRecorder $usage): RedirectResponse
    {
        Gate::authorize('update', $class);

        $scope = ClassificationScope::tryFrom((string) $request->input('scope')) ?? ClassificationScope::Period;

        $selected = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->where('ulid', $period)->firstOrFail();

        $counts = $this->publisher->forPeriod($class, $selected, $scope);

        if ($counts['published'] > 0) {
            $usage->action(UsageEventType::ClassificationsPublished, 'publish:'.$class->getKey().':'.$selected->getKey(), $counts['published']);
        }

        Inertia::flash('toast', [
            'type' => $counts['blocked_under_review'] > 0 ? 'warning' : 'success',
            'message' => __(
                ':published classificações publicadas, :blocked retidas por elemento em revisão.',
                ['published' => $counts['published'], 'blocked' => $counts['blocked_under_review']],
            ),
        ]);

        return back();
    }

    /**
     * The teacher's decision, from wherever they took it.
     *
     * Keyed by the student and the period rather than by a stored row: the row
     * is where the decision is written, not what it is. A period whose proposals
     * were never generated has none yet, and the classification is no less the
     * teacher's for that — it is opened, then confirmed through the same service
     * that has always confirmed one.
     */
    public function decide(Request $request, SchoolClass $class, string $period, Enrollment $enrollment, UsageRecorder $usage): RedirectResponse
    {
        Gate::authorize('update', $class);

        $selected = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->where('ulid', $period)->firstOrFail();

        // Looked up THROUGH the class, so a URL cannot mix another class's
        // student into this one.
        abort_unless($class->enrollments()->whereKey($enrollment->getKey())->exists(), 404);

        $scope = ClassificationScope::tryFrom((string) $request->input('scope')) ?? ClassificationScope::Period;

        $validated = $request->validate([
            // The level assigned on a scale made of levels. Whether it is one of
            // THIS scale's levels is decided by the service, which is the only
            // place that knows the class's scale.
            'final_scale_level_id' => ['nullable', 'integer'],
            // `decimal` (not `numeric`) rejects scientific notation like "1e2",
            // which would pass numeric+between and then blow up bcmath with a 500;
            // it also caps at 3 places instead of silently rounding on cast.
            // The real limits are the scale's own, checked in the service.
            'final_value' => ['nullable', 'decimal:0,3', 'between:0,999.999'],
            // Optional, always. Deciding differently from the proposal is the
            // teacher's job and not an exception to be justified (§7).
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $level = isset($validated['final_scale_level_id']) ? (int) $validated['final_scale_level_id'] : null;
        $value = isset($validated['final_value']) ? (string) $validated['final_value'] : null;
        $observation = $validated['override_reason'] ?? null;

        try {
            // Found, or opened — through the one service that owns that, never
            // by writing a row from here.
            $classification = $this->opener->forDecision($class, $selected, $enrollment, $scope);

            // A confirmed classification is still the teacher's to revise until
            // it is published — a different act, on the same row, with its own
            // guards. Both re-check the status under a row lock, so a
            // publication landing between this branch and the write still wins.
            $changing = $classification->status === ClassificationStatus::Confirmed;

            $changing
                ? $this->confirmer->redecide($classification, $this->user(), $level, $value, $observation)
                : $this->confirmer->confirm($classification, $this->user(), $level, $value, $observation);
        } catch (ClassificationDecisionException $exception) {
            return back()->withErrors(['final_value' => $exception->getMessage()]);
        }

        $usage->action(UsageEventType::ClassificationDecided, 'classification:'.$enrollment->getKey().':'.$selected->getKey());

        Inertia::flash('toast', ['type' => 'success', 'message' => $changing
            ? __('Classificação alterada.')
            : __('Classificação confirmada.')]);

        return back();
    }

    /**
     * Correcting a decision that was already published.
     *
     * NOT `Gate::authorize('update', $class)`, unlike `decide`: once the period
     * is closed in an institution, the head may rectify a class they do not
     * teach, and the class policy would turn them away. The classification's own
     * policy ability decides, from the classification's organization — see
     * RectificationAccess.
     */
    public function rectify(RectifyClassificationRequest $request, SchoolClass $class, string $period, Enrollment $enrollment, RectifyClassification $rectifier): RedirectResponse
    {
        $selected = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->where('ulid', $period)->firstOrFail();

        // Looked up THROUGH the class, so a URL cannot mix another class's
        // student into this one.
        abort_unless($class->enrollments()->whereKey($enrollment->getKey())->exists(), 404);

        $scope = ClassificationScope::tryFrom((string) $request->input('scope')) ?? ClassificationScope::Period;

        // Only the live, published row of the cell can be rectified; a proposal,
        // a confirmation or an already superseded row is not found here.
        $classification = Classification::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('academic_period_id', $selected->getKey())
            ->where('scope', $scope)
            ->where('status', ClassificationStatus::Published)
            ->whereNull('superseded_by_id')
            ->firstOrFail();

        Gate::authorize('rectify', $classification);

        $validated = $request->validated();

        try {
            $rectifier->rectify(
                $classification,
                $this->user(),
                RectificationReason::from($validated['reason']),
                $validated['note'] ?? null,
                isset($validated['final_scale_level_id']) ? (int) $validated['final_scale_level_id'] : null,
                isset($validated['final_value']) ? (string) $validated['final_value'] : null,
                (int) $validated['lock_version'],
                $request->boolean('closed_confirmed'),
            );
        } catch (ClassificationDecisionException $exception) {
            return back()->withErrors(['rectify' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Classificação retificada.')]);

        return back();
    }

    /**
     * What the rectification dialog needs when it opens, asked for then and not
     * sent with every row: the history of the cell and the warning about having
     * communicated the grade elsewhere. Each costs queries per classification.
     */
    public function rectification(SchoolClass $class, Classification $classification, ExternalCommunicationNotice $notice): JsonResponse
    {
        abort_unless($class->enrollments()->whereKey($classification->enrollment_id)->exists(), 404);

        // The cell, oldest to newest. A link to a row that has since been
        // superseded still answers about the cell, as of now.
        $cell = Classification::query()
            ->with('finalScaleLevel')
            ->where('enrollment_id', $classification->enrollment_id)
            ->where('academic_period_id', $classification->academic_period_id)
            ->where('scope', $classification->scope)
            ->get()
            ->keyBy('id');

        $live = $cell->first(fn (Classification $row) => $row->superseded_by_id === null);
        abort_if($live === null, 404);

        Gate::authorize('viewHistory', $live);

        // Walked from the live row back through `rectifies_id`: the chain is the
        // history, and a row outside it (there should be none) is not shown.
        $chain = [];

        for ($row = $live; $row !== null; $row = $row->rectifies_id === null ? null : $cell->get($row->rectifies_id)) {
            array_unshift($chain, $row);
        }

        $authors = User::query()
            ->whereIn('id', collect($chain)->map(fn (Classification $row) => $row->rectified_by ?? $row->confirmed_by)->filter()->unique())
            ->pluck('name', 'id');

        $scale = $class->profileVersion?->scale()->with('levels')->first();

        return response()->json([
            'history' => collect($chain)->map(function (Classification $row) use ($authors, $scale) {
                $by = $row->rectified_by ?? $row->confirmed_by;

                return [
                    'ulid' => $row->ulid,
                    'status' => $row->status->value,
                    'is_live' => $row->superseded_by_id === null,
                    'final' => $this->decisionPayload($row, $scale),
                    'decided_by' => $by === null ? null : $authors->get($by),
                    'decided_at' => ($row->rectified_at ?? $row->confirmed_at)?->toIso8601String(),
                    'reason' => $row->rectification_reason?->label(),
                    'note' => $row->rectification_note,
                    'rectified' => $row->rectifies_id !== null,
                ];
            })->values()->all(),
            'notice' => $notice->for($live),
        ]);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
