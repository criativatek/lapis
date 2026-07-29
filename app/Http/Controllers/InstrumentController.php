<?php

namespace App\Http\Controllers;

use App\Http\Requests\InstrumentRequest;
use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\InstrumentType;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\RecordScores;
use App\Support\Assessment\InstrumentValidationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InstrumentController extends Controller
{
    public function __construct(protected InstrumentBuilder $builder) {}

    public function index(): Response
    {
        $instruments = Instrument::query()
            ->whereHas('schoolClass.teachers', fn ($query) => $query->whereKey($this->user()->getKey()))
            ->with(['schoolClass', 'type', 'academicPeriod'])
            ->withCount('items')
            ->orderByDesc('applied_on')
            ->get()
            ->map(fn (Instrument $instrument) => [
                'ulid' => $instrument->ulid,
                'title' => $instrument->title,
                'class_label' => $instrument->schoolClass->label,
                'type' => $instrument->type->name,
                'period' => $instrument->academicPeriod->label,
                'applied_on' => $instrument->applied_on->toDateString(),
                'status_label' => $instrument->status->label(),
                'counts' => $instrument->counts_toward_classification,
                'items_count' => $instrument->items_count,
            ]);

        return Inertia::render('instruments/Index', ['instruments' => $instruments]);
    }

    public function create(SchoolClass $class): Response
    {
        Gate::authorize('update', $class);

        return Inertia::render('instruments/Create', [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label],
            ...$this->formOptions($class),
        ]);
    }

    public function store(InstrumentRequest $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        try {
            $instrument = $this->builder->create(
                $class,
                $request->safe()->except('items'),
                $request->validated('items'),
            );
        } catch (InstrumentValidationException $exception) {
            return back()->withErrors(['items' => $exception->getMessage()])->withInput();
        }

        return to_route('instruments.show', $instrument->ulid);
    }

    /**
     * The grading grid: students in rows, items in columns (§12.4).
     */
    public function show(Instrument $instrument): Response
    {
        Gate::authorize('view', $instrument->schoolClass);

        $instrument->load(['items.domainAllocations.domain', 'schoolClass', 'academicPeriod', 'type']);

        $enrollments = $instrument->schoolClass->enrollments()
            ->with('student.identity')
            ->orderBy('class_number')
            ->get();

        // Only the cells that exist — an absent row means "por avaliar" and the
        // grid renders it empty rather than the backend inventing blanks.
        $scores = StudentItemScore::where('instrument_id', $instrument->id)
            ->get()
            ->map(fn (StudentItemScore $score) => [
                'enrollment_id' => $score->enrollment_id,
                'instrument_item_id' => $score->instrument_item_id,
                'result_state' => $score->result_state->value,
                'points_earned' => $score->points_earned === null ? null : (float) $score->points_earned,
                'state_reason' => $score->state_reason,
            ]);

        $scaleBands = $instrument->schoolClass->profileVersion?->scale
            ?->levels()
            ->whereNotNull('band_min_normalized')
            ->whereNotNull('band_max_normalized')
            ->orderBy('sequence')
            ->get()
            ->map(fn ($level) => [
                'label' => $level->label,
                'band_min' => (string) $level->band_min_normalized,
                'band_max' => (string) $level->band_max_normalized,
            ])
            ->all() ?? [];

        return Inertia::render('instruments/Grid', [
            'instrument' => [
                'ulid' => $instrument->ulid,
                'title' => $instrument->title,
                'applied_on' => $instrument->applied_on->toDateString(),
                'status_label' => $instrument->status->label(),
                'total_points' => $instrument->total_points === null ? null : (float) $instrument->total_points,
                'class_label' => $instrument->schoolClass->label,
                'class_ulid' => $instrument->schoolClass->ulid,
                'period' => $instrument->academicPeriod->label,
            ],
            'items' => $instrument->items->map(fn (InstrumentItem $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'label' => $item->label,
                'points_possible' => (float) $item->points_possible,
                'is_bonus' => $item->is_bonus,
                'domains' => $item->domainAllocations->map(fn ($allocation) => [
                    'name' => $allocation->domain->name,
                    'percent' => (float) $allocation->allocation_percent,
                ]),
            ]),
            'students' => $enrollments->map(fn (Enrollment $enrollment) => [
                'enrollment_id' => $enrollment->id,
                'name' => $enrollment->student->identity->display_name,
                'class_number' => $enrollment->class_number,
                // The engine derives applicability from these dates (§11.4); the
                // grid shows it so the teacher sees why a cell is not applicable.
                'enrolled_on' => $enrollment->enrolled_on->toDateString(),
                'is_late_entry' => $enrollment->is_late_entry,
                'joined_after_instrument' => $enrollment->enrolled_on->greaterThan($instrument->applied_on),
            ]),
            'scores' => $scores,
            'states' => array_map(
                fn (ResultState $state) => ['value' => $state->value, 'label' => $state->label(), 'carries_value' => $state->carriesValue()],
                ResultState::cases(),
            ),
            'scaleBands' => $scaleBands,
        ]);
    }

    /**
     * Saves the cells the teacher changed. Only touched cells are sent.
     */
    public function saveScores(Request $request, Instrument $instrument, RecordScores $recordScores): RedirectResponse
    {
        Gate::authorize('update', $instrument->schoolClass);

        $data = $request->validate([
            'cells' => ['required', 'array'],
            'cells.*.enrollment_id' => ['required', 'integer'],
            'cells.*.instrument_item_id' => ['required', 'integer'],
            'cells.*.result_state' => ['required', 'string'],
            'cells.*.points_earned' => ['nullable', 'numeric'],
            'cells.*.state_reason' => ['nullable', 'string', 'max:255'],
        ]);

        // Guard the ids against the instrument itself, so a tampered payload
        // cannot write a score onto another class's student or item.
        $validItemIds = $instrument->items()->pluck('id')->all();
        $validEnrollmentIds = $instrument->schoolClass->enrollments()->pluck('id')->all();

        foreach ($data['cells'] as $cell) {
            abort_unless(in_array($cell['instrument_item_id'], $validItemIds, true), 422);
            abort_unless(in_array($cell['enrollment_id'], $validEnrollmentIds, true), 422);
        }

        $recordScores->save($instrument, $data['cells'], $this->user());

        return back();
    }

    public function destroy(Instrument $instrument): RedirectResponse
    {
        Gate::authorize('update', $instrument->schoolClass);

        $instrument->delete();

        return to_route('instruments.index');
    }

    /**
     * @return array<string, mixed>
     */
    protected function formOptions(SchoolClass $class): array
    {
        return [
            'periods' => AcademicPeriod::where('academic_year_id', $class->academic_year_id)
                ->orderBy('sequence')->get(['id', 'label'])
                ->map(fn (AcademicPeriod $period) => ['id' => $period->id, 'label' => $period->label]),
            'types' => InstrumentType::where('is_active', true)->orderBy('name')->get(['id', 'name', 'default_purpose'])
                ->map(fn (InstrumentType $type) => ['id' => $type->id, 'label' => $type->name, 'default_purpose' => $type->default_purpose]),
            // The domains this class's active profile version assesses.
            'domains' => $this->builder->domainsFor($class)
                ->map(fn ($domain) => ['id' => $domain->id, 'label' => $domain->name])
                ->values(),
        ];
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
