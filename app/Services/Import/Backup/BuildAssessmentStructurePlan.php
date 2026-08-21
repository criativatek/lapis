<?php

namespace App\Services\Import\Backup;

use App\Models\AcademicPeriod;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\Domain;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Services\Import\Backup\Concerns\ResolvesBackupReferences;
use Illuminate\Support\Collection;

/**
 * The assessment STRUCTURE tier of the import plan (§14 of the import
 * brief, docs/backup-schema.md): academic periods, scales, instrument
 * types, domains, assessment profiles/versions and their weights. Split
 * out of `BuildImportPlan` purely to keep each file small enough for
 * PHPStan to analyse — see that class for the shared new/existing/
 * conflict/invalid vocabulary and the ulid-vs-flat-child-row-vs-
 * system-reference classification shapes this file also follows.
 *
 * Runs BEFORE classes/students/enrollments: a class can reference an
 * assessment profile version, so the version has to already be resolved
 * (or classified invalid) by the time `BuildImportPlan` classifies classes.
 *
 * @phpstan-import-type ScaleResolution from ResolvesBackupReferences
 * @phpstan-import-type InstrumentTypeResolution from ResolvesBackupReferences
 */
class BuildAssessmentStructurePlan
{
    use ResolvesBackupReferences;

    /**
     * @param  array<int, array<string, mixed>>  $periodsIn
     * @param  array<int, array<string, mixed>>  $scalesIn
     * @param  array<int, array<string, mixed>>  $instrumentTypesIn
     * @param  array<int, array<string, mixed>>  $domainsIn
     * @param  array<int, array<string, mixed>>  $profilesIn
     * @param  array<int, array<string, mixed>>  $profileVersionsIn
     * @param  array<int, array<string, mixed>>  $profileVersionDomainsIn
     * @param  array<int, array<string, mixed>>  $profileVersionPeriodsIn
     * @param  Collection<string, array<string, mixed>>  $academicYearsByLabel
     * @param  Collection<string, array<string, mixed>>  $subjectsByName
     * @return array{rows: array<string, array<int, array<string, mixed>>>, periodsByUlid: Collection<string, array<string, mixed>>, domainsByUlid: Collection<string, array<string, mixed>>, profileVersionsByUlid: Collection<string, array<string, mixed>>, scaleResolution: ScaleResolution, instrumentTypeResolution: InstrumentTypeResolution}
     */
    public function build(
        array $periodsIn,
        array $scalesIn,
        array $instrumentTypesIn,
        array $domainsIn,
        array $profilesIn,
        array $profileVersionsIn,
        array $profileVersionDomainsIn,
        array $profileVersionPeriodsIn,
        Organization $destination,
        Collection $academicYearsByLabel,
        Collection $subjectsByName,
    ): array {
        $periodRows = $this->classifyAcademicPeriods($periodsIn, $destination, $academicYearsByLabel);
        $periodsByUlid = collect($periodRows)->keyBy('ulid');

        $scaleResolution = $this->classifyScales($scalesIn, $destination);

        $instrumentTypeResolution = $this->classifyInstrumentTypes($instrumentTypesIn, $destination);

        $domainResolution = $this->classifyDomains($domainsIn, $destination, $subjectsByName);
        $domainRows = $domainResolution['rows'];
        $domainsByUlid = collect($domainRows)->keyBy('ulid');

        $profileRows = $this->classifyProfiles($profilesIn, $destination, $academicYearsByLabel, $subjectsByName);
        $profilesByUlid = collect($profileRows)->keyBy('ulid');

        $profileVersionRows = $this->classifyProfileVersions($profileVersionsIn, $destination, $profilesByUlid, $scaleResolution);
        $profileVersionsByUlid = collect($profileVersionRows)->keyBy('ulid');

        $profileVersionDomainRows = $this->classifyProfileVersionDomains($profileVersionDomainsIn, $profileVersionsByUlid, $domainsByUlid);
        $profileVersionPeriodRows = $this->classifyProfileVersionPeriods($profileVersionPeriodsIn, $profileVersionsByUlid, $periodsByUlid);

        return [
            'rows' => [
                'academic_periods' => $periodRows,
                'scales' => $scaleResolution['rows'],
                'instrument_types' => $instrumentTypeResolution['rows'],
                'domains' => $domainRows,
                'assessment_profiles' => $profileRows,
                'assessment_profile_versions' => $profileVersionRows,
                'profile_version_domains' => $profileVersionDomainRows,
                'profile_version_periods' => $profileVersionPeriodRows,
            ],
            'periodsByUlid' => $periodsByUlid,
            'domainsByUlid' => $domainsByUlid,
            'profileVersionsByUlid' => $profileVersionsByUlid,
            'scaleResolution' => $scaleResolution,
            'instrumentTypeResolution' => $instrumentTypeResolution,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $periodsIn
     * @param  Collection<string, array<string, mixed>>  $academicYearsByLabel
     * @return array<int, array<string, mixed>>
     */
    private function classifyAcademicPeriods(array $periodsIn, Organization $destination, Collection $academicYearsByLabel): array
    {
        $ulids = collect($periodsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(AcademicPeriod::class, $ulids, $destination);
        $academicYearIds = $academicYearsByLabel->pluck('existing_id')->filter();
        $byBusinessKey = $academicYearIds->isEmpty() ? collect() : AcademicPeriod::query()
            ->where('organization_id', $destination->getKey())
            ->whereIn('academic_year_id', $academicYearIds)
            ->get()
            ->keyBy(fn (AcademicPeriod $period): string => "{$period->academic_year_id}:{$period->sequence}");

        return collect($periodsIn)->map(function (array $row) use ($destination, $academicYearsByLabel, $lookups, $byBusinessKey): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->label !== $row['label'] || $existing->kind->value !== $row['kind']
                    || $existing->starts_on->toDateString() !== $row['starts_on'] || $existing->ends_on->toDateString() !== $row['ends_on'];

                return [
                    'ulid' => $row['ulid'], 'label' => $row['label'],
                    'classification' => $diverges ? 'conflict' : 'existing',
                    'reason' => $diverges ? $this->conflictReason() : null,
                    'existing_id' => $existing->getKey(),
                ];
            }

            $academicYear = $academicYearsByLabel->get($row['academic_year']);
            $academicYearResolvable = $academicYear !== null && in_array($academicYear['classification'], ['new', 'existing'], true);

            if (! $academicYearResolvable) {
                return [
                    'ulid' => $row['ulid'], 'label' => $row['label'], 'classification' => 'invalid',
                    'reason' => $this->t('O ano letivo «:label» ainda não existe nesta organização.', ['label' => $row['academic_year']]),
                ];
            }

            $academicYearId = $academicYear['existing_id'] ?? null;

            if ($lookups['elsewhere']->has($row['ulid'])) {
                $match = $academicYearId !== null ? $byBusinessKey->get("{$academicYearId}:{$row['sequence']}") : null;

                if ($match !== null) {
                    $diverges = $match->label !== $row['label'] || $match->kind->value !== $row['kind']
                        || $match->starts_on->toDateString() !== $row['starts_on'] || $match->ends_on->toDateString() !== $row['ends_on'];

                    return ['ulid' => $row['ulid'], 'label' => $row['label'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $match->getKey()];
                }

                return [
                    'ulid' => $row['ulid'], 'label' => $row['label'], 'classification' => 'new', 'reason' => null, 'preserve_ulid' => false,
                    'academic_year_ulid' => $academicYear['ulid'], 'academic_year_id' => $academicYearId, 'kind' => $row['kind'], 'sequence' => $row['sequence'],
                    'starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on'], 'status' => $row['status'],
                ];
            }

            $businessKeyConflict = $academicYearId !== null && AcademicPeriod::query()
                ->where('organization_id', $destination->getKey())
                ->where('academic_year_id', $academicYearId)
                ->where('sequence', $row['sequence'])
                ->exists();

            if ($businessKeyConflict) {
                return ['ulid' => $row['ulid'], 'label' => $row['label'], 'classification' => 'conflict', 'reason' => $this->conflictReason()];
            }

            return [
                'ulid' => $row['ulid'], 'label' => $row['label'], 'classification' => 'new', 'reason' => null,
                'academic_year_ulid' => $academicYear['ulid'], 'academic_year_id' => $academicYearId, 'kind' => $row['kind'], 'sequence' => $row['sequence'],
                'starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on'], 'status' => $row['status'],
            ];
        })->values()->all();
    }

    /**
     * System scales are matched by NAME only and never created — every
     * database seeds its own three system scales independently
     * (SystemScalesSeeder), so their ulids never line up across databases
     * even for the exact same conceptual scale (§12 of the import brief).
     * Custom (org-owned) scales are ulid-restorable like any other row.
     *
     * @param  array<int, array<string, mixed>>  $scalesIn
     * @return ScaleResolution
     */
    private function classifyScales(array $scalesIn, Organization $destination): array
    {
        $systemNames = collect($scalesIn)->where('is_system', true)->pluck('name')->unique();
        $systemScales = $systemNames->isEmpty() ? collect() : Scale::query()->whereNull('organization_id')->whereIn('name', $systemNames)->with('levels')->get()->keyBy('name');

        $customUlids = collect($scalesIn)->where('is_system', false)->pluck('ulid');
        $lookups = $this->ulidLookups(Scale::class, $customUlids, $destination);
        $customByName = Scale::query()->where('organization_id', $destination->getKey())->whereIn('name', collect($scalesIn)->where('is_system', false)->pluck('name'))->with('levels')->get()->keyBy('name');
        $existingWithLevels = $lookups['existing']->isEmpty() ? collect() : Scale::query()->whereIn('id', $lookups['existing']->pluck('id'))->with('levels')->get()->keyBy('id');

        /** @var Collection<string, array{destination_id: int, levelCodes: array<string, true>}> $byRef */
        $byRef = collect();

        $rows = collect($scalesIn)->map(function (array $row) use ($systemScales, $lookups, $existingWithLevels, $customByName, $destination, &$byRef): array {
            $refKey = $row['is_system'] ? "system:{$row['name']}" : "custom:{$row['ulid']}";

            if ($row['is_system']) {
                $match = $systemScales->get($row['name']);

                if ($match === null) {
                    return ['ulid' => $row['ulid'], 'name' => $row['name'], 'is_system' => true, 'classification' => 'invalid', 'reason' => $this->t('A escala de sistema «:name» não existe nesta instalação.', ['name' => $row['name']])];
                }

                $byRef[$refKey] = ['destination_id' => (int) $match->getKey(), 'levelCodes' => $this->levelCodeMap($match->levels)];

                return ['ulid' => $row['ulid'], 'name' => $row['name'], 'is_system' => true, 'classification' => 'existing', 'reason' => null, 'existing_id' => $match->getKey()];
            }

            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->name !== $row['name'] || $existing->kind !== $row['kind'];
                $withLevels = $existingWithLevels->get($existing->id);
                $byRef[$refKey] = ['destination_id' => (int) $existing->getKey(), 'levelCodes' => $withLevels === null ? [] : $this->levelCodeMap($withLevels->levels)];

                return [
                    'ulid' => $row['ulid'], 'name' => $row['name'], 'is_system' => false,
                    'classification' => $diverges ? 'conflict' : 'existing',
                    'reason' => $diverges ? $this->conflictReason() : null,
                    'existing_id' => $existing->getKey(),
                ];
            }

            if ($lookups['elsewhere']->has($row['ulid'])) {
                $match = $customByName->get($row['name']);

                if ($match !== null) {
                    $diverges = $match->name !== $row['name'] || $match->kind !== $row['kind'];
                    $byRef[$refKey] = ['destination_id' => (int) $match->getKey(), 'levelCodes' => $this->levelCodeMap($match->levels)];

                    return ['ulid' => $row['ulid'], 'name' => $row['name'], 'is_system' => false, 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $match->getKey()];
                }

                $levelsIn = $row['levels'];
                $byRef[$refKey] = ['destination_id' => 0, 'levelCodes' => is_array($levelsIn) ? $this->levelCodeMapFromRows($levelsIn) : []];

                return ['ulid' => $row['ulid'], 'name' => $row['name'], 'is_system' => false, 'classification' => 'new', 'reason' => null, 'preserve_ulid' => false, 'kind' => $row['kind'], 'min_value' => $row['min_value'], 'max_value' => $row['max_value'], 'levels' => $row['levels']];
            }

            $businessKeyConflict = Scale::query()->where('organization_id', $destination->getKey())->where('name', $row['name'])->exists();

            if ($businessKeyConflict) {
                return ['ulid' => $row['ulid'], 'name' => $row['name'], 'is_system' => false, 'classification' => 'conflict', 'reason' => $this->conflictReason()];
            }

            $levelsIn = $row['levels'];
            $byRef[$refKey] = [
                'destination_id' => 0,
                'levelCodes' => is_array($levelsIn) ? $this->levelCodeMapFromRows($levelsIn) : [],
            ];

            return [
                'ulid' => $row['ulid'], 'name' => $row['name'], 'is_system' => false, 'classification' => 'new', 'reason' => null,
                'kind' => $row['kind'], 'min_value' => $row['min_value'], 'max_value' => $row['max_value'], 'levels' => $row['levels'],
            ];
        })->values()->all();

        return ['rows' => $rows, 'byRef' => $byRef];
    }

    /**
     * @param  Collection<int, ScaleLevel>  $levels
     * @return array<string, true>
     */
    private function levelCodeMap(Collection $levels): array
    {
        return array_fill_keys($levels->pluck('code')->map(fn (mixed $code): string => (string) $code)->all(), true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $levelsIn
     * @return array<string, true>
     */
    private function levelCodeMapFromRows(array $levelsIn): array
    {
        return array_fill_keys(array_map(fn (array $level): string => (string) $level['code'], $levelsIn), true);
    }

    /**
     * Same system-vs-custom split as scales: system instrument types are
     * match-only by code (InstrumentTypesSeeder), custom ones restorable
     * by ulid.
     *
     * @param  array<int, array<string, mixed>>  $typesIn
     * @return InstrumentTypeResolution
     */
    private function classifyInstrumentTypes(array $typesIn, Organization $destination): array
    {
        $systemCodes = collect($typesIn)->where('is_system', true)->pluck('code')->unique();
        $systemTypes = $systemCodes->isEmpty() ? collect() : InstrumentType::query()->whereNull('organization_id')->whereIn('code', $systemCodes)->get()->keyBy('code');

        $customUlids = collect($typesIn)->where('is_system', false)->pluck('ulid');
        $lookups = $this->ulidLookups(InstrumentType::class, $customUlids, $destination);
        $customByCode = InstrumentType::query()->where('organization_id', $destination->getKey())->whereIn('code', collect($typesIn)->where('is_system', false)->pluck('code'))->get()->keyBy('code');

        /** @var Collection<string, int> $byRef */
        $byRef = collect();

        $rows = collect($typesIn)->map(function (array $row) use ($systemTypes, $lookups, $customByCode, $destination, &$byRef): array {
            $refKey = $row['is_system'] ? "system:{$row['code']}" : "custom:{$row['ulid']}";

            if ($row['is_system']) {
                $match = $systemTypes->get($row['code']);

                if ($match === null) {
                    return ['ulid' => $row['ulid'], 'name' => $row['name'], 'code' => $row['code'], 'is_system' => true, 'classification' => 'invalid', 'reason' => $this->t('O tipo de elemento de sistema «:name» não existe nesta instalação.', ['name' => $row['name']])];
                }

                $byRef[$refKey] = $match->getKey();

                return ['ulid' => $row['ulid'], 'name' => $row['name'], 'code' => $row['code'], 'is_system' => true, 'classification' => 'existing', 'reason' => null, 'existing_id' => $match->getKey()];
            }

            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->name !== $row['name'] || $existing->code !== $row['code'];
                $byRef[$refKey] = $existing->getKey();

                return [
                    'ulid' => $row['ulid'], 'name' => $row['name'], 'is_system' => false,
                    'classification' => $diverges ? 'conflict' : 'existing',
                    'reason' => $diverges ? $this->conflictReason() : null,
                    'existing_id' => $existing->getKey(),
                ];
            }

            if ($lookups['elsewhere']->has($row['ulid'])) {
                $match = $customByCode->get($row['code']);

                if ($match !== null) {
                    $diverges = $match->name !== $row['name'] || $match->code !== $row['code'];
                    $byRef[$refKey] = $match->getKey();

                    return ['ulid' => $row['ulid'], 'name' => $row['name'], 'is_system' => false, 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $match->getKey()];
                }

                return ['ulid' => $row['ulid'], 'name' => $row['name'], 'is_system' => false, 'classification' => 'new', 'reason' => null, 'preserve_ulid' => false, 'code' => $row['code'], 'default_purpose' => $row['default_purpose'], 'is_active' => $row['is_active']];
            }

            $businessKeyConflict = InstrumentType::query()->where('organization_id', $destination->getKey())->where('code', $row['code'])->exists();

            if ($businessKeyConflict) {
                return ['ulid' => $row['ulid'], 'name' => $row['name'], 'is_system' => false, 'classification' => 'conflict', 'reason' => $this->conflictReason()];
            }

            return [
                'ulid' => $row['ulid'], 'name' => $row['name'], 'is_system' => false, 'classification' => 'new', 'reason' => null,
                'code' => $row['code'], 'default_purpose' => $row['default_purpose'], 'is_active' => $row['is_active'],
            ];
        })->values()->all();

        return ['rows' => $rows, 'byRef' => $byRef];
    }

    /**
     * @param  array<int, array<string, mixed>>  $domainsIn
     * @param  Collection<string, array<string, mixed>>  $subjectsByName
     * @return array{rows: array<int, array<string, mixed>>}
     */
    private function classifyDomains(array $domainsIn, Organization $destination, Collection $subjectsByName): array
    {
        $ulids = collect($domainsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(Domain::class, $ulids, $destination);
        $byBusinessKey = Domain::query()->where('organization_id', $destination->getKey())->whereIn('code', collect($domainsIn)->pluck('code'))->get()
            ->keyBy(fn (Domain $domain): string => ($domain->subject_id ?? 'null').":{$domain->code}");

        $rows = collect($domainsIn)->map(function (array $row) use ($subjectsByName, $lookups, $destination, $byBusinessKey): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->name !== $row['name'] || $existing->code !== $row['code'];

                return [
                    'ulid' => $row['ulid'], 'name' => $row['name'],
                    'classification' => $diverges ? 'conflict' : 'existing',
                    'reason' => $diverges ? $this->conflictReason() : null,
                    'existing_id' => $existing->getKey(), 'parent_domain_ulid' => $row['parent_domain_ulid'],
                ];
            }

            $subject = $row['subject'] !== null ? $subjectsByName->get($row['subject']) : null;
            $subjectResolvable = $row['subject'] === null || ($subject !== null && in_array($subject['classification'], ['new', 'existing'], true));

            if (! $subjectResolvable) {
                return [
                    'ulid' => $row['ulid'], 'name' => $row['name'], 'classification' => 'invalid',
                    'reason' => $this->t('A disciplina «:name» ainda não existe nesta organização.', ['name' => $row['subject']]),
                    'parent_domain_ulid' => $row['parent_domain_ulid'],
                ];
            }

            $subjectId = $subject['existing_id'] ?? null;

            if ($lookups['elsewhere']->has($row['ulid'])) {
                $match = ($row['subject'] === null || $subjectId !== null) ? $byBusinessKey->get(($subjectId ?? 'null').":{$row['code']}") : null;

                if ($match !== null) {
                    $diverges = $match->name !== $row['name'] || $match->code !== $row['code'];

                    return ['ulid' => $row['ulid'], 'name' => $row['name'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $match->getKey(), 'parent_domain_ulid' => $row['parent_domain_ulid']];
                }

                return ['ulid' => $row['ulid'], 'name' => $row['name'], 'classification' => 'new', 'reason' => null, 'preserve_ulid' => false, 'code' => $row['code'], 'subject_ulid' => $subject['ulid'] ?? null, 'subject_id' => $subjectId, 'sequence' => $row['sequence'], 'is_active' => $row['is_active'], 'parent_domain_ulid' => $row['parent_domain_ulid']];
            }

            $businessKeyConflict = ($row['subject'] === null || $subjectId !== null)
                && Domain::query()->where('organization_id', $destination->getKey())->where('subject_id', $subjectId)->where('code', $row['code'])->exists();

            if ($businessKeyConflict) {
                return ['ulid' => $row['ulid'], 'name' => $row['name'], 'classification' => 'conflict', 'reason' => $this->conflictReason(), 'parent_domain_ulid' => $row['parent_domain_ulid']];
            }

            return [
                'ulid' => $row['ulid'], 'name' => $row['name'], 'classification' => 'new', 'reason' => null,
                'code' => $row['code'], 'subject_ulid' => $subject['ulid'] ?? null, 'subject_id' => $subjectId, 'sequence' => $row['sequence'], 'is_active' => $row['is_active'],
                'parent_domain_ulid' => $row['parent_domain_ulid'],
            ];
        })->values()->all();

        return ['rows' => $rows];
    }

    /**
     * @param  array<int, array<string, mixed>>  $profilesIn
     * @param  Collection<string, array<string, mixed>>  $academicYearsByLabel
     * @param  Collection<string, array<string, mixed>>  $subjectsByName
     * @return array<int, array<string, mixed>>
     */
    private function classifyProfiles(array $profilesIn, Organization $destination, Collection $academicYearsByLabel, Collection $subjectsByName): array
    {
        $ulids = collect($profilesIn)->pluck('ulid');
        $lookups = $this->ulidLookups(AssessmentProfile::class, $ulids, $destination);
        $byBusinessKey = AssessmentProfile::query()->where('organization_id', $destination->getKey())->whereIn('name', collect($profilesIn)->pluck('name'))->get()
            ->keyBy(fn (AssessmentProfile $profile): string => ($profile->academic_year_id ?? 'null').':'.($profile->subject_id ?? 'null').":{$profile->grade_level}:{$profile->name}");

        return collect($profilesIn)->map(function (array $row) use ($academicYearsByLabel, $subjectsByName, $lookups, $byBusinessKey): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->name !== $row['name'];

                return [
                    'ulid' => $row['ulid'], 'name' => $row['name'],
                    'classification' => $diverges ? 'conflict' : 'existing',
                    'reason' => $diverges ? $this->conflictReason() : null,
                    'existing_id' => $existing->getKey(),
                ];
            }

            $academicYear = $row['academic_year'] !== null ? $academicYearsByLabel->get($row['academic_year']) : null;
            $subject = $row['subject'] !== null ? $subjectsByName->get($row['subject']) : null;
            $academicYearResolvable = $row['academic_year'] === null || ($academicYear !== null && in_array($academicYear['classification'], ['new', 'existing'], true));
            $subjectResolvable = $row['subject'] === null || ($subject !== null && in_array($subject['classification'], ['new', 'existing'], true));

            if (! $academicYearResolvable || ! $subjectResolvable) {
                return [
                    'ulid' => $row['ulid'], 'name' => $row['name'], 'classification' => 'invalid',
                    'reason' => ! $academicYearResolvable
                        ? $this->t('O ano letivo «:label» ainda não existe nesta organização.', ['label' => (string) $row['academic_year']])
                        : $this->t('A disciplina «:name» ainda não existe nesta organização.', ['name' => (string) $row['subject']]),
                ];
            }

            if ($lookups['elsewhere']->has($row['ulid'])) {
                $academicYearId = $academicYear['existing_id'] ?? null;
                $subjectId = $subject['existing_id'] ?? null;
                $key = ($academicYearId ?? 'null').':'.($subjectId ?? 'null').":{$row['grade_level']}:{$row['name']}";
                $match = (($row['academic_year'] === null || $academicYearId !== null) && ($row['subject'] === null || $subjectId !== null)) ? $byBusinessKey->get($key) : null;

                if ($match !== null) {
                    $diverges = $match->name !== $row['name'];

                    return ['ulid' => $row['ulid'], 'name' => $row['name'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $match->getKey()];
                }
            }

            return [
                'ulid' => $row['ulid'], 'name' => $row['name'], 'classification' => 'new', 'reason' => null,
                'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']),
                'description' => $row['description'], 'academic_year_ulid' => $academicYear['ulid'] ?? null, 'academic_year_id' => $academicYear['existing_id'] ?? null,
                'subject_ulid' => $subject['ulid'] ?? null, 'subject_id' => $subject['existing_id'] ?? null,
                'grade_level' => $row['grade_level'], 'is_institutional_template' => $row['is_institutional_template'],
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $versionsIn
     * @param  Collection<string, array<string, mixed>>  $profilesByUlid
     * @param  ScaleResolution  $scaleResolution
     * @return array<int, array<string, mixed>>
     */
    private function classifyProfileVersions(array $versionsIn, Organization $destination, Collection $profilesByUlid, array $scaleResolution): array
    {
        $ulids = collect($versionsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(AssessmentProfileVersion::class, $ulids, $destination);
        $profileIds = $profilesByUlid->pluck('existing_id')->filter();
        $byBusinessKey = $profileIds->isEmpty() ? collect() : AssessmentProfileVersion::query()->where('organization_id', $destination->getKey())->whereIn('assessment_profile_id', $profileIds)->get()
            ->keyBy(fn (AssessmentProfileVersion $version): string => "{$version->assessment_profile_id}:{$version->version_number}");

        return collect($versionsIn)->map(function (array $row) use ($profilesByUlid, $scaleResolution, $lookups, $byBusinessKey): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->version_number !== $row['version_number'] || $existing->status->value !== $row['status'];

                return [
                    'ulid' => $row['ulid'],
                    'classification' => $diverges ? 'conflict' : 'existing',
                    'reason' => $diverges ? $this->conflictReason() : null,
                    'existing_id' => $existing->getKey(), 'is_current' => $row['is_current'],
                ];
            }

            $profile = $profilesByUlid->get($row['profile_ulid']);
            $profileResolvable = $profile !== null && in_array($profile['classification'], ['new', 'existing'], true);
            $scale = $this->resolveScaleRef($row['scale'], $scaleResolution);

            if (! $profileResolvable || ! $scale['resolvable']) {
                return [
                    'ulid' => $row['ulid'], 'classification' => 'invalid',
                    'reason' => ! $profileResolvable
                        ? $this->t('O perfil de avaliação desta versão não pode ser restaurado.')
                        : $this->t('A escala desta versão não pode ser restaurada.'),
                    'is_current' => $row['is_current'],
                ];
            }

            $businessKeyMatch = $byBusinessKey->get(($profile['existing_id'] ?? 'null').":{$row['version_number']}");

            if ($businessKeyMatch !== null) {
                if ($lookups['elsewhere']->has($row['ulid'])) {
                    $diverges = $businessKeyMatch->version_number !== $row['version_number'] || $businessKeyMatch->status->value !== $row['status'];

                    return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $businessKeyMatch->getKey(), 'is_current' => $row['is_current']];
                }

                return ['ulid' => $row['ulid'], 'classification' => 'conflict', 'reason' => $this->conflictReason(), 'is_current' => $row['is_current']];
            }

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null,
                'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']),
                'profile_ulid' => $row['profile_ulid'], 'version_number' => $row['version_number'], 'status' => $row['status'],
                'is_current' => $row['is_current'], 'scale' => $row['scale'],
                'domain_weight_mode' => $row['domain_weight_mode'], 'period_result_mode' => $row['period_result_mode'],
                'accumulated_mode' => $row['accumulated_mode'], 'absence_mode' => $row['absence_mode'],
                'rounding_mode' => $row['rounding_mode'], 'rounding_scale' => $row['rounding_scale'], 'rounding_stage' => $row['rounding_stage'],
                'minimum_rules' => $row['minimum_rules'], 'activated_at' => $row['activated_at'], 'frozen_at' => $row['frozen_at'],
                'superseded_at' => $row['superseded_at'], 'change_note' => $row['change_note'],
            ];
        })->values()->all();
    }

    /**
     * No ulid of its own — always `new` once its parents resolve, matched
     * for idempotency by the same (version, domain) pair the database
     * itself enforces as unique.
     *
     * @param  array<int, array<string, mixed>>  $rowsIn
     * @param  Collection<string, array<string, mixed>>  $versionsByUlid
     * @param  Collection<string, array<string, mixed>>  $domainsByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifyProfileVersionDomains(array $rowsIn, Collection $versionsByUlid, Collection $domainsByUlid): array
    {
        return collect($rowsIn)->map(function (array $row) use ($versionsByUlid, $domainsByUlid): array {
            $version = $versionsByUlid->get($row['version_ulid']);
            $domain = $domainsByUlid->get($row['domain_ulid']);
            $versionResolvable = $version !== null && in_array($version['classification'], ['new', 'existing'], true);
            $domainResolvable = $domain !== null && in_array($domain['classification'], ['new', 'existing'], true);

            if (! $versionResolvable || ! $domainResolvable) {
                return ['classification' => 'invalid', 'reason' => $this->t('A versão de perfil ou o domínio deste peso não podem ser restaurados.')];
            }

            if ($version['classification'] === 'existing') {
                return ['classification' => 'existing', 'reason' => null];
            }

            return [
                'classification' => 'new', 'reason' => null, 'version_ulid' => $row['version_ulid'], 'domain_ulid' => $row['domain_ulid'],
                'weight_percent' => $row['weight_percent'], 'sequence' => $row['sequence'],
                'expected_element_count' => $row['expected_element_count'], 'minimum_element_count' => $row['minimum_element_count'],
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rowsIn
     * @param  Collection<string, array<string, mixed>>  $versionsByUlid
     * @param  Collection<string, array<string, mixed>>  $periodsByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifyProfileVersionPeriods(array $rowsIn, Collection $versionsByUlid, Collection $periodsByUlid): array
    {
        return collect($rowsIn)->map(function (array $row) use ($versionsByUlid, $periodsByUlid): array {
            $version = $versionsByUlid->get($row['version_ulid']);
            $period = $periodsByUlid->get($row['academic_period_ulid']);
            $versionResolvable = $version !== null && in_array($version['classification'], ['new', 'existing'], true);
            $periodResolvable = $period !== null && in_array($period['classification'], ['new', 'existing'], true);

            if (! $versionResolvable || ! $periodResolvable) {
                return ['classification' => 'invalid', 'reason' => $this->t('A versão de perfil ou o período deste peso não podem ser restaurados.')];
            }

            if ($version['classification'] === 'existing') {
                return ['classification' => 'existing', 'reason' => null];
            }

            return [
                'classification' => 'new', 'reason' => null, 'version_ulid' => $row['version_ulid'], 'academic_period_ulid' => $row['academic_period_ulid'],
                'is_cumulative' => $row['is_cumulative'], 'period_weight_percent' => $row['period_weight_percent'],
                'contributes_to_accumulated' => $row['contributes_to_accumulated'],
            ];
        })->values()->all();
    }
}
