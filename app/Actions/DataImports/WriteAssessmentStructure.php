<?php

namespace App\Actions\DataImports;

use App\Actions\DataImports\Concerns\ResolvesWrittenReferences;
use App\Models\AcademicPeriod;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\Domain;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\ProfileVersionDomain;
use App\Models\ProfileVersionPeriod;
use App\Models\Scale;
use App\Models\ScaleLevel;

/**
 * Writes the assessment STRUCTURE tier a validated backup's plan already
 * classified: academic periods, scales, instrument types, domains,
 * assessment profiles/versions and their weights. Split out of
 * `ExecuteDataImport` purely to keep each file small enough for PHPStan to
 * analyse — see that class for the transaction/idempotency guarantees this
 * writer runs inside.
 *
 * Only rows classified `new` by the plan are ever written — `existing`,
 * `conflict`, `invalid` and `unsupported` are all skipped here, never
 * merged or overwritten (§12 of the import brief). Every lookup map this
 * method returns (`periodsByUlid`, `scalesByRef`, …) covers BOTH rows it
 * just created and rows the plan matched as `existing`, so anything
 * written afterwards (a class, an instrument) can resolve either kind
 * identically.
 */
class WriteAssessmentStructure
{
    use ResolvesWrittenReferences;

    /**
     * @param  array<int, array<string, mixed>>  $periodRows
     * @param  array<int, array<string, mixed>>  $scaleRows
     * @param  array<int, array<string, mixed>>  $instrumentTypeRows
     * @param  array<int, array<string, mixed>>  $domainRows
     * @param  array<int, array<string, mixed>>  $profileRows
     * @param  array<int, array<string, mixed>>  $profileVersionRows
     * @param  array<int, array<string, mixed>>  $profileVersionDomainRows
     * @param  array<int, array<string, mixed>>  $profileVersionPeriodRows
     * @return array{periodsByUlid: array<string, int>, scalesByRef: array<string, int>, scaleLevelsByRef: array<string, int>, instrumentTypesByRef: array<string, int>, domainsByUlid: array<string, int>, profilesByUlid: array<string, int>, profileVersionsByUlid: array<string, int>, createdCounts: array<string, int>}
     */
    public function write(
        array $periodRows,
        array $scaleRows,
        array $instrumentTypeRows,
        array $domainRows,
        array $profileRows,
        array $profileVersionRows,
        array $profileVersionDomainRows,
        array $profileVersionPeriodRows,
        Organization $organization,
    ): array {
        $periodsByUlid = $this->writeAcademicPeriods($periodRows);
        ['scalesByRef' => $scalesByRef, 'scaleLevelsByRef' => $scaleLevelsByRef, 'created' => $scalesCreated] = $this->writeScales($scaleRows);
        ['byRef' => $instrumentTypesByRef, 'created' => $typesCreated] = $this->writeInstrumentTypes($instrumentTypeRows);
        ['byUlid' => $domainsByUlid, 'created' => $domainsCreated] = $this->writeDomains($domainRows);
        ['byUlid' => $profilesByUlid, 'createdIds' => $newlyCreatedProfileIds] = $this->writeProfiles($profileRows);
        ['byUlid' => $profileVersionsByUlid, 'created' => $versionsCreated] = $this->writeProfileVersions($profileVersionRows, $profilesByUlid, $newlyCreatedProfileIds, $scalesByRef);
        $this->writeProfileVersionDomains($profileVersionDomainRows, $profileVersionsByUlid, $domainsByUlid);
        $this->writeProfileVersionPeriods($profileVersionPeriodRows, $profileVersionsByUlid, $periodsByUlid);

        return [
            'periodsByUlid' => $periodsByUlid,
            'scalesByRef' => $scalesByRef,
            'scaleLevelsByRef' => $scaleLevelsByRef,
            'instrumentTypesByRef' => $instrumentTypesByRef,
            'domainsByUlid' => $domainsByUlid,
            'profilesByUlid' => $profilesByUlid,
            'profileVersionsByUlid' => $profileVersionsByUlid,
            'createdCounts' => [
                'academic_periods' => count(array_filter($periodRows, fn (array $r): bool => $r['classification'] === 'new')),
                'scales' => $scalesCreated,
                'instrument_types' => $typesCreated,
                'domains' => $domainsCreated,
                'assessment_profiles' => count(array_filter($profileRows, fn (array $r): bool => $r['classification'] === 'new')),
                'assessment_profile_versions' => $versionsCreated,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function writeAcademicPeriods(array $rows): array
    {
        $byUlid = [];

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $period = new AcademicPeriod;
                $period->forceFill([
                    'ulid' => $row['ulid'], 'academic_year_id' => $row['academic_year_id'], 'label' => $row['label'],
                    'kind' => $row['kind'], 'sequence' => $row['sequence'], 'starts_on' => $row['starts_on'],
                    'ends_on' => $row['ends_on'], 'status' => $row['status'],
                ]);
                $period->save();
                $byUlid[$row['ulid']] = $period->getKey();
            } elseif (in_array($row['classification'], ['existing', 'conflict'], true) && isset($row['existing_id'])) {
                $byUlid[$row['ulid']] = (int) $row['existing_id'];
            }
        }

        return $byUlid;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{scalesByRef: array<string, int>, scaleLevelsByRef: array<string, int>, created: int}
     */
    private function writeScales(array $rows): array
    {
        $scalesByRef = [];
        $scaleLevelsByRef = [];
        $created = 0;

        foreach ($rows as $row) {
            $refKey = $row['is_system'] ? "system:{$row['name']}" : "custom:{$row['ulid']}";

            if ($row['classification'] === 'new') {
                $scale = new Scale;
                $scale->forceFill(['ulid' => $row['ulid'], 'name' => $row['name'], 'kind' => $row['kind'], 'min_value' => $row['min_value'], 'max_value' => $row['max_value']]);
                $scale->save();
                $scalesByRef[$refKey] = $scale->getKey();

                foreach ($row['levels'] as $level) {
                    $created2 = $scale->levels()->create([
                        'code' => $level['code'], 'label' => $level['label'], 'inovar_code' => $level['inovar_code'],
                        'sequence' => $level['sequence'], 'numeric_value' => $level['numeric_value'], 'normalized_value' => $level['normalized_value'],
                        'band_min_normalized' => $level['band_min_normalized'], 'band_max_normalized' => $level['band_max_normalized'],
                        'is_negative' => $level['is_negative'],
                    ]);
                    $scaleLevelsByRef["{$refKey}:{$level['code']}"] = $created2->getKey();
                }

                $created++;
            } elseif (in_array($row['classification'], ['existing', 'conflict'], true) && isset($row['existing_id'])) {
                $scalesByRef[$refKey] = (int) $row['existing_id'];

                foreach (ScaleLevel::query()->where('scale_id', $row['existing_id'])->get() as $level) {
                    $scaleLevelsByRef["{$refKey}:{$level->code}"] = $level->getKey();
                }
            }
        }

        return ['scalesByRef' => $scalesByRef, 'scaleLevelsByRef' => $scaleLevelsByRef, 'created' => $created];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{byRef: array<string, int>, created: int}
     */
    private function writeInstrumentTypes(array $rows): array
    {
        $byRef = [];
        $created = 0;

        foreach ($rows as $row) {
            $refKey = $row['is_system'] ? "system:{$row['code']}" : "custom:{$row['ulid']}";

            if ($row['classification'] === 'new') {
                $type = new InstrumentType;
                $type->forceFill(['ulid' => $row['ulid'], 'name' => $row['name'], 'code' => $row['code'], 'default_purpose' => $row['default_purpose'], 'is_active' => $row['is_active']]);
                $type->save();
                $byRef[$refKey] = $type->getKey();
                $created++;
            } elseif (in_array($row['classification'], ['existing', 'conflict'], true) && isset($row['existing_id'])) {
                $byRef[$refKey] = (int) $row['existing_id'];
            }
        }

        return ['byRef' => $byRef, 'created' => $created];
    }

    /**
     * Two passes: the first creates every domain without `parent_domain_id`,
     * the second wires it only when the parent also resolved to a real
     * destination row in this same run.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{byUlid: array<string, int>, created: int}
     */
    private function writeDomains(array $rows): array
    {
        $byUlid = [];
        $created = 0;
        /** @var array<string, string> $pendingParents */
        $pendingParents = [];

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $domain = new Domain;
                $domain->forceFill(['ulid' => $row['ulid'], 'name' => $row['name'], 'code' => $row['code'], 'subject_id' => $row['subject_id'], 'sequence' => $row['sequence'], 'is_active' => $row['is_active']]);
                $domain->save();
                $byUlid[$row['ulid']] = $domain->getKey();
                $created++;

                if ($row['parent_domain_ulid'] !== null) {
                    $pendingParents[$row['ulid']] = $row['parent_domain_ulid'];
                }
            } elseif (in_array($row['classification'], ['existing', 'conflict'], true) && isset($row['existing_id'])) {
                $byUlid[$row['ulid']] = (int) $row['existing_id'];
            }
        }

        foreach ($pendingParents as $childUlid => $parentUlid) {
            $parentId = $byUlid[$parentUlid] ?? null;

            if ($parentId !== null) {
                Domain::query()->whereKey($byUlid[$childUlid])->update(['parent_domain_id' => $parentId]);
            }
        }

        return ['byUlid' => $byUlid, 'created' => $created];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{byUlid: array<string, int>, createdIds: array<int, true>}
     */
    private function writeProfiles(array $rows): array
    {
        $byUlid = [];
        /** @var array<int, true> $createdIds */
        $createdIds = [];

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $profile = new AssessmentProfile;
                $profile->forceFill([
                    'ulid' => $row['ulid'], 'name' => $row['name'], 'description' => $row['description'],
                    'academic_year_id' => $row['academic_year_id'], 'subject_id' => $row['subject_id'],
                    'grade_level' => $row['grade_level'], 'is_institutional_template' => $row['is_institutional_template'],
                ]);
                $profile->save();
                $byUlid[$row['ulid']] = $profile->getKey();
                $createdIds[$profile->getKey()] = true;
            } elseif (in_array($row['classification'], ['existing', 'conflict'], true) && isset($row['existing_id'])) {
                $byUlid[$row['ulid']] = (int) $row['existing_id'];
            }
        }

        return ['byUlid' => $byUlid, 'createdIds' => $createdIds];
    }

    /**
     * A version is restored as a historical fact — including `is_current` —
     * never as a fresh activation decision this importer makes. Only a
     * NEWLY CREATED profile (`$newlyCreatedProfileIds`) ever has its
     * `current_version_id` pointer set here; an EXISTING profile's own
     * pointer is left untouched, since it may already reflect the
     * destination's own independent history. Same
     * `$profile->profile()->update(['current_version_id' => …])` call
     * `ActivateProfileVersion` already uses — `current_version_id` is
     * deliberately outside `AssessmentProfile`'s `#[Fillable]` allowlist
     * (request input should never set it directly), but trusted
     * application code updating it explicitly is exactly what that
     * allowlist exists to distinguish from.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $profilesByUlid
     * @param  array<int, true>  $newlyCreatedProfileIds
     * @param  array<string, int>  $scalesByRef
     * @return array{byUlid: array<string, int>, created: int}
     */
    private function writeProfileVersions(array $rows, array $profilesByUlid, array $newlyCreatedProfileIds, array $scalesByRef): array
    {
        $byUlid = [];
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $profileId = $this->resolveId($row['profile_ulid'], $profilesByUlid);

                if ($profileId === null) {
                    continue;
                }

                $version = new AssessmentProfileVersion;
                $version->forceFill([
                    'ulid' => $row['ulid'], 'assessment_profile_id' => $profileId, 'version_number' => $row['version_number'],
                    'status' => $row['status'], 'scale_id' => $this->resolveScaleId($row['scale'], $scalesByRef),
                    'domain_weight_mode' => $row['domain_weight_mode'], 'period_result_mode' => $row['period_result_mode'],
                    'accumulated_mode' => $row['accumulated_mode'], 'absence_mode' => $row['absence_mode'],
                    'rounding_mode' => $row['rounding_mode'], 'rounding_scale' => $row['rounding_scale'], 'rounding_stage' => $row['rounding_stage'],
                    'minimum_rules' => $row['minimum_rules'], 'change_note' => $row['change_note'],
                ]);
                $version->save();

                if ($row['activated_at'] !== null || $row['frozen_at'] !== null || $row['superseded_at'] !== null) {
                    $version->allowFrozenWrite = true;
                    $version->forceFill(['activated_at' => $row['activated_at'], 'frozen_at' => $row['frozen_at'], 'superseded_at' => $row['superseded_at']])->save();
                }

                $byUlid[$row['ulid']] = $version->getKey();
                $created++;

                if ($row['is_current'] && isset($newlyCreatedProfileIds[$profileId])) {
                    $version->profile()->update(['current_version_id' => $version->getKey()]);
                }
            } elseif (in_array($row['classification'], ['existing', 'conflict'], true) && isset($row['existing_id'])) {
                $byUlid[$row['ulid']] = (int) $row['existing_id'];
            }
        }

        return ['byUlid' => $byUlid, 'created' => $created];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $versionsByUlid
     * @param  array<string, int>  $domainsByUlid
     */
    private function writeProfileVersionDomains(array $rows, array $versionsByUlid, array $domainsByUlid): void
    {
        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $versionId = $this->resolveId($row['version_ulid'], $versionsByUlid);
            $domainId = $this->resolveId($row['domain_ulid'], $domainsByUlid);

            if ($versionId === null || $domainId === null) {
                continue;
            }

            $weight = new ProfileVersionDomain;
            $weight->forceFill([
                'assessment_profile_version_id' => $versionId, 'domain_id' => $domainId, 'weight_percent' => $row['weight_percent'],
                'sequence' => $row['sequence'], 'expected_element_count' => $row['expected_element_count'], 'minimum_element_count' => $row['minimum_element_count'],
            ]);
            $weight->save();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $versionsByUlid
     * @param  array<string, int>  $periodsByUlid
     */
    private function writeProfileVersionPeriods(array $rows, array $versionsByUlid, array $periodsByUlid): void
    {
        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $versionId = $this->resolveId($row['version_ulid'], $versionsByUlid);
            $periodId = $this->resolveId($row['academic_period_ulid'], $periodsByUlid);

            if ($versionId === null || $periodId === null) {
                continue;
            }

            $weight = new ProfileVersionPeriod;
            $weight->forceFill([
                'assessment_profile_version_id' => $versionId, 'academic_period_id' => $periodId, 'is_cumulative' => $row['is_cumulative'],
                'period_weight_percent' => $row['period_weight_percent'], 'contributes_to_accumulated' => $row['contributes_to_accumulated'],
            ]);
            $weight->save();
        }
    }
}
