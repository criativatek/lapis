<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcademicYearRequest;
use App\Models\AcademicPeriodKind;
use App\Models\AcademicYear;
use App\Models\AcademicYearStatus;
use App\Services\AcademicYearService;
use App\Support\AcademicYears\AcademicYearValidationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AcademicYearController extends Controller
{
    public function __construct(protected AcademicYearService $service) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', AcademicYear::class);

        return Inertia::render('academic-years/Index', [
            'academicYears' => AcademicYear::withCount('periods')
                ->orderByDesc('starts_on')
                ->get()
                ->map(fn (AcademicYear $year) => [
                    'ulid' => $year->ulid,
                    'label' => $year->label,
                    'starts_on' => $year->starts_on->toDateString(),
                    'ends_on' => $year->ends_on->toDateString(),
                    'status' => $year->status->value,
                    'status_label' => $year->status->label(),
                    'periods_count' => $year->periods_count,
                    'editable' => $year->isEditable(),
                ]),
            'statuses' => $this->statusOptions(),
            'periodKinds' => $this->periodKindOptions(),
            // A member reads and picks a year to work in; only the
            // organization's owner shapes the calendar itself (Fatia 1).
            'canManage' => Gate::allows('create', AcademicYear::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', AcademicYear::class);

        return Inertia::render('academic-years/Create', [
            'statuses' => $this->statusOptions(),
            'periodKinds' => $this->periodKindOptions(),
        ]);
    }

    public function store(AcademicYearRequest $request): RedirectResponse
    {
        Gate::authorize('create', AcademicYear::class);

        $this->service->create(
            $request->safe()->except('periods'),
            $request->validated('periods'),
        );

        return to_route('academic-years.index');
    }

    public function edit(AcademicYear $academicYear): Response
    {
        Gate::authorize('view', $academicYear);

        return Inertia::render('academic-years/Edit', [
            'academicYear' => [
                'ulid' => $academicYear->ulid,
                'label' => $academicYear->label,
                'starts_on' => $academicYear->starts_on->toDateString(),
                'ends_on' => $academicYear->ends_on->toDateString(),
                'status' => $academicYear->status->value,
                'country_code' => $academicYear->country_code,
                'region_code' => $academicYear->region_code,
                'editable' => $academicYear->isEditable(),
                'periods' => $academicYear->periods->map(fn ($period) => [
                    'ulid' => $period->ulid,
                    'label' => $period->label,
                    'kind' => $period->kind->value,
                    'sequence' => $period->sequence,
                    'starts_on' => $period->starts_on->toDateString(),
                    'ends_on' => $period->ends_on->toDateString(),
                ]),
            ],
            'statuses' => $this->statusOptions(),
            'periodKinds' => $this->periodKindOptions(),
            // Ownership alone — kept separate from `editable` (the year's own
            // pedagogical state) so the page can tell the two refusal reasons
            // apart instead of collapsing them into one silent blank form.
            'canManage' => Gate::allows('create', AcademicYear::class),
        ]);
    }

    public function update(AcademicYearRequest $request, AcademicYear $academicYear): RedirectResponse
    {
        Gate::authorize('update', $academicYear);

        try {
            $this->service->update(
                $academicYear,
                $request->safe()->except('periods'),
                $request->validated('periods'),
            );
        } catch (AcademicYearValidationException $exception) {
            return back()->withErrors(['periods' => $exception->getMessage()])->withInput();
        }

        return to_route('academic-years.index');
    }

    public function destroy(AcademicYear $academicYear): RedirectResponse
    {
        Gate::authorize('delete', $academicYear);

        $academicYear->periods()->delete();
        $academicYear->delete();

        return to_route('academic-years.index');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function statusOptions(): array
    {
        return array_map(
            fn (AcademicYearStatus $status) => ['value' => $status->value, 'label' => $status->label()],
            AcademicYearStatus::cases(),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function periodKindOptions(): array
    {
        return array_map(
            fn (AcademicPeriodKind $kind) => ['value' => $kind->value, 'label' => $kind->label()],
            AcademicPeriodKind::cases(),
        );
    }
}
