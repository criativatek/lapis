<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\ScaleProposalResolver;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ResultsController extends Controller
{
    public function __construct(
        protected ClassResultsCalculator $calculator,
        protected ScaleProposalResolver $proposals,
    ) {}

    public function index(): Response
    {
        $classes = SchoolClass::query()
            ->whereHas('teachers', fn ($query) => $query->whereKey($this->user()->getKey()))
            ->with(['subject', 'academicYear'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SchoolClass $class) => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'has_profile' => $class->assessment_profile_version_id !== null,
            ]);

        return Inertia::render('results/Index', ['classes' => $classes]);
    }

    public function show(SchoolClass $class, ?string $period = null): Response
    {
        Gate::authorize('view', $class);

        $periods = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->orderBy('sequence')->get();

        $selected = $period !== null
            ? $periods->firstWhere('ulid', $period)
            : $periods->first();

        $results = $selected !== null
            ? $this->calculator->forPeriod($class, $selected)
            : [];

        // Domain names for the columns — the stable domains this class's version uses.
        $domainNames = Domain::whereIn(
            'id',
            collect($results)->flatMap(fn ($row) => collect($row['outcome']->domains)->pluck('domainId'))->unique()->values(),
        )->pluck('name', 'id');

        // The scale the profile version in force actually uses — with its levels,
        // so resolving each row's proposal costs no further query. The rounding
        // travels with it: a numeric scale's proposal obeys the same rule the
        // teacher configured for the result.
        $version = $class->profileVersion;
        $scale = $version?->scale()->with('levels')->first();
        $roundingMode = $version->rounding_mode ?? 'half_up';
        $roundingScale = $version->rounding_scale ?? 0;

        return Inertia::render('results/Show', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'has_profile' => $class->assessment_profile_version_id !== null,
                'scale_name' => $scale?->name,
            ],
            'periods' => $periods->map(fn (AcademicPeriod $academicPeriod) => [
                'ulid' => $academicPeriod->ulid,
                'label' => $academicPeriod->label,
                'selected' => $selected !== null && $academicPeriod->id === $selected->id,
            ]),
            'domains' => collect($results)
                ->flatMap(fn ($row) => collect($row['outcome']->domains)->pluck('domainId'))
                ->unique()->values()
                ->map(fn (int $id) => ['id' => $id, 'name' => $domainNames[$id] ?? '—']),
            'rows' => array_map(fn (array $row) => [
                'name' => optional($row['enrollment']->student->identity)->display_name ?? '(sem identidade)',
                'photo_url' => $row['enrollment']->student->photoUrl(),
                'class_number' => $row['enrollment']->class_number,
                'overall' => $row['outcome']->normalizedValue,
                // The result stays a percentage; the proposal is that result read
                // on the profile's own scale — a 4, a 16, an 80%, a "Bom".
                'proposal' => $this->proposals->resolve(
                    $scale,
                    $row['outcome']->scaleLevelId,
                    $row['outcome']->normalizedValue,
                    $row['outcome']->proposedValue,
                    $roundingMode,
                    $roundingScale,
                )->toPayload(),
                'has_value' => $row['outcome']->hasValue(),
                'coverage_warning' => $row['outcome']->coverageWarning,
                'domains' => array_map(fn ($domain) => [
                    'domain_id' => $domain->domainId,
                    'value' => $domain->normalizedValue,
                    'warning' => $domain->coverageWarning,
                ], $row['outcome']->domains),
            ], $results),
        ]);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
