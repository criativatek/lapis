<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\OpenClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\PublishClassifications;
use App\Services\Assessment\ScaleProposalResolver;
use App\Support\Assessment\ClassificationDecisionException;
use App\Support\Assessment\DecisionScale;
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

        $enrollments = $class->enrollments()->with('student.identity')->orderBy('class_number')->get();

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
            'periods' => $periods->map(fn (AcademicPeriod $academicPeriod) => [
                'ulid' => $academicPeriod->ulid,
                'label' => $academicPeriod->label,
                'selected' => $selected !== null && $academicPeriod->id === $selected->id,
            ]),
            'rows' => $enrollments->map(function ($enrollment) use ($live, $scale, $roundingMode, $roundingScale, $alongside, $scope) {
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
                        'decision' => $this->decisionPayload($classification),
                        'overridden' => $classification->wasOverridden(),
                        'observation' => $classification->override_reason,
                        // «Usar proposta» is how a FIRST decision is made, so it
                        // belongs to a proposal alone…
                        'can_confirm' => $classification->status === ClassificationStatus::Proposed,
                        // …while «Alterar» stays available until publication.
                        'can_change' => $classification->status->allowsDecision(),
                        'is_published' => $classification->status->isPublished(),
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
    protected function decisionPayload(Classification $classification): ?array
    {
        $level = $classification->finalScaleLevel;

        if ($level !== null) {
            return [
                'code' => (string) $level->code,
                'label' => (string) $level->label,
                'scale_level_id' => (int) $level->id,
            ];
        }

        if ($classification->final_value === null) {
            return null;
        }

        // An interval scale has no band to name: the number is the whole answer.
        return [
            'code' => (string) $classification->final_value,
            'label' => null,
            'scale_level_id' => null,
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

    public function publish(Request $request, SchoolClass $class, string $period): RedirectResponse
    {
        Gate::authorize('update', $class);

        $scope = ClassificationScope::tryFrom((string) $request->input('scope')) ?? ClassificationScope::Period;

        $selected = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->where('ulid', $period)->firstOrFail();

        $counts = $this->publisher->forPeriod($class, $selected, $scope);

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
    public function decide(Request $request, SchoolClass $class, string $period, Enrollment $enrollment): RedirectResponse
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

        Inertia::flash('toast', ['type' => 'success', 'message' => $changing
            ? __('Classificação alterada.')
            : __('Classificação confirmada.')]);

        return back();
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
