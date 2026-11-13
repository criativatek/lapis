<?php

namespace App\Http\Controllers;

use App\Actions\ConfigSharing\BuildConfigurationImportPlan;
use App\Actions\ConfigSharing\BuildYearReusePackage;
use App\Actions\ConfigSharing\WriteConfigurationImport;
use App\Domain\Assessment\Bc;
use App\Http\Requests\AssessmentProfileRequest;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\Organization;
use App\Models\Scale;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Audit\AuditLog;
use App\Support\Assessment\GradeLevelLabel;
use App\Support\Assessment\ProfileActivationException;
use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCenter;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;

class AssessmentProfileController extends Controller
{
    public function __construct(protected ProfileBuilder $builder, protected HelpCenter $helpCenter) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', AssessmentProfile::class);

        return Inertia::render('assessment-profiles/Index', [
            'profiles' => AssessmentProfile::with(['subject', 'academicYear', 'currentVersion', 'gradeLevels'])
                ->orderByDesc('created_at')
                ->get()
                ->map(function (AssessmentProfile $profile) {
                    $gradeLevels = array_values($profile->gradeLevels->pluck('grade_level')->all());

                    return [
                        'ulid' => $profile->ulid,
                        'name' => $profile->name,
                        'subject' => $profile->subject->name,
                        'academic_year' => $profile->academicYear->label,
                        'grade_levels' => $gradeLevels,
                        'grade_levels_label' => GradeLevelLabel::forList($gradeLevels),
                        'is_active' => $profile->current_version_id !== null,
                        'status_label' => $profile->currentVersion?->status->label() ?? __('Rascunho'),
                        'has_draft' => $profile->draftVersion() !== null,
                    ];
                }),
            // A member reads and uses a profile to teach; only the
            // organization's owner defines how grades are calculated (Fatia 1).
            'canManage' => Gate::allows('create', AssessmentProfile::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', AssessmentProfile::class);

        return Inertia::render('assessment-profiles/Create', [
            ...$this->formOptions(),
            // Por defeito, a escala com menções (Fraco… Muito Bom): quem não
            // mexer fica com ela. Não é obrigação — é o que evita a pauta só
            // com percentagens de quem criou o perfil sem reparar na escala.
            'defaultScaleId' => $this->defaultScaleId(),
            // "Precisa de ajuda?" (A2, Onboarding & Help) — critérios, domínios
            // e pesos are the one place in this flow a first-time teacher is
            // most likely to get stuck, per the brief's own explicit choice.
            'helpArticles' => $this->helpCenter->forContext('assessment-profiles.create')
                ->map(fn (HelpArticle $article): array => $article->toArray())
                ->values()
                ->all(),
        ]);
    }

    public function store(AssessmentProfileRequest $request): RedirectResponse
    {
        Gate::authorize('create', AssessmentProfile::class);

        $this->builder->create(
            $request->safe()->only(['name', 'academic_year_id', 'subject_id', 'description']),
            (int) $request->validated('scale_id'),
            $request->validated('domains'),
            $request->validated('grade_levels', []),
        );

        return to_route('assessment-profiles.index');
    }

    public function edit(AssessmentProfile $assessmentProfile): Response
    {
        Gate::authorize('view', $assessmentProfile);

        $version = $assessmentProfile->draftVersion() ?? $assessmentProfile->currentVersion;
        $version?->load('domains.domain');
        $assessmentProfile->load('gradeLevels');

        return Inertia::render('assessment-profiles/Edit', [
            ...$this->formOptions(),
            'profile' => [
                'ulid' => $assessmentProfile->ulid,
                'name' => $assessmentProfile->name,
                'academic_year_id' => $assessmentProfile->academic_year_id,
                'subject_id' => $assessmentProfile->subject_id,
                'grade_levels' => $assessmentProfile->gradeLevels->pluck('grade_level')->all(),
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
            $request->safe()->only(['name', 'academic_year_id', 'subject_id', 'description']),
            (int) $request->validated('scale_id'),
            $request->validated('domains'),
            $request->validated('grade_levels', []),
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
        $organizationScaleId = $this->organizationScaleId();

        return [
            'academicYears' => AcademicYear::orderByDesc('starts_on')->get(['ulid', 'id', 'label'])
                ->map(fn (AcademicYear $year) => ['id' => $year->id, 'label' => $year->label]),
            'subjects' => Subject::orderBy('name')->get(['id', 'name'])
                ->map(fn (Subject $subject) => ['id' => $subject->id, 'label' => $subject->name]),
            'scales' => Scale::with('levels')->orderByRaw('organization_id IS NOT NULL, name')->get()
                ->sortBy(fn (Scale $scale): int => $scale->id === $organizationScaleId ? 0 : 1)
                ->values()
                ->map(fn (Scale $scale) => [
                    'id' => $scale->id,
                    'organization_default' => $scale->id === $organizationScaleId,
                    'ulid' => $scale->ulid,
                    'label' => $scale->name,
                    'system' => $scale->organization_id === null,
                    'kind' => $scale->kind,
                    'min_value' => $scale->min_value,
                    'max_value' => $scale->max_value,
                    // Os níveis, para o diálogo «Personalizar escala» os mostrar como
                    // estão; vazio numa escala de intervalo.
                    'levels' => $this->levelsForForm($scale),
                ]),
        ];
    }

    /**
     * Os níveis de uma escala como o diálogo os edita. O corte é o inteiro
     * «a partir de X %»: 0 no primeiro nível, e a banda mínima mais meio ponto
     * nos restantes (49.5 → 50) — a conversão inversa de ScaleCutsToBands.
     *
     * @return list<array{code: string, label: string, from_percent: int, inovar_code: string|null, is_negative: bool}>
     */
    protected function levelsForForm(Scale $scale): array
    {
        $levels = [];

        foreach ($scale->levels->sortBy('sequence')->values() as $index => $level) {
            $levels[] = [
                'code' => (string) $level->code,
                'label' => (string) $level->label,
                'from_percent' => $index === 0 || $level->band_min_normalized === null
                    ? 0
                    : (int) Bc::truncate(Bc::add(Bc::of((string) $level->band_min_normalized), '0.5'), 0),
                'inovar_code' => $level->inovar_code,
                'is_negative' => (bool) $level->is_negative,
            ];
        }

        return $levels;
    }

    /**
     * A escala da organização, se ainda existe e é visível. O apontador desfaz-se
     * sozinho quando a escala é apagada (`nullOnDelete`); a verificação protege
     * também de uma escala de outra organização que lá tenha ido parar.
     */
    protected function organizationScaleId(): ?int
    {
        $scaleId = Organization::query()->whereKey(app(CurrentOrganization::class)->id())->value('default_scale_id');

        if ($scaleId === null) {
            return null;
        }

        return Scale::query()
            ->whereKey($scaleId)
            ->where('organization_id', app(CurrentOrganization::class)->id())
            ->value('id');
    }

    /** Com que escala abre um perfil novo: a da organização, ou a «Escala 1 a 5» do sistema. */
    protected function defaultScaleId(): ?int
    {
        return $this->organizationScaleId()
            ?? Scale::whereNull('organization_id')->where('name', 'Escala 1 a 5')->value('id');
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
