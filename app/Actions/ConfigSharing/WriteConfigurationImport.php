<?php

declare(strict_types=1);

namespace App\Actions\ConfigSharing;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\Domain;
use App\Models\OrganizationIdentity;
use App\Models\ProfileVersionDomain;
use App\Models\ProfileVersionStatus;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WriteConfigurationImport
{
    public function __construct(private readonly BuildConfigurationImportPlan $plans) {}

    /**
     * @param  array<string, mixed>  $package
     * @param  list<string>  $selectedTypes
     * @return array{created: int, skipped: int}
     */
    public function handle(array $package, array $selectedTypes): array
    {
        return DB::transaction(function () use ($package, $selectedTypes): array {
            $plan = $this->plans->handle($package);
            $created = 0;
            $skipped = 0;
            foreach ($plan['rows'] as $row) {
                if (! in_array($row['type'], $selectedTypes, true) || $row['status'] !== 'new') {
                    $skipped++;

                    continue;
                }
                match ($row['type']) {
                    'school_identity' => $this->writeIdentity($row['data']),
                    'academic_year' => $this->writeYear($row['data']),
                    'subject' => Subject::query()->create($row['data']),
                    'scale' => $this->writeScale($row['data']),
                    'assessment_profile' => $this->writeProfile($row['data']),
                    default => throw ValidationException::withMessages(['package' => __('O plano contém um tipo desconhecido.')]),
                };
                $created++;
            }

            return compact('created', 'skipped');
        });
    }

    /** @param array<string, mixed> $data */
    private function writeIdentity(array $data): void
    {
        $identity = OrganizationIdentity::query()->first() ?? new OrganizationIdentity;
        if ($identity->exists && ! $identity->isEmpty()) {
            return;
        }
        $identity->fill($data)->save();
    }

    /** @param array<string, mixed> $data */
    private function writeYear(array $data): void
    {
        $periods = $data['periods'];
        unset($data['periods']);
        $year = AcademicYear::query()->create($data);
        foreach ($periods as $period) {
            $period['academic_year_id'] = $year->id;
            AcademicPeriod::query()->create($period);
        }
    }

    /** @param array<string, mixed> $data */
    private function writeScale(array $data): void
    {
        $levels = $data['levels'];
        unset($data['levels']);
        $scale = Scale::query()->create($data);
        foreach ($levels as $level) {
            $level['scale_id'] = $scale->id;
            ScaleLevel::query()->create($level);
        }
    }

    /** @param array<string, mixed> $data */
    private function writeProfile(array $data): void
    {
        $year = AcademicYear::query()->where('label', $data['academic_year_label'])->firstOrFail();
        $subject = Subject::query()->where('code', $data['subject_code'])->firstOrFail();
        $scale = Scale::query()->where('name', $data['scale_reference']['name'])->where('kind', $data['scale_reference']['kind'])
            ->when($data['scale_reference']['system'], fn ($q) => $q->whereNull('organization_id'), fn ($q) => $q->whereNotNull('organization_id'))->firstOrFail();
        $profile = AssessmentProfile::query()->create(['academic_year_id' => $year->id, 'subject_id' => $subject->id, 'grade_level' => $data['grade_level'], 'name' => $data['name'], 'description' => $data['description']]);
        $profile->forceFill(['is_institutional_template' => $data['is_institutional_template']])->save();
        $version = AssessmentProfileVersion::query()->create([...$data['version'], 'assessment_profile_id' => $profile->id, 'scale_id' => $scale->id, 'status' => ProfileVersionStatus::Draft, 'version_number' => ((int) $profile->versions()->max('version_number')) + 1]);
        $domainMap = [];
        $createdCodes = [];
        foreach ($data['domains'] as $domainData) {
            $domain = Domain::query()->firstOrCreate(['subject_id' => $subject->id, 'code' => $domainData['code']], ['name' => $domainData['name'], 'sequence' => $domainData['domain_sequence'], 'is_active' => $domainData['is_active']]);
            $domainMap[$domainData['code']] = $domain;
            if ($domain->wasRecentlyCreated) {
                $createdCodes[$domainData['code']] = true;
            }
        }
        foreach ($data['domains'] as $domainData) {
            $domain = $domainMap[$domainData['code']];
            // A domain matched by business key (subject+code) is reused as-is — it may
            // already serve another profile, so only a domain this import just created
            // gets its parent wired up; a pre-existing domain's hierarchy is never touched.
            if ($domainData['parent_code'] !== null && isset($createdCodes[$domainData['code']])) {
                $domain->update(['parent_domain_id' => $domainMap[$domainData['parent_code']]->id]);
            }
            ProfileVersionDomain::query()->create(['assessment_profile_version_id' => $version->id, 'domain_id' => $domain->id, 'weight_percent' => $domainData['weight_percent'], 'sequence' => $domainData['sequence'], 'expected_element_count' => $domainData['expected_element_count'], 'minimum_element_count' => $domainData['minimum_element_count']]);
        }
    }
}
