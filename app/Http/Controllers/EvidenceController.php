<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\ActivityEvaluation;
use App\Models\DisciplinarySeverity;
use App\Models\Domain;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\ParticipationLevel;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The teacher's logbook (§14): qualitative entries beside the grades. It never
 * touches the calculation (§14.3) — an entry can only be flagged to appear in a
 * report, never to change a result.
 */
class EvidenceController extends Controller
{
    public function index(): Response
    {
        $classes = SchoolClass::query()
            ->whereHas('teachers', fn ($query) => $query->whereKey($this->user()->getKey()))
            ->with(['subject', 'academicYear'])
            ->withCount('evidenceRecords')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SchoolClass $class) => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'records_count' => $class->evidence_records_count,
            ]);

        return Inertia::render('records/Index', ['classes' => $classes]);
    }

    public function show(Request $request, SchoolClass $class): Response
    {
        Gate::authorize('view', $class);

        $enrollmentFilter = $request->query('enrollment_id') !== null ? (int) $request->query('enrollment_id') : null;
        $kindFilter = $request->query('kind');
        $periodFilter = $request->query('period_id') !== null ? (int) $request->query('period_id') : null;

        $period = $periodFilter !== null
            ? AcademicPeriod::where('academic_year_id', $class->academic_year_id)->find($periodFilter)
            : null;

        $records = EvidenceRecord::query()
            ->forClass($class->id)
            ->forEnrollmentOrWholeClass($enrollmentFilter)
            ->when($kindFilter !== null, fn ($query) => $query->where('kind', $kindFilter))
            ->when($period !== null, fn ($query) => $query->inPeriod($period))
            ->with(['enrollment.student.identity', 'domain'])
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get()
            ->map(fn (EvidenceRecord $record) => [
                'ulid' => $record->ulid,
                'kind' => $record->kind->value,
                'kind_label' => $record->kind->label(),
                'enrollment_id' => $record->enrollment_id,
                'domain_id' => $record->domain_id,
                'disciplinary_severity' => $record->disciplinary_severity?->value,
                'disciplinary_severity_label' => $record->disciplinary_severity?->label(),
                'homework_status' => $record->homework_status?->value,
                'participation_level' => $record->participation_level?->value,
                'activity_evaluation' => $record->activity_evaluation?->value,
                'activity_include_in_report' => $record->activity_include_in_report,
                'detail_label' => $this->detailLabel($record),
                'description' => $record->description,
                'student' => $record->enrollment === null ? null : (optional($record->enrollment->student->identity)->display_name ?? '(sem identidade)'),
                'domain' => $record->domain?->name,
                'occurred_at' => $record->occurred_at->toIso8601String(),
            ]);

        return Inertia::render('records/Show', [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label, 'subject' => $class->subject->name],
            'enrollments' => $class->enrollments()->with('student.identity')->orderBy('class_number')->get()
                ->map(fn ($enrollment) => ['id' => $enrollment->id, 'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)']),
            'domains' => Domain::where('subject_id', $class->subject_id)->orderBy('name')->get(['id', 'name']),
            'kinds' => collect(EvidenceKind::cases())->map(fn (EvidenceKind $kind) => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'group' => $kind->group()->value,
                'group_label' => $kind->group()->label(),
            ]),
            'severities' => collect(DisciplinarySeverity::cases())->map(fn (DisciplinarySeverity $severity) => ['value' => $severity->value, 'label' => $severity->label()]),
            'periods' => AcademicPeriod::where('academic_year_id', $class->academic_year_id)->orderBy('sequence')->get()
                ->map(fn (AcademicPeriod $classPeriod) => ['id' => $classPeriod->id, 'label' => $classPeriod->label]),
            'filters' => [
                'enrollment_id' => $enrollmentFilter,
                'kind' => $kindFilter,
                'period_id' => $periodFilter,
            ],
            'records' => $records,
        ]);
    }

    /**
     * A short, type-specific suffix for the compact listing (§10) — e.g.
     * "Não realizado", "Positiva", "Avaliação positiva — Incluir no
     * relatório". Computed once here so the frontend never re-derives it.
     */
    protected function detailLabel(EvidenceRecord $record): ?string
    {
        return match ($record->kind) {
            EvidenceKind::Homework => $record->homework_status?->label(),
            EvidenceKind::Participation => $record->participation_level?->label(),
            EvidenceKind::Progress, EvidenceKind::Difficulty => $record->domain?->name,
            EvidenceKind::Activity => $record->activity_evaluation === null ? null : ($record->activity_evaluation->label().($record->activity_include_in_report ? ' — '.__('Incluir no relatório') : '')),
            default => null,
        };
    }

    /**
     * A record can target the whole class (empty `enrollment_ids`) or one or
     * more specific students at once — the multi-student grid is a pure
     * authoring shortcut, never a shared record: each selected student gets
     * their own independent row, identical except for `enrollment_id`, each
     * editable and deletable on its own from then on.
     */
    public function store(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $validated = $request->validate(array_merge($this->rulesFor($request->input('kind')), [
            'enrollment_ids' => ['present', 'array'],
            'enrollment_ids.*' => ['integer'],
        ]));

        $this->guardCrossReferences($class, $validated);

        $targets = $validated['enrollment_ids'] === [] ? [null] : $validated['enrollment_ids'];

        DB::transaction(function () use ($class, $validated, $targets): void {
            foreach ($targets as $enrollmentId) {
                EvidenceRecord::create([
                    'class_id' => $class->id,
                    'enrollment_id' => $enrollmentId,
                    'domain_id' => $validated['domain_id'] ?? null,
                    'occurred_at' => $validated['occurred_at'],
                    'kind' => $validated['kind'],
                    'disciplinary_severity' => $validated['disciplinary_severity'] ?? null,
                    'homework_status' => $validated['homework_status'] ?? null,
                    'participation_level' => $validated['participation_level'] ?? null,
                    'activity_evaluation' => $validated['activity_evaluation'] ?? null,
                    'activity_include_in_report' => $validated['activity_include_in_report'] ?? null,
                    // The DB column is NOT NULL — an omitted, optional description (§4.4)
                    // is stored as empty, never as a null that would fail the insert.
                    'description' => $validated['description'] ?? '',
                    'created_by' => $this->user()->getKey(),
                ]);
            }
        });

        $message = count($targets) === 1
            ? __('Registo adicionado.')
            : __(':count registos adicionados.', ['count' => count($targets)]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function update(Request $request, EvidenceRecord $record): RedirectResponse
    {
        $class = $record->schoolClass;
        Gate::authorize('update', $class);

        $validated = $request->validate($this->rulesFor($request->input('kind')));

        $this->guardCrossReferences($class, $validated);

        $record->update([
            'enrollment_id' => $validated['enrollment_id'] ?? null,
            'domain_id' => $validated['domain_id'] ?? null,
            'occurred_at' => $validated['occurred_at'],
            'kind' => $validated['kind'],
            'disciplinary_severity' => $validated['disciplinary_severity'] ?? null,
            'homework_status' => $validated['homework_status'] ?? null,
            'participation_level' => $validated['participation_level'] ?? null,
            'activity_evaluation' => $validated['activity_evaluation'] ?? null,
            'activity_include_in_report' => $validated['activity_include_in_report'] ?? null,
            // The DB column is NOT NULL — an omitted, optional description (§4.4)
            // is stored as empty, never as a null that would fail the insert.
            'description' => $validated['description'] ?? '',
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Registo atualizado.')]);

        return back();
    }

    public function destroy(EvidenceRecord $record): RedirectResponse
    {
        Gate::authorize('update', $record->schoolClass);

        $record->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Registo removido.')]);

        return back();
    }

    /**
     * Shared between store() and update() — every field a kind needs is
     * required for that kind and prohibited for every other one, so a
     * record can never carry a stray value from a kind it isn't.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rulesFor(?string $kind): array
    {
        // homework/participation/incident already carry other descriptive
        // content (situação/nível/gravidade) — description stays optional
        // only for those three; every other kind needs it to say what
        // happened at all (§4.4).
        $descriptionOptionalKinds = [
            EvidenceKind::Homework->value,
            EvidenceKind::Participation->value,
            EvidenceKind::Incident->value,
        ];

        return [
            'kind' => ['required', Rule::enum(EvidenceKind::class)],
            'description' => [
                Rule::requiredIf(! in_array($kind, $descriptionOptionalKinds, true)),
                'nullable', 'string', 'max:1000',
            ],
            'occurred_at' => ['required', 'date'],
            'enrollment_id' => ['nullable', 'integer'],
            'domain_id' => ['nullable', 'integer'],
            // Required exactly for a disciplinary occurrence, never set for
            // any other kind — G1 ("Comportamento meritório") is its own
            // EvidenceKind, not a severity of this field. 'nullable' matters
            // here: the real form always sends every kind-specific field,
            // explicit `null` for whichever ones don't belong to the chosen
            // kind — without it, Rule::enum() rejects that null outright,
            // instead of prohibitedIf() being the only thing that runs.
            'disciplinary_severity' => [
                Rule::requiredIf($kind === EvidenceKind::Incident->value),
                Rule::prohibitedIf($kind !== EvidenceKind::Incident->value),
                'nullable', Rule::enum(DisciplinarySeverity::class),
            ],
            'homework_status' => [
                Rule::requiredIf($kind === EvidenceKind::Homework->value),
                Rule::prohibitedIf($kind !== EvidenceKind::Homework->value),
                'nullable', Rule::enum(HomeworkStatus::class),
            ],
            'participation_level' => [
                Rule::requiredIf($kind === EvidenceKind::Participation->value),
                Rule::prohibitedIf($kind !== EvidenceKind::Participation->value),
                'nullable', Rule::enum(ParticipationLevel::class),
            ],
            'activity_evaluation' => [
                Rule::requiredIf($kind === EvidenceKind::Activity->value),
                Rule::prohibitedIf($kind !== EvidenceKind::Activity->value),
                'nullable',
                Rule::enum(ActivityEvaluation::class),
            ],
            'activity_include_in_report' => [
                Rule::requiredIf($kind === EvidenceKind::Activity->value),
                Rule::prohibitedIf($kind !== EvidenceKind::Activity->value),
                'nullable', 'boolean',
            ],
        ];
    }

    /**
     * A targeted entry must point at a student OF THIS class, and a domain OF
     * THIS class's subject — not just anything in the organization. Checks
     * both the single `enrollment_id` (update()'s single-target edit) and the
     * `enrollment_ids` list (store()'s multi-student grid) in one pass.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function guardCrossReferences(SchoolClass $class, array $validated): void
    {
        $enrollmentIds = array_values(array_unique(array_filter(array_merge(
            [$validated['enrollment_id'] ?? null],
            $validated['enrollment_ids'] ?? [],
        ), fn ($id) => $id !== null)));

        if ($enrollmentIds !== [] && $class->enrollments()->whereIn('id', $enrollmentIds)->count() !== count($enrollmentIds)) {
            throw ValidationException::withMessages(['enrollment_ids' => __('Aluno inválido para esta turma.')]);
        }
        if (($validated['domain_id'] ?? null) !== null
            && ! Domain::where('subject_id', $class->subject_id)->whereKey($validated['domain_id'])->exists()) {
            throw ValidationException::withMessages(['domain_id' => __('Domínio inválido para esta disciplina.')]);
        }
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
