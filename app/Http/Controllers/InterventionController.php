<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Intervention;
use App\Models\InterventionEffectiveness;
use App\Models\InterventionReview;
use App\Models\InterventionStatus;
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
 * Support interventions (§14): measures for a student, with a lifecycle and
 * periodic effectiveness reviews. Never part of the calculation (§14.3).
 */
class InterventionController extends Controller
{
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
            ]);

        return Inertia::render('interventions/Index', ['classes' => $classes]);
    }

    public function show(SchoolClass $class): Response
    {
        Gate::authorize('view', $class);

        $enrollmentIds = $class->enrollments()->pluck('id');

        $interventions = Intervention::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->with(['enrollment.student.identity', 'domain', 'reviews'])
            ->orderByDesc('started_on')
            ->get()
            ->map(fn (Intervention $intervention) => [
                'ulid' => $intervention->ulid,
                'title' => $intervention->title,
                'description' => $intervention->description,
                'student' => optional($intervention->enrollment->student->identity)->display_name ?? '(sem identidade)',
                'domain' => $intervention->domain?->name,
                'status' => $intervention->status->value,
                'status_label' => $intervention->status->label(),
                'is_closed' => $intervention->status->isClosed(),
                'started_on' => $intervention->started_on->toDateString(),
                'expected_end_on' => $intervention->expected_end_on?->toDateString(),
                'concluded_on' => $intervention->concluded_on?->toDateString(),
                'include_in_report' => $intervention->include_in_report,
                'reviews' => $intervention->reviews->map(fn (InterventionReview $review) => [
                    'ulid' => $review->ulid,
                    'reviewed_on' => $review->reviewed_on->toDateString(),
                    'effectiveness' => $review->effectiveness?->value,
                    'effectiveness_label' => $review->effectiveness?->label(),
                    'notes' => $review->notes,
                ])->all(),
            ]);

        return Inertia::render('interventions/Show', [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label, 'subject' => $class->subject->name],
            'enrollments' => $class->enrollments()->with('student.identity')->orderBy('class_number')->get()
                ->map(fn ($enrollment) => ['id' => $enrollment->id, 'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)']),
            'domains' => Domain::where('subject_id', $class->subject_id)->orderBy('name')->get(['id', 'name']),
            'effectivenessOptions' => collect(InterventionEffectiveness::cases())
                ->map(fn (InterventionEffectiveness $option) => ['value' => $option->value, 'label' => $option->label()]),
            'interventions' => $interventions,
        ]);
    }

    public function store(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $validated = $request->validate([
            'enrollment_id' => ['required', 'integer'],
            'domain_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'started_on' => ['required', 'date'],
            'expected_end_on' => ['nullable', 'date', 'after_or_equal:started_on'],
            'include_in_report' => ['boolean'],
        ]);

        if (! $class->enrollments()->whereKey($validated['enrollment_id'])->exists()) {
            throw ValidationException::withMessages(['enrollment_id' => __('Aluno inválido para esta turma.')]);
        }
        if (($validated['domain_id'] ?? null) !== null
            && ! Domain::where('subject_id', $class->subject_id)->whereKey($validated['domain_id'])->exists()) {
            throw ValidationException::withMessages(['domain_id' => __('Domínio inválido para esta disciplina.')]);
        }

        Intervention::create([
            'enrollment_id' => $validated['enrollment_id'],
            'domain_id' => $validated['domain_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => InterventionStatus::New,
            'started_on' => $validated['started_on'],
            'expected_end_on' => $validated['expected_end_on'] ?? null,
            'include_in_report' => $validated['include_in_report'] ?? false,
            'created_by' => $this->user()->getKey(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Intervenção criada.')]);

        return back();
    }

    public function update(Request $request, Intervention $intervention): RedirectResponse
    {
        Gate::authorize('update', $intervention->enrollment->schoolClass);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(InterventionStatus::class)],
        ]);

        $status = InterventionStatus::from($validated['status']);

        $intervention->fill([
            'status' => $status,
            // Stamp the conclusion date the moment it is concluded; clear it if the
            // intervention is reopened or cancelled.
            'concluded_on' => $status === InterventionStatus::Concluded ? now()->toDateString() : null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Estado atualizado.')]);

        return back();
    }

    public function addReview(Request $request, Intervention $intervention): RedirectResponse
    {
        Gate::authorize('update', $intervention->enrollment->schoolClass);

        $validated = $request->validate([
            'reviewed_on' => ['required', 'date'],
            'effectiveness' => ['nullable', Rule::enum(InterventionEffectiveness::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $intervention->reviews()->create([
            'reviewed_on' => $validated['reviewed_on'],
            'effectiveness' => $validated['effectiveness'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'reviewed_by' => $this->user()->getKey(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Apreciação adicionada.')]);

        return back();
    }

    public function destroy(Intervention $intervention): RedirectResponse
    {
        Gate::authorize('update', $intervention->enrollment->schoolClass);

        $intervention->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Intervenção removida.')]);

        return back();
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
