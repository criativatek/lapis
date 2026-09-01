<?php

declare(strict_types=1);

namespace App\Actions\ConfigSharing;

use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\OrganizationIdentity;
use App\Models\Scale;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Model;

final class BuildConfigurationImportPlan
{
    /**
     * @param  array<string, mixed>  $package
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function handle(array $package): array
    {
        $rows = [];
        if ($package['payload']['school_identity'] !== null) {
            $existing = OrganizationIdentity::query()->first();
            $rows[] = $this->row('school_identity', 'Identidade da escola', $package['payload']['school_identity'], $existing, $existing?->isEmpty() ?? true);
        }
        foreach ($package['payload']['academic_years'] as $data) {
            $existing = AcademicYear::query()->where('label', $data['label'])->first();
            $rows[] = $this->row('academic_year', $data['label'], $data, $existing, false, ['label']);
        }
        foreach ($package['payload']['subjects'] as $data) {
            $existing = Subject::query()->where('code', $data['code'])->first();
            $rows[] = $this->row('subject', $data['code'].' — '.$data['name'], $data, $existing, false, ['code']);
        }
        foreach ($package['payload']['scales'] as $data) {
            $existing = Scale::query()->whereNotNull('organization_id')->where('name', $data['name'])->first();
            $rows[] = $this->row('scale', $data['name'], $data, $existing, false, ['name']);
        }
        foreach ($package['payload']['assessment_profiles'] as $data) {
            $year = AcademicYear::query()->where('label', $data['academic_year_label'])->first();
            $subject = Subject::query()->where('code', $data['subject_code'])->first();
            $candidates = $year && $subject ? AssessmentProfile::query()->with(['versions.scale', 'versions.domains.domain', 'gradeLevels'])->where('academic_year_id', $year->id)->where('subject_id', $subject->id)->where('name', $data['name'])->get() : collect();
            /** @var list<string> $wantedGradeLevels */
            $wantedGradeLevels = $data['grade_levels'];
            sort($wantedGradeLevels);
            $existing = $candidates->first(function (AssessmentProfile $candidate) use ($wantedGradeLevels): bool {
                /** @var list<string> $candidateGradeLevels */
                $candidateGradeLevels = $candidate->gradeLevels->pluck('grade_level')->all();
                sort($candidateGradeLevels);

                return $candidateGradeLevels === $wantedGradeLevels;
            });
            $profileRow = $this->profileRow($data, $existing);
            $dependencyConflict = collect($rows)->contains(fn (array $row): bool => $row['status'] === 'conflict' && (
                ($row['type'] === 'academic_year' && $row['data']['label'] === $data['academic_year_label'])
                || ($row['type'] === 'subject' && $row['data']['code'] === $data['subject_code'])
                || ($row['type'] === 'scale' && $row['data']['name'] === $data['scale_reference']['name'])
            ));
            if ($dependencyConflict) {
                $profileRow['status'] = 'conflict';
            }
            $rows[] = $profileRow;
        }

        $summary = ['new' => 0, 'existing' => 0, 'conflict' => 0, 'invalid' => 0];
        foreach ($rows as $row) {
            $summary[$row['status']]++;
        }

        return ['summary' => $summary, 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $ignore
     * @return array<string, mixed>
     */
    private function row(string $type, string $label, array $data, ?Model $existing, bool $emptyIsNew = false, array $ignore = []): array
    {
        if ($existing === null || $emptyIsNew) {
            $status = 'new';
        } else {
            $wanted = $this->comparable($data, $ignore);
            $current = $this->comparable($existing->attributesToArray(), $ignore);
            if ($existing instanceof AcademicYear) {
                $current['starts_on'] = $existing->starts_on->toDateString();
                $current['ends_on'] = $existing->ends_on->toDateString();
                $current['status'] = $existing->status->value;
                $current['periods'] = $existing->periods()->get()->map(fn ($p): array => ['label' => $p->label, 'kind' => $p->kind->value, 'sequence' => $p->sequence, 'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on->toDateString(), 'status' => $p->status->value])->all();
            }
            if ($existing instanceof Scale) {
                $current['levels'] = $existing->levels()->get()->map(fn ($level): array => $this->comparable($level->attributesToArray()))->all();
            }
            $status = $wanted == array_intersect_key($current, $wanted) ? 'existing' : 'conflict';
        }

        return compact('type', 'label', 'status', 'data');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function profileRow(array $data, ?AssessmentProfile $existing): array
    {
        $status = 'new';
        if ($existing) {
            $equivalent = $existing->versions->contains(function ($version) use ($data): bool {
                $scaleMatches = $version->scale?->name === $data['scale_reference']['name'] && $version->scale?->kind === $data['scale_reference']['kind'];
                $versionMatches = $this->comparable($data['version']) == array_intersect_key($this->comparable($version->attributesToArray()), $data['version']);
                $domains = $version->domains->map(fn ($join): array => ['code' => $join->domain->code, 'weight_percent' => $join->weight_percent, 'sequence' => $join->sequence, 'expected_element_count' => $join->expected_element_count, 'minimum_element_count' => $join->minimum_element_count])->values()->all();
                $wanted = array_values(array_map(fn (array $domain): array => ['code' => $domain['code'], 'weight_percent' => $domain['weight_percent'], 'sequence' => $domain['sequence'], 'expected_element_count' => $domain['expected_element_count'], 'minimum_element_count' => $domain['minimum_element_count']], $data['domains']));

                return $scaleMatches && $versionMatches && $domains == $wanted;
            });
            $profileMatches = $existing->description === $data['description'] && $existing->is_institutional_template === $data['is_institutional_template'];
            $status = $profileMatches && $equivalent ? 'existing' : 'conflict';
        }

        return ['type' => 'assessment_profile', 'label' => $data['name'].' — '.$data['academic_year_label'].' / '.$data['subject_code'], 'status' => $status, 'data' => $data];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $ignore
     * @return array<string, mixed>
     */
    private function comparable(array $data, array $ignore = []): array
    {
        foreach (['id', 'ulid', 'organization_id', 'created_at', 'updated_at', 'frozen_at', 'closed_at', 'closed_by', ...$ignore] as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
