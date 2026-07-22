<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Assessment\ClassificationDecisionException;
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
                ->where('academic_period_id', $selected->id)
                ->where('scope', $scope)
                ->whereNot('status', ClassificationStatus::Superseded)
                ->whereIn('enrollment_id', $enrollments->pluck('id'))
                ->get()
                ->keyBy('enrollment_id')
            : collect();

        return Inertia::render('classifications/Show', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'has_profile' => $class->assessment_profile_version_id !== null,
            ],
            'scope' => $scope->value,
            'periods' => $periods->map(fn (AcademicPeriod $academicPeriod) => [
                'ulid' => $academicPeriod->ulid,
                'label' => $academicPeriod->label,
                'selected' => $selected !== null && $academicPeriod->id === $selected->id,
            ]),
            'rows' => $enrollments->map(function ($enrollment) use ($live) {
                /** @var Classification|null $classification */
                $classification = $live->get($enrollment->id);

                return [
                    'name' => $enrollment->student->identity->display_name,
                    'class_number' => $enrollment->class_number,
                    'classification' => $classification === null ? null : [
                        'ulid' => $classification->ulid,
                        'status' => $classification->status->value,
                        'status_label' => $classification->status->label(),
                        'proposed_value' => $classification->proposed_value,
                        'final_value' => $classification->final_value,
                        'effective_value' => $classification->effectiveValue(),
                        'overridden' => $classification->wasOverridden(),
                        'override_reason' => $classification->override_reason,
                        'can_confirm' => $classification->status === ClassificationStatus::Proposed,
                    ],
                ];
            })->values(),
        ]);
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

    public function confirm(Request $request, Classification $classification): RedirectResponse
    {
        Gate::authorize('update', $classification->enrollment->schoolClass);

        $validated = $request->validate([
            // `decimal` (not `numeric`) rejects scientific notation like "1e2",
            // which would pass numeric+between and then blow up bcmath with a 500;
            // it also caps at 3 places instead of silently rounding on cast.
            'final_value' => ['nullable', 'decimal:0,3', 'between:0,999.999'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->confirmer->confirm(
                $classification,
                $this->user(),
                isset($validated['final_value']) ? (string) $validated['final_value'] : null,
                $validated['override_reason'] ?? null,
            );
        } catch (ClassificationDecisionException $exception) {
            return back()->withErrors(['final_value' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Classificação confirmada.')]);

        return back();
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
