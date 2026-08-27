<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ConfigSharing\BuildConfigurationImportPlan;
use App\Actions\ConfigSharing\GenerateConfigurationPackage;
use App\Actions\ConfigSharing\ValidateConfigurationPackage;
use App\Actions\ConfigSharing\WriteConfigurationImport;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\OrganizationIdentity;
use App\Models\Scale;
use App\Models\Subject;
use App\Services\Audit\AuditLog;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class ConfigurationSharingController extends Controller
{
    private const SESSION_KEY = 'configuration_sharing.package';

    public function export(): InertiaResponse
    {
        return Inertia::render('config-sharing/Export', ['options' => [
            'hasSchoolIdentity' => ! (OrganizationIdentity::query()->first()?->isEmpty() ?? true),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['ulid', 'label']),
            'subjects' => Subject::query()->orderBy('name')->get(['ulid', 'name', 'code']),
            'scales' => Scale::query()->whereNotNull('organization_id')->orderBy('name')->get(['ulid', 'name', 'kind']),
            'assessmentProfiles' => AssessmentProfile::query()->with(['academicYear:id,label', 'subject:id,name,code'])->whereNotNull('current_version_id')->orderBy('name')->get()->map(fn ($profile): array => ['ulid' => $profile->ulid, 'name' => $profile->name, 'year' => $profile->academicYear->label, 'subject' => $profile->subject->name]),
        ]]);
    }

    public function download(Request $request, GenerateConfigurationPackage $generate, AuditLog $audit): Response
    {
        $selection = $request->validate([
            'school_identity' => ['sometimes', 'boolean'], 'academic_years' => ['array'], 'academic_years.*' => ['string'],
            'subjects' => ['array'], 'subjects.*' => ['string'], 'scales' => ['array'], 'scales.*' => ['string'],
            'assessment_profiles' => ['array'], 'assessment_profiles.*' => ['string'],
        ]);
        abort_if(array_filter(Arr::flatten($selection)) === [], 422, __('Selecione pelo menos uma configuração.'));
        $package = $generate->handle($selection);
        $audit->record('configuration_package.exported', causer: $request->user(), summary: __('Pacote de configuração exportado.'), properties: ['components' => $package['components']]);

        return response(json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 200, [
            'Content-Type' => 'application/json; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="Lapispro-configuracao-'.now()->format('Y-m-d').'.json"',
        ]);
    }

    public function import(): InertiaResponse
    {
        return Inertia::render('config-sharing/Import');
    }

    public function preview(Request $request, ValidateConfigurationPackage $validate, BuildConfigurationImportPlan $plans, CurrentOrganization $tenant): InertiaResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'mimetypes:application/json,text/plain', 'max:20480']]);
        $contents = file_get_contents($data['file']->getRealPath());
        if ($contents === false) {
            throw ValidationException::withMessages(['file' => __('Não foi possível ler o ficheiro carregado.')]);
        }
        $package = $validate->fromJson($contents);
        $request->session()->put(self::SESSION_KEY, ['organization_id' => $tenant->id(), 'package' => $package]);

        return Inertia::render('config-sharing/Preview', [
            'plan' => $plans->handle($package),
            'provenance' => $package['provenance'],
            'exportedAt' => $package['exported_at'],
            'productVersion' => $package['product']['version'] ?? null,
        ]);
    }

    public function confirm(Request $request, WriteConfigurationImport $write, AuditLog $audit, CurrentOrganization $tenant): RedirectResponse
    {
        $stored = $request->session()->get(self::SESSION_KEY);
        abort_unless(is_array($stored) && $stored['organization_id'] === $tenant->id(), 403);
        $data = $request->validate(['types' => ['required', 'array'], 'types.*' => ['in:school_identity,academic_year,subject,scale,assessment_profile']]);
        $result = $write->handle($stored['package'], $data['types']);
        $request->session()->forget(self::SESSION_KEY);
        $audit->record('configuration_package.imported', causer: $request->user(), summary: __('Pacote de configuração importado.'), properties: $result);

        return to_route('configuration-sharing.import')->with('success', __('Configurações importadas: :count.', ['count' => $result['created']]));
    }
}
