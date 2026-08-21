<?php

namespace App\Actions\DataImports;

use App\Actions\DataImports\Concerns\ResolvesWrittenReferences;
use App\Models\DataImport;
use App\Models\DataImportStatus;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Import\Backup\BuildImportPlan;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes a validated backup into the destination organization — the one
 * place in this fatia that performs an insert (§6, §19). Runs the whole
 * write inside DB::transaction(): a failure partway through rolls back
 * everything from this run, never a half-restored class (§19, §64).
 *
 * The plan is rebuilt here, inside the transaction, against the row-locked
 * DataImport and a row-locked Organization — never trusted from an earlier
 * preview render. That is what makes running the same backup twice safe:
 * the second run classifies everything the first run created as `existing`
 * and writes nothing (§11, §44), and it is what closes the race of two
 * confirms of the same import arriving together (§53) — the second one
 * finds `canBeConfirmed() === false` under the same row lock and refuses.
 *
 * Only rows classified `new` by the plan are ever written. `existing`,
 * `conflict`, `invalid` and `unsupported` are all skipped — never merged,
 * never overwritten (§12).
 *
 * Fatia 6.1+ (schema_version 5, docs/backup-schema.md) delegates the
 * pedagogical tiers to three collaborators, run in the same dependency
 * order the plan itself resolves in — structure, then classes/students/
 * enrollments (unchanged from Fatia 6), then assessment data, then
 * pedagogical records. Split for the same reason `BuildImportPlan` is
 * split: one file this size made PHPStan's own memory footprint too large
 * to check reliably.
 */
class ExecuteDataImport
{
    use ResolvesWrittenReferences;

    public function __construct(
        private readonly BuildImportPlan $planner,
        private readonly WriteAssessmentStructure $structureWriter,
        private readonly WriteAssessmentData $dataWriter,
        private readonly WritePedagogicalRecords $recordsWriter,
        private readonly AuditLog $audit,
        private readonly CurrentOrganization $currentOrganization,
    ) {}

    public function execute(DataImport $import, User $actor): DataImport
    {
        return DB::transaction(function () use ($import, $actor): DataImport {
            $lockedImport = DataImport::query()->whereKey($import->getKey())->lockForUpdate()->firstOrFail();

            if (! $lockedImport->status->canBeConfirmed()) {
                throw new RuntimeException('Esta importação já não pode ser confirmada.');
            }

            $organization = Organization::query()->whereKey($lockedImport->organization_id)->lockForUpdate()->firstOrFail();
            $canonical = $lockedImport->canonical_snapshot ?? [];
            $plan = $this->planner->build($canonical, $organization, $actor, []);
            $rows = $plan['rows'];

            $structure = $this->structureWriter->write(
                $rows['academic_years'], $rows['subjects'],
                $rows['academic_periods'], $rows['scales'], $rows['instrument_types'], $rows['domains'],
                $rows['assessment_profiles'], $rows['assessment_profile_versions'],
                $rows['profile_version_domains'], $rows['profile_version_periods'],
                $organization,
            );

            $classModels = $this->writeClasses($rows['classes'], $organization, $actor, $structure['academicYearsByUlid'], $structure['subjectsByUlid'], $structure['profileVersionsByUlid']);
            $studentModels = $this->writeStudents($rows['students'], $organization);
            $enrollmentSummary = $this->writeEnrollments($rows['enrollments'], $classModels, $studentModels);

            $dataCounts = $this->dataWriter->write(
                $rows['instruments'], $rows['instrument_groups'], $rows['instrument_items'], $rows['item_domain_allocations'],
                $rows['student_item_scores'], $rows['classifications'], $rows['self_assessment_templates'],
                $rows['self_assessment_questions'], $rows['self_assessments'], $rows['self_assessment_responses'],
                $organization, $classModels['byUlid'], $enrollmentSummary['byUlid'], $structure['periodsByUlid'],
                $structure['domainsByUlid'], $structure['profileVersionsByUlid'], $structure['scalesByRef'],
                $structure['scaleLevelsByRef'], $structure['instrumentTypesByRef'],
            );

            $recordCounts = $this->recordsWriter->write(
                $rows['interim_assessments'], $rows['evidence_records'], $rows['interventions'],
                $rows['intervention_reviews'], $rows['reports'], $organization, $classModels['byUlid'],
                $enrollmentSummary['byUlid'], $structure['periodsByUlid'], $structure['domainsByUlid'],
                $structure['scalesByRef'], $structure['scaleLevelsByRef'], $structure['academicYearsByUlid'],
            );

            $summary = [
                'classes' => $this->tally($rows['classes'], $classModels['createdCount']),
                'students' => $this->tally($rows['students'], $studentModels['createdCount']),
                'enrollments' => $this->tally($rows['enrollments'], $enrollmentSummary['createdCount']),
                'classes_needing_reassignment' => $classModels['needingReassignment'],
                'academic_years_created' => $structure['createdCounts']['academic_years'],
                'subjects_created' => $structure['createdCounts']['subjects'],
                'academic_periods_created' => $structure['createdCounts']['academic_periods'],
                'scales_created' => $structure['createdCounts']['scales'],
                'instrument_types_created' => $structure['createdCounts']['instrument_types'],
                'domains_created' => $structure['createdCounts']['domains'],
                'assessment_profiles_created' => $structure['createdCounts']['assessment_profiles'],
                'assessment_profile_versions_created' => $structure['createdCounts']['assessment_profile_versions'],
                'instruments_created' => $dataCounts['instruments'],
                'instrument_items_created' => $dataCounts['instrument_items'],
                'student_item_scores_created' => $dataCounts['student_item_scores'],
                'classifications_created' => $dataCounts['classifications'],
                'self_assessments_created' => $dataCounts['self_assessments'],
                'interim_assessments_created' => $recordCounts['interim_assessments'],
                'evidence_records_created' => $recordCounts['evidence_records'],
                'interventions_created' => $recordCounts['interventions'],
                'reports_created' => $recordCounts['reports'],
            ];

            $lockedImport->forceFill([
                'status' => DataImportStatus::Imported,
                'confirmed_by' => $actor->getKey(),
                'confirmed_at' => now(),
                'summary' => $summary,
            ])->save();

            $this->currentOrganization->runFor($organization, fn () => $this->audit->record(
                'data_import.completed',
                $lockedImport,
                causer: $actor,
                summary: __('Foi importado um backup de dados.'),
                properties: [
                    'fingerprint' => $lockedImport->file_sha256 !== null ? substr($lockedImport->file_sha256, 0, 12) : null,
                    'source_schema_version' => $lockedImport->source_schema_version,
                    'created_count' => $summary['classes']['created'] + $summary['students']['created'] + $summary['enrollments']['created'],
                    'skipped_count' => $summary['classes']['skipped'] + $summary['students']['skipped'] + $summary['enrollments']['skipped'],
                    'conflict_count' => ($plan['counts']['classes']['conflict'] ?? 0) + ($plan['counts']['students']['conflict'] ?? 0) + ($plan['counts']['enrollments']['conflict'] ?? 0),
                ],
            ));

            return $lockedImport;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $academicYearsByUlid
     * @param  array<string, int>  $subjectsByUlid
     * @param  array<string, int>  $profileVersionsByUlid
     * @return array{byUlid: array<string, int>, createdCount: int, needingReassignment: int}
     */
    private function writeClasses(array $rows, Organization $organization, User $actor, array $academicYearsByUlid, array $subjectsByUlid, array $profileVersionsByUlid): array
    {
        $byUlid = [];
        $created = 0;
        $needingReassignment = 0;

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $profileVersionId = $row['assessment_profile_version_id'] ?? $this->resolveId($row['assessment_profile_version_ulid'] ?? null, $profileVersionsByUlid);

                $class = new SchoolClass;
                $class->forceFill([
                    'ulid' => $this->writableUlid($row),
                    'academic_year_id' => $row['academic_year_id'] ?? $this->resolveId($row['academic_year_ulid'] ?? null, $academicYearsByUlid),
                    'subject_id' => $row['subject_id'] ?? $this->resolveId($row['subject_ulid'] ?? null, $subjectsByUlid),
                    'label' => $row['label'],
                    'status' => $row['status'],
                    'assessment_profile_version_id' => $profileVersionId,
                ]);
                $class->save();

                if ($organization->isPersonal()) {
                    $class->teachers()->attach($actor, ['role' => 'owner']);
                } else {
                    $needingReassignment++;
                }

                $byUlid[$row['ulid']] = $class->getKey();
                $created++;
            } elseif ($row['classification'] === 'existing' && isset($row['existing_id'])) {
                $byUlid[$row['ulid']] = (int) $row['existing_id'];
            }
        }

        return ['byUlid' => $byUlid, 'createdCount' => $created, 'needingReassignment' => $needingReassignment];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{byUlid: array<string, int>, createdCount: int}
     */
    private function writeStudents(array $rows, Organization $organization): array
    {
        $byUlid = [];
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $student = new Student;
                $student->forceFill(['ulid' => $this->writableUlid($row), 'pseudonym_code' => $row['pseudonym_code']]);
                $student->save();

                if ($row['display_name'] !== null) {
                    $student->identity()->create([
                        'organization_id' => $organization->getKey(),
                        'display_name' => $row['display_name'],
                    ]);
                }

                $byUlid[$row['ulid']] = $student->getKey();
                $created++;
            } elseif ($row['classification'] === 'existing' && isset($row['existing_id'])) {
                $byUlid[$row['ulid']] = (int) $row['existing_id'];
            }
        }

        return ['byUlid' => $byUlid, 'createdCount' => $created];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{byUlid: array<string, int>, createdCount: int, needingReassignment: int}  $classModels
     * @param  array{byUlid: array<string, int>, createdCount: int}  $studentModels
     * @return array{byUlid: array<string, int>, createdCount: int}
     */
    private function writeEnrollments(array $rows, array $classModels, array $studentModels): array
    {
        $byUlid = [];
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $classId = $classModels['byUlid'][$row['class_ulid']] ?? null;
            $studentId = $studentModels['byUlid'][$row['student_ulid']] ?? null;

            if ($classId === null || $studentId === null) {
                continue;
            }

            $enrollment = new Enrollment;
            $enrollment->forceFill([
                'ulid' => $this->writableUlid($row),
                'class_id' => $classId,
                'student_id' => $studentId,
                'status' => $row['status'],
                'enrolled_on' => $row['enrolled_on'],
                'left_on' => $row['left_on'],
                'class_number' => $row['class_number'],
            ]);
            $enrollment->save();
            $byUlid[$row['ulid']] = $enrollment->getKey();
            $created++;
        }

        return ['byUlid' => $byUlid, 'createdCount' => $created];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created: int, skipped: int}
     */
    private function tally(array $rows, int $created): array
    {
        return ['created' => $created, 'skipped' => count($rows) - $created];
    }
}
