<?php

declare(strict_types=1);

namespace App\Actions\ConfigSharing;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\OrganizationIdentity;
use App\Models\ProfileVersionDomain;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\Subject;
use Illuminate\Support\Arr;

final class GenerateConfigurationPackage
{
    private const IDENTITY_FIELDS = ['official_name', 'short_name', 'address', 'postal_code', 'locality', 'country', 'phone', 'email', 'website', 'school_code', 'tax_number', 'department', 'footer_note'];

    /**
     * @param  array{school_identity?: bool, academic_years?: list<string>, subjects?: list<string>, scales?: list<string>, assessment_profiles?: list<string>}  $selection
     * @return array<string, mixed>
     */
    public function handle(array $selection): array
    {
        $profiles = AssessmentProfile::query()->with(['academicYear.periods', 'subject', 'gradeLevels', 'currentVersion.scale.levels', 'currentVersion.domains.domain'])->whereIn('ulid', $selection['assessment_profiles'] ?? [])->get();
        $years = AcademicYear::query()->with('periods')->whereIn('ulid', $selection['academic_years'] ?? [])->get();
        $subjects = Subject::query()->whereIn('ulid', $selection['subjects'] ?? [])->get();
        $scales = Scale::query()->with('levels')->whereNotNull('organization_id')->whereIn('ulid', $selection['scales'] ?? [])->get();

        foreach ($profiles as $profile) {
            $years->push($profile->academicYear);
            $subjects->push($profile->subject);
            if ($profile->currentVersion?->scale && ! $profile->currentVersion->scale->isSystem()) {
                $scales->push($profile->currentVersion->scale);
            }
        }

        $identity = ($selection['school_identity'] ?? false) ? OrganizationIdentity::query()->first() : null;
        $payload = [
            'school_identity' => $identity && ! $identity->isEmpty() ? Arr::only($identity->attributesToArray(), self::IDENTITY_FIELDS) : null,
            'academic_years' => $years->unique('id')->values()->map(fn (AcademicYear $year): array => [
                'label' => $year->label, 'starts_on' => $year->starts_on->toDateString(), 'ends_on' => $year->ends_on->toDateString(),
                'status' => $year->status->value, 'country_code' => $year->country_code, 'region_code' => $year->region_code,
                'periods' => $year->periods->map(fn (AcademicPeriod $period): array => [
                    'label' => $period->label, 'kind' => $period->kind->value, 'sequence' => $period->sequence,
                    'starts_on' => $period->starts_on->toDateString(), 'ends_on' => $period->ends_on->toDateString(), 'status' => $period->status->value,
                ])->values()->all(),
            ])->all(),
            'subjects' => $subjects->unique('id')->values()->map(fn (Subject $subject): array => ['name' => $subject->name, 'code' => $subject->code])->all(),
            'scales' => $scales->unique('id')->values()->map(fn (Scale $scale): array => $this->scale($scale))->all(),
            'assessment_profiles' => $profiles->values()->map(fn (AssessmentProfile $profile): array => $this->profile($profile))->all(),
        ];

        $components = collect($payload)->filter(fn ($value): bool => $value !== null && $value !== [])->keys()->values()->all();

        return [
            'kind' => 'lapis_configuration_package', 'schema_version' => 2, 'exported_at' => now()->toIso8601String(),
            'product' => ['name' => 'Lapispro', 'version' => (string) config('app.version')],
            'provenance' => ['note' => 'Informativo, nunca usado para autorizar escrita.', 'organization_name' => $identity->official_name ?? $identity->short_name ?? 'Lapispro'],
            'components' => $components, 'payload' => $payload,
        ];
    }

    /** @return array<string, mixed> */
    private function scale(Scale $scale): array
    {
        return ['name' => $scale->name, 'kind' => $scale->kind, 'min_value' => $scale->min_value, 'max_value' => $scale->max_value,
            'levels' => $scale->levels->map(fn (ScaleLevel $level): array => Arr::only($level->attributesToArray(), ['code', 'label', 'inovar_code', 'sequence', 'numeric_value', 'normalized_value', 'band_min_normalized', 'band_max_normalized', 'is_negative']))->values()->all()];
    }

    /** @return array<string, mixed> */
    private function profile(AssessmentProfile $profile): array
    {
        $version = $profile->currentVersion;
        abort_if($version === null, 422, __('O perfil :name não tem uma versão ativa para partilhar.', ['name' => $profile->name]));
        $scale = $version->scale;

        return [
            'academic_year_label' => $profile->academicYear->label, 'subject_code' => $profile->subject->code,
            'grade_levels' => $profile->gradeLevels->pluck('grade_level')->all(), 'name' => $profile->name, 'description' => $profile->description,
            'is_institutional_template' => $profile->is_institutional_template,
            'scale_reference' => ['name' => $scale->name, 'kind' => $scale->kind, 'system' => $scale->isSystem()],
            'version' => Arr::only($version->attributesToArray(), ['domain_weight_mode', 'period_result_mode', 'accumulated_mode', 'absence_mode', 'rounding_mode', 'rounding_scale', 'rounding_stage', 'minimum_rules']),
            'domains' => $version->domains->map(fn (ProfileVersionDomain $join): array => [
                'subject_code' => $profile->subject->code, 'code' => $join->domain->code, 'name' => $join->domain->name,
                'parent_code' => $join->domain->parent_domain_id ? $join->domain->newQuery()->find($join->domain->parent_domain_id)?->code : null,
                'domain_sequence' => $join->domain->sequence, 'is_active' => $join->domain->is_active,
                'weight_percent' => $join->weight_percent, 'sequence' => $join->sequence,
                'expected_element_count' => $join->expected_element_count, 'minimum_element_count' => $join->minimum_element_count,
            ])->values()->all(),
        ];
    }
}
