<?php

declare(strict_types=1);

namespace App\Actions\ConfigSharing;

use App\Support\Import\Backup\SecretScanner;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ValidateConfigurationPackage
{
    private const FORBIDDEN_DOMAINS = ['student', 'students', 'enrollment', 'enrollments', 'score', 'scores', 'classification', 'classifications', 'self_assessment', 'evidence_record', 'intervention', 'report', 'user', 'users', 'membership', 'memberships', 'owner'];

    public function __construct(private readonly SecretScanner $secretScanner) {}

    /** @return array<string, mixed> */
    public function fromJson(string $json): array
    {
        try {
            $package = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['file' => __('O ficheiro não contém JSON válido.')]);
        }
        if (! is_array($package)) {
            throw ValidationException::withMessages(['file' => __('O pacote de configuração é inválido.')]);
        }
        if (($package['kind'] ?? null) !== 'lapis_configuration_package') {
            throw ValidationException::withMessages(['file' => __('Este ficheiro não é um pacote de configuração do Lapispro.')]);
        }
        if (($package['schema_version'] ?? null) !== 1) {
            throw ValidationException::withMessages(['file' => __('A versão deste pacote de configuração não é suportada.')]);
        }
        $topLevelKeys = ['kind', 'schema_version', 'exported_at', 'product', 'provenance', 'components', 'payload'];
        if (array_diff(array_keys($package), $topLevelKeys) !== []) {
            throw ValidationException::withMessages(['file' => __('O pacote contém campos que não pertencem ao formato de configuração.')]);
        }
        $forbidden = array_merge($this->secretScanner->scan($package), $this->scanForbiddenDomains($package));
        if ($forbidden !== []) {
            throw ValidationException::withMessages(['file' => __('O ficheiro contém dados ou segredos que não podem fazer parte de um pacote de configuração.')]);
        }

        $allowedComponents = ['school_identity', 'academic_years', 'subjects', 'scales', 'assessment_profiles'];
        $validator = Validator::make($package, [
            'kind' => ['required', 'in:lapis_configuration_package'], 'schema_version' => ['required', 'integer', 'in:1'],
            'exported_at' => ['required', 'date'], 'product' => ['required', 'array:name,version'],
            'provenance' => ['required', 'array:note,organization_name'],
            'components' => ['required', 'array'], 'components.*' => ['string', 'in:'.implode(',', $allowedComponents)],
            'payload' => ['required', 'array:'.implode(',', $allowedComponents)],
            'payload.school_identity' => ['nullable', 'array:official_name,short_name,address,postal_code,locality,country,phone,email,website,school_code,tax_number,department,footer_note'],
            'payload.academic_years' => ['present', 'array'], 'payload.academic_years.*' => ['array:label,starts_on,ends_on,status,country_code,region_code,periods'],
            'payload.academic_years.*.label' => ['required', 'string', 'max:50'], 'payload.academic_years.*.starts_on' => ['required', 'date'], 'payload.academic_years.*.ends_on' => ['required', 'date', 'after:payload.academic_years.*.starts_on'],
            'payload.academic_years.*.periods' => ['present', 'array'], 'payload.academic_years.*.periods.*' => ['array:label,kind,sequence,starts_on,ends_on,status'],
            'payload.academic_years.*.periods.*.label' => ['required', 'string'], 'payload.academic_years.*.periods.*.kind' => ['required', 'string', 'in:semester,term,trimester,module,other'],
            'payload.academic_years.*.periods.*.sequence' => ['required', 'integer', 'min:1'], 'payload.academic_years.*.periods.*.starts_on' => ['required', 'date'], 'payload.academic_years.*.periods.*.ends_on' => ['required', 'date'],
            'payload.academic_years.*.periods.*.status' => ['required', 'string', 'in:draft,open,closed,archived'],
            'payload.subjects' => ['present', 'array'], 'payload.subjects.*' => ['array:name,code'], 'payload.subjects.*.name' => ['required', 'string'], 'payload.subjects.*.code' => ['required', 'string'],
            'payload.scales' => ['present', 'array'], 'payload.scales.*' => ['array:name,kind,min_value,max_value,levels'], 'payload.scales.*.name' => ['required', 'string'], 'payload.scales.*.kind' => ['required', 'string'], 'payload.scales.*.levels' => ['present', 'array'],
            'payload.scales.*.levels.*' => ['array:code,label,inovar_code,sequence,numeric_value,normalized_value,band_min_normalized,band_max_normalized,is_negative'],
            'payload.scales.*.levels.*.code' => ['required', 'string'], 'payload.scales.*.levels.*.label' => ['required', 'string'], 'payload.scales.*.levels.*.sequence' => ['required', 'integer'],
            'payload.assessment_profiles' => ['present', 'array'], 'payload.assessment_profiles.*' => ['array:academic_year_label,subject_code,grade_level,name,description,is_institutional_template,scale_reference,version,domains'],
            'payload.assessment_profiles.*.academic_year_label' => ['required', 'string'], 'payload.assessment_profiles.*.subject_code' => ['required', 'string'], 'payload.assessment_profiles.*.name' => ['required', 'string'],
            'payload.assessment_profiles.*.scale_reference' => ['required', 'array:name,kind,system'], 'payload.assessment_profiles.*.version' => ['required', 'array:domain_weight_mode,period_result_mode,accumulated_mode,absence_mode,rounding_mode,rounding_scale,rounding_stage,minimum_rules'],
            'payload.assessment_profiles.*.domains' => ['required', 'array'],
            'payload.assessment_profiles.*.domains.*' => ['array:subject_code,code,name,parent_code,domain_sequence,is_active,weight_percent,sequence,expected_element_count,minimum_element_count'],
            'payload.assessment_profiles.*.domains.*.code' => ['required', 'string'], 'payload.assessment_profiles.*.domains.*.name' => ['required', 'string'],
            'payload.assessment_profiles.*.domains.*.weight_percent' => ['required', 'numeric', 'between:0,100'], 'payload.assessment_profiles.*.domains.*.sequence' => ['required', 'integer'],
        ]);
        $validator->after(function ($validator) use ($package): void {
            $components = $package['components'];
            foreach ($package['payload'] as $key => $value) {
                if (($value !== null && $value !== []) !== in_array($key, $components, true)) {
                    $validator->errors()->add('file', __('Os componentes declarados não correspondem ao conteúdo do pacote.'));
                }
            }
            $yearLabels = array_column($package['payload']['academic_years'], 'label');
            $subjectCodes = array_column($package['payload']['subjects'], 'code');
            $scaleRefs = array_map(fn (array $scale): string => $scale['name'].'|'.$scale['kind'], $package['payload']['scales']);
            foreach ($package['payload']['assessment_profiles'] as $profile) {
                if (! in_array($profile['academic_year_label'], $yearLabels, true) || ! in_array($profile['subject_code'], $subjectCodes, true)) {
                    $validator->errors()->add('file', __('Um perfil referencia um ano letivo ou disciplina que o pacote não inclui.'));
                }
                if (! $profile['scale_reference']['system'] && ! in_array($profile['scale_reference']['name'].'|'.$profile['scale_reference']['kind'], $scaleRefs, true)) {
                    $validator->errors()->add('file', __('Um perfil referencia uma escala própria que o pacote não inclui.'));
                }
            }
        });
        $validator->validate();

        return $package;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<string>
     */
    private function scanForbiddenDomains(array $value, string $path = ''): array
    {
        $found = [];
        foreach ($value as $key => $child) {
            $current = $path === '' ? (string) $key : $path.'.'.$key;
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_DOMAINS, true)) {
                $found[] = $current;
            }
            if (is_array($child)) {
                array_push($found, ...$this->scanForbiddenDomains($child, $current));
            }
        }

        return $found;
    }
}
