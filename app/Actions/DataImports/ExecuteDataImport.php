<?php

namespace App\Actions\DataImports;

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
 */
class ExecuteDataImport
{
    public function __construct(
        private readonly BuildImportPlan $planner,
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
            $plan = $this->planner->build($canonical, $organization, []);

            $classModels = $this->writeClasses($plan['rows']['classes'], $organization, $actor);
            $studentModels = $this->writeStudents($plan['rows']['students'], $organization);
            $enrollmentSummary = $this->writeEnrollments($plan['rows']['enrollments'], $classModels, $studentModels);

            $summary = [
                'classes' => $this->tally($plan['rows']['classes'], $classModels['createdCount']),
                'students' => $this->tally($plan['rows']['students'], $studentModels['createdCount']),
                'enrollments' => $this->tally($plan['rows']['enrollments'], $enrollmentSummary['createdCount']),
                'instruments_unsupported' => $plan['counts']['instruments']['unsupported'] ?? 0,
                'classifications_unsupported' => $plan['counts']['classifications']['unsupported'] ?? 0,
                'classes_needing_reassignment' => $classModels['needingReassignment'],
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
     * @return array{byUlid: array<string, SchoolClass>, createdCount: int, needingReassignment: int}
     */
    private function writeClasses(array $rows, Organization $organization, User $actor): array
    {
        $byUlid = [];
        $created = 0;
        $needingReassignment = 0;

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $class = new SchoolClass;
                $class->forceFill([
                    'ulid' => $row['ulid'],
                    'academic_year_id' => $row['academic_year_id'],
                    'subject_id' => $row['subject_id'],
                    'label' => $row['label'],
                    'status' => $row['status'],
                ]);
                $class->save();

                if ($organization->isPersonal()) {
                    $class->teachers()->attach($actor, ['role' => 'owner']);
                } else {
                    $needingReassignment++;
                }

                $byUlid[$row['ulid']] = $class;
                $created++;
            } elseif ($row['classification'] === 'existing' && isset($row['existing_id'])) {
                $existing = SchoolClass::query()->whereKey((int) $row['existing_id'])->first();

                if ($existing !== null) {
                    $byUlid[$row['ulid']] = $existing;
                }
            }
        }

        return ['byUlid' => $byUlid, 'createdCount' => $created, 'needingReassignment' => $needingReassignment];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{byUlid: array<string, Student>, createdCount: int}
     */
    private function writeStudents(array $rows, Organization $organization): array
    {
        $byUlid = [];
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $student = new Student;
                $student->forceFill(['ulid' => $row['ulid'], 'pseudonym_code' => $row['pseudonym_code']]);
                $student->save();

                if ($row['display_name'] !== null) {
                    $student->identity()->create([
                        'organization_id' => $organization->getKey(),
                        'display_name' => $row['display_name'],
                    ]);
                }

                $byUlid[$row['ulid']] = $student;
                $created++;
            } elseif ($row['classification'] === 'existing' && isset($row['existing_id'])) {
                $existing = Student::query()->whereKey((int) $row['existing_id'])->first();

                if ($existing !== null) {
                    $byUlid[$row['ulid']] = $existing;
                }
            }
        }

        return ['byUlid' => $byUlid, 'createdCount' => $created];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{byUlid: array<string, SchoolClass>, createdCount: int, needingReassignment: int}  $classModels
     * @param  array{byUlid: array<string, Student>, createdCount: int}  $studentModels
     * @return array{createdCount: int}
     */
    private function writeEnrollments(array $rows, array $classModels, array $studentModels): array
    {
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $class = $classModels['byUlid'][$row['class_ulid']] ?? null;
            $student = $studentModels['byUlid'][$row['student_ulid']] ?? null;

            if ($class === null || $student === null) {
                continue;
            }

            $enrollment = new Enrollment;
            $enrollment->forceFill([
                'ulid' => $row['ulid'],
                'class_id' => $class->getKey(),
                'student_id' => $student->getKey(),
                'status' => $row['status'],
                'enrolled_on' => $row['enrolled_on'],
                'left_on' => $row['left_on'],
                'class_number' => $row['class_number'],
            ]);
            $enrollment->save();
            $created++;
        }

        return ['createdCount' => $created];
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
