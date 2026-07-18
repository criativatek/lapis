<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssessmentProfileRequest;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\Scale;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\ProfileBuilder;
use App\Support\Assessment\ProfileActivationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AssessmentProfileController extends Controller
{
    public function __construct(protected ProfileBuilder $builder) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', AssessmentProfile::class);

        return Inertia::render('assessment-profiles/Index', [
            'profiles' => AssessmentProfile::with(['subject', 'academicYear', 'currentVersion'])
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (AssessmentProfile $profile) => [
                    'ulid' => $profile->ulid,
                    'name' => $profile->name,
                    'subject' => $profile->subject->name,
                    'academic_year' => $profile->academicYear->label,
                    'grade_level' => $profile->grade_level,
                    'is_active' => $profile->current_version_id !== null,
                    'status_label' => $profile->currentVersion?->status->label() ?? __('Rascunho'),
                    'has_draft' => $profile->draftVersion() !== null,
                ]),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', AssessmentProfile::class);

        return Inertia::render('assessment-profiles/Create', $this->formOptions());
    }

    public function store(AssessmentProfileRequest $request): RedirectResponse
    {
        Gate::authorize('create', AssessmentProfile::class);

        $this->builder->create(
            $request->safe()->only(['name', 'academic_year_id', 'subject_id', 'grade_level', 'description']),
            (int) $request->validated('scale_id'),
            $request->validated('domains'),
        );

        return to_route('assessment-profiles.index');
    }

    public function edit(AssessmentProfile $assessmentProfile): Response
    {
        Gate::authorize('view', $assessmentProfile);

        $version = $assessmentProfile->draftVersion() ?? $assessmentProfile->currentVersion;
        $version?->load('domains.domain');

        return Inertia::render('assessment-profiles/Edit', [
            ...$this->formOptions(),
            'profile' => [
                'ulid' => $assessmentProfile->ulid,
                'name' => $assessmentProfile->name,
                'academic_year_id' => $assessmentProfile->academic_year_id,
                'subject_id' => $assessmentProfile->subject_id,
                'grade_level' => $assessmentProfile->grade_level,
                'description' => $assessmentProfile->description,
                'scale_id' => $version?->scale_id,
                'editing_active' => $assessmentProfile->draftVersion() === null && $assessmentProfile->current_version_id !== null,
                'domains' => $version?->domains->map(fn ($pvd) => [
                    'name' => $pvd->domain->name,
                    'weight' => (float) $pvd->weight_percent,
                ]) ?? [],
            ],
        ]);
    }

    public function update(AssessmentProfileRequest $request, AssessmentProfile $assessmentProfile): RedirectResponse
    {
        Gate::authorize('update', $assessmentProfile);

        $this->builder->update(
            $assessmentProfile,
            $request->safe()->only(['name', 'academic_year_id', 'subject_id', 'grade_level', 'description']),
            (int) $request->validated('scale_id'),
            $request->validated('domains'),
        );

        return to_route('assessment-profiles.index');
    }

    public function activate(AssessmentProfile $assessmentProfile, ActivateProfileVersion $activate): RedirectResponse
    {
        Gate::authorize('update', $assessmentProfile);

        $draft = $assessmentProfile->draftVersion();

        if ($draft === null) {
            return back()->withErrors(['activation' => __('Não há nenhuma versão em rascunho para ativar.')]);
        }

        try {
            $activate->activate($draft, $this->requestUser());
        } catch (ProfileActivationException $exception) {
            return back()->withErrors(['activation' => $exception->getMessage()]);
        }

        return to_route('assessment-profiles.index');
    }

    public function destroy(AssessmentProfile $assessmentProfile): RedirectResponse
    {
        Gate::authorize('delete', $assessmentProfile);

        $assessmentProfile->delete();

        return to_route('assessment-profiles.index');
    }

    /**
     * @return array<string, mixed>
     */
    protected function formOptions(): array
    {
        return [
            'academicYears' => AcademicYear::orderByDesc('starts_on')->get(['ulid', 'id', 'label'])
                ->map(fn (AcademicYear $year) => ['id' => $year->id, 'label' => $year->label]),
            'subjects' => Subject::orderBy('name')->get(['id', 'name'])
                ->map(fn (Subject $subject) => ['id' => $subject->id, 'label' => $subject->name]),
            'scales' => Scale::orderByRaw('organization_id IS NOT NULL, name')->get(['id', 'name', 'organization_id'])
                ->map(fn (Scale $scale) => ['id' => $scale->id, 'label' => $scale->name, 'system' => $scale->organization_id === null]),
        ];
    }

    protected function requestUser(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
