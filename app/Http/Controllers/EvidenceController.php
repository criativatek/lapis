<?php

namespace App\Http\Controllers;

use App\Models\DisciplinarySeverity;
use App\Models\Domain;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function show(SchoolClass $class): Response
    {
        Gate::authorize('view', $class);

        $records = EvidenceRecord::query()
            ->where('class_id', $class->id)
            ->with(['enrollment.student.identity', 'domain'])
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get()
            ->map(fn (EvidenceRecord $record) => [
                'ulid' => $record->ulid,
                'kind' => $record->kind->value,
                'kind_label' => $record->kind->label(),
                'disciplinary_severity_label' => $record->disciplinary_severity?->label(),
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
            'kinds' => collect(EvidenceKind::cases())->map(fn (EvidenceKind $kind) => ['value' => $kind->value, 'label' => $kind->label()]),
            'severities' => collect(DisciplinarySeverity::cases())->map(fn (DisciplinarySeverity $severity) => ['value' => $severity->value, 'label' => $severity->label()]),
            'records' => $records,
        ]);
    }

    public function store(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $validated = $request->validate([
            'kind' => ['required', Rule::enum(EvidenceKind::class)],
            // Required exactly for a disciplinary occurrence, never set for
            // any other kind — G1 ("Comportamento meritório") is its own
            // EvidenceKind, not a severity of this field.
            'disciplinary_severity' => [
                Rule::requiredIf($request->input('kind') === EvidenceKind::Incident->value),
                Rule::prohibitedIf($request->input('kind') !== EvidenceKind::Incident->value),
                Rule::enum(DisciplinarySeverity::class),
            ],
            'description' => ['required', 'string', 'max:1000'],
            'occurred_at' => ['required', 'date'],
            'enrollment_id' => ['nullable', 'integer'],
            'domain_id' => ['nullable', 'integer'],
        ]);

        // A targeted entry must point at a student OF THIS class, and a domain OF
        // THIS class's subject — not just anything in the organization.
        if (($validated['enrollment_id'] ?? null) !== null
            && ! $class->enrollments()->whereKey($validated['enrollment_id'])->exists()) {
            throw ValidationException::withMessages(['enrollment_id' => __('Aluno inválido para esta turma.')]);
        }
        if (($validated['domain_id'] ?? null) !== null
            && ! Domain::where('subject_id', $class->subject_id)->whereKey($validated['domain_id'])->exists()) {
            throw ValidationException::withMessages(['domain_id' => __('Domínio inválido para esta disciplina.')]);
        }

        EvidenceRecord::create([
            'class_id' => $class->id,
            'enrollment_id' => $validated['enrollment_id'] ?? null,
            'domain_id' => $validated['domain_id'] ?? null,
            'occurred_at' => $validated['occurred_at'],
            'kind' => $validated['kind'],
            'disciplinary_severity' => $validated['disciplinary_severity'] ?? null,
            'description' => $validated['description'],
            'created_by' => $this->user()->getKey(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Registo adicionado.')]);

        return back();
    }

    public function destroy(EvidenceRecord $record): RedirectResponse
    {
        Gate::authorize('update', $record->schoolClass);

        $record->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Registo removido.')]);

        return back();
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
