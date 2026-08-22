<?php

namespace App\Http\Controllers;

use App\Actions\ConfigSharing\BuildConfigurationImportPlan;
use App\Actions\ConfigSharing\BuildYearReusePackage;
use App\Actions\ConfigSharing\WriteConfigurationImport;
use App\Http\Requests\AssessmentProfileRequest;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\Scale;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Audit\AuditLog;
use App\Support\Assessment\ProfileActivationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;

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
            // A member reads and uses a profile to teach; only the
            // organization's owner defines how grades are calculated (Fatia 1).
            'canManage' => Gate::allows('create', AssessmentProfile::class),
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
            // A member still opens this page to see what they grade by —
            // `view` above stays true for that. Only the owner may change it.
            'canManage' => Gate::allows('update', $assessmentProfile),
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

    public function reuseForm(AssessmentProfile $assessmentProfile): Response
    {
        Gate::authorize('update', $assessmentProfile);

        return Inertia::render('assessment-profiles/Reuse', [
            'profile' => $this->reuseProfile($assessmentProfile),
            'academicYears' => AcademicYear::query()
                ->where('id', '!=', $assessmentProfile->academic_year_id)
                ->orderByDesc('starts_on')
                ->get(['ulid', 'label']),
        ]);
    }

    public function reusePreview(
        Request $request,
        AssessmentProfile $assessmentProfile,
        BuildYearReusePackage $buildPackage,
        BuildConfigurationImportPlan $plans,
    ): Response {
        Gate::authorize('update', $assessmentProfile);

        $validated = $request->validate(['target_academic_year' => ['required', 'string']]);
        $target = AcademicYear::query()->where('ulid', $validated['target_academic_year'])->firstOrFail();
        $package = $buildPackage->handle($assessmentProfile, $target);

        return Inertia::render('assessment-profiles/ReusePreview', [
            'plan' => $plans->handle($package),
            'sourceProfile' => $this->reuseProfile($assessmentProfile),
            'targetYear' => ['ulid' => $target->ulid, 'label' => $target->label],
            'package' => $package,
        ]);
    }

    public function reuseConfirm(
        Request $request,
        AssessmentProfile $assessmentProfile,
        BuildYearReusePackage $buildPackage,
        WriteConfigurationImport $write,
        AuditLog $audit,
    ): RedirectResponse {
        Gate::authorize('update', $assessmentProfile);

        $validated = $request->validate(['package' => ['required', 'string']]);
        try {
            $package = json_decode($validated['package'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['package' => __('O pacote de reutilização é inválido.')]);
        }
        // Validator::validate() returns only the keys covered by the rules below,
        // not the whole array — checked here purely for the shape assertion (it
        // throws on a malformed package), while $package itself, used for the
        // canonical-equality check right after, stays the full decoded array.
        validator(['package' => $package], [
            'package' => ['required', 'array'],
            'package.payload' => ['required', 'array'],
            'package.payload.assessment_profiles' => ['required', 'array', 'size:1'],
            'package.payload.assessment_profiles.0' => ['required', 'array'],
            'package.payload.assessment_profiles.0.academic_year_label' => ['required', 'string'],
        ])->validate();
        /** @var array<string, mixed> $package */
        /** @var string $targetLabel */
        $targetLabel = $package['payload']['assessment_profiles'][0]['academic_year_label'];
        $target = AcademicYear::query()->where('label', $targetLabel)->firstOrFail();
        $canonicalPackage = $buildPackage->handle($assessmentProfile, $target);

        if (
            ($package['kind'] ?? null) !== $canonicalPackage['kind']
            || ($package['schema_version'] ?? null) !== $canonicalPackage['schema_version']
            || ($package['components'] ?? null) !== $canonicalPackage['components']
            || ($package['payload'] ?? null) !== $canonicalPackage['payload']
        ) {
            throw ValidationException::withMessages(['package' => __('O perfil a reutilizar foi alterado. Volte a pré-visualizar a operação.')]);
        }

        $result = $write->handle($package, ['assessment_profile']);
        if ($result['created'] === 0) {
            return back()->withErrors(['reuse' => __('Já existe um perfil equivalente nesse ano letivo.')]);
        }

        $audit->record(
            'configuration_package.reused',
            subject: $assessmentProfile,
            causer: $request->user(),
            summary: __('Perfil de avaliação reutilizado.'),
            properties: ['profile_name' => $assessmentProfile->name, 'target_academic_year' => $target->label, ...$result],
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Perfil reutilizado em :year.', ['year' => $target->label])]);

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
            'scales' => Scale::orderByRaw('organization_id IS NOT NULL, name')->get(['id', 'name', 'kind', 'min_value', 'max_value', 'organization_id'])
                ->map(fn (Scale $scale) => [
                    'id' => $scale->id,
                    'label' => $scale->name,
                    'system' => $scale->organization_id === null,
                    'kind' => $scale->kind,
                    'min_value' => $scale->min_value,
                    'max_value' => $scale->max_value,
                ]),
        ];
    }

    protected function requestUser(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }

    /** @return array{ulid: string, name: string, academic_year: string, subject: string} */
    protected function reuseProfile(AssessmentProfile $assessmentProfile): array
    {
        return [
            'ulid' => $assessmentProfile->ulid,
            'name' => $assessmentProfile->name,
            'academic_year' => $assessmentProfile->academicYear->label,
            'subject' => $assessmentProfile->subject->name,
        ];
    }
}
