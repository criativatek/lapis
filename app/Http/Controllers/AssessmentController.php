<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\AssessmentSummaryQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Avaliações (§X): a read-only view of the instruments a teacher already
 * created, framed as "what's happening" rather than "what I built". Creating
 * one hands off to instruments.create; correcting one hands off to
 * instruments.show/Grid.vue — nothing here writes an Instrument.
 */
class AssessmentController extends Controller
{
    public function __construct(protected AssessmentSummaryQuery $summary) {}

    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $purpose = $request->query('purpose');
        $academicPeriodId = $request->query('period') !== null ? (int) $request->query('period') : null;

        return Inertia::render('assessments/Index', [
            'assessments' => $this->summary->listFor($this->user(), $status, $purpose, $academicPeriodId),
            'filters' => ['status' => $status, 'purpose' => $purpose, 'period' => $academicPeriodId],
            'statusOptions' => array_map(
                fn (InstrumentStatus $case) => ['value' => $case->value, 'label' => $case->label()],
                InstrumentStatus::cases(),
            ),
            'purposeOptions' => [
                ['value' => 'diagnostic', 'label' => AssessmentSummaryQuery::purposeLabel('diagnostic')],
                ['value' => 'formative', 'label' => AssessmentSummaryQuery::purposeLabel('formative')],
                ['value' => 'summative', 'label' => AssessmentSummaryQuery::purposeLabel('summative')],
                ['value' => 'other', 'label' => AssessmentSummaryQuery::purposeLabel('other')],
            ],
            'periodOptions' => $this->periodOptionsFor($this->user()),
            'classOptions' => $this->classOptionsFor($this->user()),
        ]);
    }

    /**
     * Same authorization pattern as InstrumentController::show() — the acting
     * teacher must teach this instrument's class. There is no InstrumentPolicy;
     * SchoolClassPolicy is what every instrument action already gates on.
     */
    public function show(Instrument $instrument): Response
    {
        Gate::authorize('view', $instrument->schoolClass);

        return Inertia::render('assessments/Show', $this->summary->summaryFor($instrument));
    }

    /**
     * Only the periods the teacher's own instruments actually use — not every
     * period of every academic year, most of which would never match a row.
     *
     * @return array<int, array{id: int, label: string}>
     */
    protected function periodOptionsFor(User $teacher): array
    {
        $periodIds = Instrument::query()
            ->whereHas('schoolClass.teachers', fn ($query) => $query->whereKey($teacher->getKey()))
            ->distinct()
            ->pluck('academic_period_id');

        return AcademicPeriod::whereIn('id', $periodIds)
            ->orderBy('sequence')
            ->get(['id', 'label'])
            ->map(fn (AcademicPeriod $period) => ['id' => $period->id, 'label' => $period->label])
            ->values()
            ->all();
    }

    /**
     * "Nova avaliação" needs a class before instruments.create's own route
     * even resolves (turma is a required URL segment, not a form field) —
     * this is the picker's source list, same teaches-the-class scoping as
     * everywhere else in this controller.
     *
     * @return array<int, array{ulid: string, label: string}>
     */
    protected function classOptionsFor(User $teacher): array
    {
        return SchoolClass::query()
            ->whereHas('teachers', fn ($query) => $query->whereKey($teacher->getKey()))
            ->orderBy('label')
            ->get(['ulid', 'label'])
            ->map(fn (SchoolClass $class) => ['ulid' => $class->ulid, 'label' => $class->label])
            ->values()
            ->all();
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
