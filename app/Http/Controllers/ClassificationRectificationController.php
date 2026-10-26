<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\RectificationReason;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\DecisionScaleReader;
use App\Services\Audit\AuditLog;
use App\Support\Assessment\DecisionPayload;
use App\Support\Assessment\DecisionScale;
use App\Support\Assessment\RectificationContext;
use App\Support\Assessment\RectificationPayload;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Retificações»: the one door by which the head of an institution reaches the
 * published classifications of a class they do NOT teach, once its period is
 * closed (spec §4). `SchoolClassPolicy::view` stays «only my classes»; this page
 * has its own ability and shows nothing but the published, live decision.
 *
 * What may then be done is still the classification's policy: the page only
 * links to `classifications.rectify` and `classifications.rectification`.
 *
 * Props, exactly (the Vue pages are written against this list and the tests
 * compare the keys for equality):
 *
 * classifications/rectifications/Index
 *   classes: list of {ulid, label, subject, academic_year, teachers: list<string>,
 *            periods: list of {ulid, label, published_count, rectified_count}}
 *
 * classifications/rectifications/Show
 *   schoolClass: {ulid, label, subject, academic_year, scale_name, decision_label,
 *            classifies_by_level, levels, min_value, max_value}
 *   period: {ulid, label}
 *   rectificationReasons: RectificationReason::options()
 *   rows: list of {enrollment_ulid, class_number, name,
 *            classification: {ulid, published_at, decision, rectification}}
 *     decision: {code, label, scale_level_id, is_percentage, scale_name}
 *     rectification: {allowed, needs_confirmation, blocked_reason, lock_version, count}
 *
 * The scope is always `period` (the request's default), so the dialog sends no `scope`.
 */
class ClassificationRectificationController extends Controller
{
    public function __construct(
        protected DecisionScaleReader $decisionScales,
        protected CurrentOrganization $currentOrganization,
        protected AuditLog $audit,
    ) {}

    public function index(): Response
    {
        $organization = $this->currentOrganization->get();

        Gate::authorize('viewRectifications', $organization);

        $classes = SchoolClass::query()
            ->with(['subject', 'academicYear.periods', 'teachers'])
            ->orderByDesc('academic_year_id')->orderBy('label')->orderBy('id')
            ->get();

        // One grouped read for the whole list: live, published, period-scope
        // classifications per class and period, and how many of them are the
        // result of a rectification.
        $counts = Classification::query()
            ->join('enrollments', 'enrollments.id', '=', 'classifications.enrollment_id')
            ->where('classifications.status', ClassificationStatus::Published)
            ->whereNull('classifications.superseded_by_id')
            ->where('classifications.scope', ClassificationScope::Period)
            ->selectRaw('enrollments.class_id as class_id, classifications.academic_period_id as period_id, count(*) as published, sum(case when classifications.rectifies_id is null then 0 else 1 end) as rectified')
            ->groupBy('enrollments.class_id', 'classifications.academic_period_id')
            ->get()
            ->groupBy('class_id');

        $payload = [];

        foreach ($classes as $class) {
            $byPeriod = ($counts->get($class->id) ?? collect())->keyBy('period_id');
            $periods = [];

            foreach ($class->academicYear->periods as $period) {
                $row = $byPeriod->get($period->id);

                // «Closed» is decided in ONE place (RectificationContext::closed).
                if ($row === null || ! RectificationContext::closed($class->academicYear, $period)) {
                    continue;
                }

                $periods[] = [
                    'ulid' => $period->ulid,
                    'label' => $period->label,
                    'published_count' => (int) $row->published,
                    'rectified_count' => (int) $row->rectified,
                ];
            }

            if ($periods === []) {
                continue;
            }

            $payload[] = [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject?->name,
                'academic_year' => $class->academicYear->label,
                // Names only: the page has no use for an address.
                'teachers' => $class->teachers->pluck('name')->sort()->values()->all(),
                'periods' => $periods,
            ];
        }

        return Inertia::render('classifications/rectifications/Index', ['classes' => $payload]);
    }

    public function show(Request $request, SchoolClass $class, string $period): Response
    {
        Gate::authorize('reviewRectifications', $class);

        $selected = AcademicPeriod::query()
            ->where('academic_year_id', $class->academic_year_id)
            ->where('ulid', $period)
            ->firstOrFail();

        // An open period is the teacher's: nothing here to review, and no hint that
        // anything exists.
        abort_unless(RectificationContext::closed($class->academicYear, $selected), 404);

        $user = $this->user();

        $enrollments = $class->enrollments()->with('student.identity')->get()->keyBy('id');

        $live = Classification::query()
            ->with('finalScaleLevel')
            ->where('academic_period_id', $selected->getKey())
            ->where('scope', ClassificationScope::Period)
            ->where('status', ClassificationStatus::Published)
            ->whereNull('superseded_by_id')
            ->whereIn('enrollment_id', $enrollments->keys())
            ->get();

        $scale = $class->profileVersion?->scale()->with('levels')->first();
        $decision = DecisionScale::for($scale);
        $decisions = new DecisionPayload($this->decisionScales);
        $rectification = RectificationPayload::for($user, $class, $selected, ClassificationScope::Period, $live);

        // Looking at a class one does not teach leaves a trace. A partial reload or
        // a prefetch is not an opening of the table.
        $this->recordViewed($request, $user, $class, $selected);

        $rows = $live->map(function (Classification $classification) use ($enrollments, $decisions, $scale, $rectification): array {
            $enrollment = $enrollments->get($classification->enrollment_id);

            return [
                'enrollment_ulid' => (string) $enrollment->ulid,
                'class_number' => $enrollment->class_number,
                'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                'classification' => [
                    'ulid' => $classification->ulid,
                    'published_at' => $classification->published_at?->toIso8601String(),
                    'decision' => $decisions->for($classification, $scale),
                    'rectification' => $rectification[(int) $classification->enrollment_id],
                ],
            ];
        })->sortBy(fn (array $row) => [$row['class_number'] ?? PHP_INT_MAX, $row['name']])->values();

        return Inertia::render('classifications/rectifications/Show', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject?->name,
                'academic_year' => $class->academicYear->label,
                'scale_name' => $scale?->name,
                'decision_label' => $decision->label(),
                'classifies_by_level' => $decision->classifiesByLevel(),
                'levels' => $decision->levels(),
                'min_value' => $scale === null ? null : (string) $scale->min_value,
                'max_value' => $scale === null ? null : (string) $scale->max_value,
            ],
            'period' => ['ulid' => $selected->ulid, 'label' => $selected->label],
            'rectificationReasons' => RectificationReason::options(),
            'rows' => $rows,
        ]);
    }

    private function recordViewed(Request $request, User $user, SchoolClass $class, AcademicPeriod $period): void
    {
        if ($request->hasHeader('X-Inertia-Partial-Component') || $request->header('Purpose') === 'prefetch') {
            return;
        }

        // A head who also teaches the class is looking at their own class.
        if ($class->teachers()->whereKey($user->getKey())->exists()) {
            return;
        }

        $this->audit->record(
            'classification.rectifications_viewed',
            $class,
            $user,
            "Retificações consultadas: {$class->label}, {$period->label}.",
            [
                'class_ulid' => $class->ulid,
                'period_ulid' => $period->ulid,
                'period_label' => $period->label,
            ],
        );
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
