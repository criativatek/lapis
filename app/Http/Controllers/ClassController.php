<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClassRequest;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\Classification;
use App\Models\ClassStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Instrument;
use App\Models\ProfileVersionStatus;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Rules\BelongsToCurrentOrganization;
use App\Services\ClassService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ClassController extends Controller
{
    public function __construct(protected ClassService $service) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', SchoolClass::class);

        // Only the teacher's own classes (§23). Tenant isolation plus class_teachers.
        $classes = SchoolClass::query()
            ->whereHas('teachers', fn ($query) => $query->whereKey($this->user()->getKey()))
            ->with(['subject', 'academicYear'])
            ->withCount('enrollments')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SchoolClass $class) => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'grade_level' => $class->grade_level,
                'status_label' => $class->status->label(),
                'students_count' => $class->enrollments_count,
            ]);

        return Inertia::render('classes/Index', ['classes' => $classes]);
    }

    public function create(): Response
    {
        Gate::authorize('create', SchoolClass::class);

        return Inertia::render('classes/Create', $this->formOptions());
    }

    public function store(ClassRequest $request): RedirectResponse
    {
        Gate::authorize('create', SchoolClass::class);

        $class = $this->service->create(
            [...$request->safe()->only(['label', 'academic_year_id', 'subject_id', 'grade_level', 'assessment_profile_version_id']), 'status' => 'preparation'],
            $this->user(),
        );

        return to_route('classes.show', $class->ulid);
    }

    public function show(SchoolClass $class): Response
    {
        Gate::authorize('view', $class);

        $class->load(['subject', 'academicYear', 'profileVersion.profile']);

        return Inertia::render('classes/Show', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'grade_level' => $class->grade_level,
                'status' => $class->status->value,
                'status_label' => $class->status->label(),
                'profile_name' => $class->profileVersion?->profile->name,
                'subject_id' => $class->subject_id,
            ],
            // Active profiles for this subject, so a class created without one can
            // be assigned later without going back to the profile screen.
            'availableProfiles' => AssessmentProfile::whereNotNull('current_version_id')
                ->where('subject_id', $class->subject_id)
                ->get()
                ->map(fn (AssessmentProfile $profile) => [
                    'version_id' => $profile->current_version_id,
                    'label' => $profile->name,
                ]),
            'instruments' => $class->instruments()->with('type')->get()
                ->map(fn (Instrument $instrument) => [
                    'ulid' => $instrument->ulid,
                    'title' => $instrument->title,
                    'type' => $instrument->type->name,
                    'applied_on' => $instrument->applied_on->toDateString(),
                    'status_label' => $instrument->status->label(),
                    'status' => $instrument->status->value,
                ]),
            // Names come from the encrypted identity — shown to the class's own
            // teacher, who is authorized. The pseudonym is what leaves the app.
            // THE CLASS AS IT STANDS. Somebody the roll says has transferred,
            // moved class, cancelled or been excluded is not part of the group
            // a teacher works with today — and is not deleted either: they are
            // listed below, under their own heading (§4, §13).
            'students' => $class->activeEnrollments()->with('student.identity')->orderBy('class_number')->get()
                ->map(fn (Enrollment $enrollment) => [
                    'ulid' => $enrollment->ulid,
                    'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                    // So the edit dialog opens on an empty field instead of
                    // offering "(sem identidade)" as if it were a real name.
                    'has_identity' => $enrollment->student->identity !== null,
                    'pseudonym' => $enrollment->student->pseudonym_code,
                    // The school's own identifier, when there is one. Optional
                    // everywhere except an export that needs it.
                    'process_number' => $enrollment->student->processNumber(),
                    'class_number' => $enrollment->class_number,
                    'enrolled_on' => $enrollment->enrolled_on->toDateString(),
                    'is_late_entry' => $enrollment->is_late_entry,
                    'status_label' => $enrollment->status->label(),
                    'photo_url' => $enrollment->student->photoUrl(),
                ]),

            // NOT DELETED, JUST NOT HERE ANY MORE. Kept visible so a teacher
            // who imported a roll and lost three names can see where they
            // went, and named by WHY they left rather than by a status code
            // (§13, §14). One query, ordered like the roll itself.
            'former_students' => $class->enrollments()
                ->whereNot('status', EnrollmentStatus::Active)
                ->with('student.identity')
                ->orderBy('class_number')
                ->get()
                ->map(fn (Enrollment $enrollment) => [
                    'ulid' => $enrollment->ulid,
                    'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                    'class_number' => $enrollment->class_number,
                    // The reason when the roll gave one, the status otherwise —
                    // «Mudou de turma», never «moved_class».
                    'state_label' => $enrollment->status_reason?->label() ?? $enrollment->status->label(),
                ]),
        ]);
    }

    public function edit(SchoolClass $class): Response
    {
        Gate::authorize('update', $class);

        $class->load(['subject', 'academicYear']);

        return Inertia::render('classes/Edit', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                // Shown for context, not editable here (§10.2 — year, subject
                // and grade feed reporting/profile-matching and are not safe
                // to change once a class has enrollments or instruments).
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'grade_level' => $class->grade_level,
            ],
        ]);
    }

    public function update(ClassRequest $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        // Only the label is editable from this form — academic_year_id,
        // subject_id and grade_level are deliberately never read from the
        // request here, regardless of what ClassRequest validated, so a
        // crafted payload cannot move a class between years/subjects.
        $class->update(['label' => $request->validated('label')]);

        return to_route('classes.show', $class->ulid);
    }

    /**
     * Assign (or change) the profile version a class is assessed by.
     *
     * ponytail: a plain reassignment while no results exist. Once results hang
     * off the class, §10.2 requires this to become an explicit migration with an
     * impact preview, recorded in class_profile_migrations — hence the guard.
     */
    public function updateProfile(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $data = $request->validate([
            'assessment_profile_version_id' => ['required', new BelongsToCurrentOrganization(AssessmentProfileVersion::class)],
        ]);

        // whereKey()->firstOrFail(), not findOrFail(): findOrFail also accepts an
        // array of ids, so its return type is a model-or-collection union.
        $version = AssessmentProfileVersion::whereKey($data['assessment_profile_version_id'])->firstOrFail();

        if ($version->status !== ProfileVersionStatus::Active) {
            return back()->withErrors(['assessment_profile_version_id' => __('Só um perfil ativo pode ser associado a uma turma.')]);
        }

        if ($version->id === $class->assessment_profile_version_id) {
            return back();
        }

        // A class that already has classifications cannot swap versions silently
        // (§10.2, A4): route through the auditable migration, which previews the
        // impact and records a reason. Only a class with no decisions swaps freely.
        $hasDecisions = Classification::query()
            ->whereIn('enrollment_id', $class->enrollments()->select('id'))
            ->exists();

        if ($hasDecisions) {
            return redirect()->route('classes.profile-migration.create', [
                'class' => $class->ulid,
                'to' => $version->ulid,
            ]);
        }

        $class->update(['assessment_profile_version_id' => $version->id]);

        return back();
    }

    public function activate(SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $class->update(['status' => ClassStatus::Active]);

        return back();
    }

    public function destroy(SchoolClass $class): RedirectResponse
    {
        Gate::authorize('delete', $class);

        $class->delete();

        return to_route('classes.index');
    }

    /**
     * @return array<string, mixed>
     */
    protected function formOptions(): array
    {
        return [
            'academicYears' => AcademicYear::orderByDesc('starts_on')->get(['id', 'label'])
                ->map(fn (AcademicYear $year) => ['id' => $year->id, 'label' => $year->label]),
            'subjects' => Subject::orderBy('name')->get(['id', 'name'])
                ->map(fn (Subject $subject) => ['id' => $subject->id, 'label' => $subject->name]),
            // Only activated profiles can be assigned — a class is assessed by an
            // active (frozen) version, never a draft.
            'profiles' => AssessmentProfile::whereNotNull('current_version_id')
                ->with('subject')
                ->get()
                ->map(fn (AssessmentProfile $profile) => [
                    'version_id' => $profile->current_version_id,
                    'label' => $profile->name,
                    'subject_id' => $profile->subject_id,
                ]),
        ];
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
