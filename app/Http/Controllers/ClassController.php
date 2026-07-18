<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClassRequest;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\ClassService;
use Illuminate\Http\RedirectResponse;
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
                'status_label' => $class->status->label(),
                'profile_name' => $class->profileVersion?->profile->name,
            ],
            // Names come from the encrypted identity — shown to the class's own
            // teacher, who is authorized. The pseudonym is what leaves the app.
            'students' => $class->enrollments()->with('student.identity')->orderBy('class_number')->get()
                ->map(fn (Enrollment $enrollment) => [
                    'ulid' => $enrollment->ulid,
                    'name' => $enrollment->student->identity->display_name,
                    'pseudonym' => $enrollment->student->pseudonym_code,
                    'class_number' => $enrollment->class_number,
                    'enrolled_on' => $enrollment->enrolled_on->toDateString(),
                    'is_late_entry' => $enrollment->is_late_entry,
                    'status_label' => $enrollment->status->label(),
                ]),
        ]);
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
