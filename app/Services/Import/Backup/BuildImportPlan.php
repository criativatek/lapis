<?php

namespace App\Services\Import\Backup;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\Import\Backup\Concerns\ResolvesBackupReferences;
use App\Support\Retention\AcademicYearRetentionClassifier;
use Illuminate\Support\Collection;

/**
 * The import plan (§14 of the import brief): classifies every row a backup
 * would touch — new / existing / conflict / invalid / unsupported — without
 * writing anything. `ExecuteDataImport` consumes exactly this same plan and
 * only ever acts on rows classified `new`, so preview and execution can
 * never disagree about what would happen.
 *
 * Academic years and subjects are matched by label/name against the
 * DESTINATION organization, never created — a decision already made in
 * Fatia 6 and left untouched here (§90 of the Fatia 6.1 brief: "não abrir
 * decisões funcionais já tomadas").
 *
 * Fatia 6.1 (schema_version 4, docs/backup-schema.md) completes the
 * pedagogical restore that used to stop at classes/students/enrollments,
 * with instruments and classifications always `unsupported`. They are not
 * anymore. The classification logic for the new domains lives in three
 * collaborators, each covering one tier and run in dependency order —
 * split into separate classes purely because a single file this size made
 * PHPStan's own memory footprint too large to check reliably, not for any
 * architectural reason:
 *
 *   - `BuildAssessmentStructurePlan` — academic periods, scales, instrument
 *     types, domains, assessment profiles/versions and their weights. Runs
 *     BEFORE classes, since a class can reference a profile version.
 *   - `BuildAssessmentDataPlan` — elements/items/scores, the full
 *     classification record, self-assessments. Runs after classes/
 *     students/enrollments, which it references.
 *   - `BuildPedagogicalRecordsPlan` — interim assessments, pedagogical
 *     records (Registos), interventions (Estratégias e Medidas) and their
 *     reviews, finalized reports. Independent of the calculation engine;
 *     runs last.
 *
 * Three shapes of "restorable" recur throughout all three collaborators:
 *   - ulid-identified rows (a class, a scale, an instrument, a report, …):
 *     match by ulid in the destination, else check it belongs to another
 *     organization first (global ulid uniqueness, §10 of the Fatia 6.1
 *     brief — never a raw SQL error), else classify new/conflict by a
 *     business key.
 *   - flat child rows with no ulid of their own (profile_version_domains,
 *     item_domain_allocations, self_assessment_questions, …): always
 *     `new` once their parent(s) resolve, matched for idempotency by
 *     whatever natural key the database itself enforces.
 *   - system/reference rows (a system scale, a system instrument type):
 *     match-only by name/code, NEVER created — every database seeds its
 *     own copy of these with its own ulid, so ulid matching would never
 *     work across databases even for the exact same conceptual row (§12).
 *
 * Authorship (§30-31, `ResolvesBackupReferences::resolveAuthor()`): every
 * author-shaped field the backup carries is an email. The only safe,
 * non-inventive resolution is "this row's author is literally the person
 * confirming THIS import" — their own email, compared case-insensitively.
 * Anything else leaves a nullable author field empty (the fact survives,
 * the provenance doesn't) or, for a NOT NULL author column, blocks the row
 * as `invalid` rather than substituting anyone.
 */
class BuildImportPlan
{
    use ResolvesBackupReferences;

    public function __construct(
        private readonly AcademicYearRetentionClassifier $retentionClassifier,
        private readonly BuildAssessmentStructurePlan $structurePlan,
        private readonly BuildAssessmentDataPlan $dataPlan,
        private readonly BuildPedagogicalRecordsPlan $recordsPlan,
    ) {}

    /**
     * @param  array<string, mixed>  $canonical
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array{rows: array<string, array<int, array<string, mixed>>>, counts: array<string, array<string, int>>, can_confirm: bool}
     */
    public function build(array $canonical, Organization $destination, User $actor, array $rowIssues): array
    {
        $classesIn = $canonical['classes'] ?? [];
        $studentsIn = $canonical['students'] ?? [];
        $enrollmentsIn = $canonical['enrollments'] ?? [];

        $academicYears = $this->resolveAcademicYears($classesIn, $destination);
        $subjects = $this->resolveSubjects($classesIn, $destination);

        $structure = $this->structurePlan->build(
            $canonical['academic_periods'] ?? [],
            $canonical['scales'] ?? [],
            $canonical['instrument_types'] ?? [],
            $canonical['domains'] ?? [],
            $canonical['assessment_profiles'] ?? [],
            $canonical['assessment_profile_versions'] ?? [],
            $canonical['profile_version_domains'] ?? [],
            $canonical['profile_version_periods'] ?? [],
            $destination,
            $academicYears['byLabel'],
            $subjects['byName'],
        );

        $classRows = $this->classifyClasses($classesIn, $destination, $academicYears['byLabel'], $subjects['byName'], $structure['profileVersionsByUlid']);
        $classesByUlid = collect($classRows)->keyBy('ulid');

        $studentRows = $this->classifyStudents($studentsIn, $destination);
        $studentsByUlid = collect($studentRows)->keyBy('ulid');

        $enrollmentRows = $this->classifyEnrollments($enrollmentsIn, $destination, $classesByUlid, $studentsByUlid);
        $enrollmentsByUlid = collect($enrollmentRows)->keyBy('ulid');

        $data = $this->dataPlan->build(
            $canonical['instruments'] ?? [],
            $canonical['instrument_groups'] ?? [],
            $canonical['instrument_items'] ?? [],
            $canonical['item_domain_allocations'] ?? [],
            $canonical['student_item_scores'] ?? [],
            $canonical['classifications'] ?? [],
            $canonical['self_assessment_templates'] ?? [],
            $canonical['self_assessment_questions'] ?? [],
            $canonical['self_assessments'] ?? [],
            $canonical['self_assessment_responses'] ?? [],
            $destination,
            $actor,
            $classesByUlid,
            $enrollmentsByUlid,
            $structure['periodsByUlid'],
            $structure['domainsByUlid'],
            $structure['profileVersionsByUlid'],
            $structure['scaleResolution'],
            $structure['instrumentTypeResolution'],
        );

        $records = $this->recordsPlan->build(
            $canonical['interim_assessments'] ?? [],
            $canonical['evidence_records'] ?? [],
            $canonical['interventions'] ?? [],
            $canonical['intervention_reviews'] ?? [],
            $canonical['reports'] ?? [],
            $destination,
            $actor,
            $classesByUlid,
            $enrollmentsByUlid,
            $structure['periodsByUlid'],
            $structure['domainsByUlid'],
            $academicYears['byLabel'],
        );

        $issuesByDomain = collect($rowIssues)->groupBy('domain');

        $rows = array_merge(
            [
                'academic_years' => $academicYears['rows'],
                'subjects' => $subjects['rows'],
                'classes' => $classRows,
                'students' => $studentRows,
                'enrollments' => $enrollmentRows,
            ],
            $structure['rows'],
            $data['rows'],
            $records['rows'],
        );

        $noIssueDomains = ['academic_years', 'subjects', 'profile_version_domains', 'profile_version_periods', 'item_domain_allocations', 'student_item_scores', 'self_assessment_questions', 'self_assessment_responses'];

        foreach ($rows as $domain => $domainRows) {
            if (! in_array($domain, $noIssueDomains, true)) {
                $rows[$domain] = $this->appendRowIssues($domainRows, $issuesByDomain->get($domain, collect()));
            }
        }

        $counts = [];
        $hasNew = false;

        foreach ($rows as $domain => $domainRows) {
            $tally = ['new' => 0, 'existing' => 0, 'conflict' => 0, 'invalid' => 0, 'unsupported' => 0];

            foreach ($domainRows as $row) {
                $classification = (string) $row['classification'];
                $tally[$classification] = ($tally[$classification] ?? 0) + 1;

                if ($classification === 'new') {
                    $hasNew = true;
                }
            }

            $counts[$domain] = $tally;
        }

        return [
            'rows' => $rows,
            'counts' => $counts,
            'can_confirm' => $hasNew,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $classesIn
     * @return array{rows: array<int, array<string, mixed>>, byLabel: Collection<string, AcademicYear>}
     */
    private function resolveAcademicYears(array $classesIn, Organization $destination): array
    {
        $labels = collect($classesIn)->pluck('academic_year')->filter()->unique()->values();

        $existing = AcademicYear::query()
            ->where('organization_id', $destination->getKey())
            ->whereIn('label', $labels)
            ->get()
            ->keyBy('label');

        $currentYears = AcademicYear::query()->where('organization_id', $destination->getKey())->get();
        $currentYear = $this->retentionClassifier->currentYearFor($currentYears);
        $withinRetention = $currentYear === null
            ? collect()
            : $this->retentionClassifier->classify($currentYears, $currentYear)->keyBy(fn (array $c) => $c['year']->label);

        $rows = $labels->map(function (string $label) use ($existing, $withinRetention): array {
            $match = $existing->get($label);

            return [
                'label' => $label,
                'classification' => $match !== null ? 'existing' : 'invalid',
                'reason' => $match === null ? $this->t('O ano letivo «:label» ainda não existe nesta organização.', ['label' => $label]) : null,
                'within_retention' => $match !== null ? ($withinRetention->get($label)['within_retention'] ?? null) : null,
            ];
        })->values()->all();

        return ['rows' => $rows, 'byLabel' => $existing];
    }

    /**
     * @param  array<int, array<string, mixed>>  $classesIn
     * @return array{rows: array<int, array<string, mixed>>, byName: Collection<string, Subject>}
     */
    private function resolveSubjects(array $classesIn, Organization $destination): array
    {
        $names = collect($classesIn)->pluck('subject')->filter()->unique()->values();

        $existing = Subject::query()
            ->where('organization_id', $destination->getKey())
            ->whereIn('name', $names)
            ->get()
            ->groupBy('name');

        $rows = $names->map(function (string $name) use ($existing): array {
            $matches = $existing->get($name, collect());

            return [
                'name' => $name,
                'classification' => $matches->count() === 1 ? 'existing' : 'invalid',
                'reason' => match (true) {
                    $matches->count() === 1 => null,
                    $matches->count() > 1 => $this->t('Existe mais do que uma disciplina «:name» nesta organização — não é possível escolher automaticamente.', ['name' => $name]),
                    default => $this->t('A disciplina «:name» ainda não existe nesta organização.', ['name' => $name]),
                },
            ];
        })->values()->all();

        $byName = $existing->filter(fn (Collection $matches): bool => $matches->count() === 1)->map(fn (Collection $matches) => $matches->first());

        return ['rows' => $rows, 'byName' => $byName];
    }

    /**
     * @param  array<int, array<string, mixed>>  $classesIn
     * @param  Collection<string, AcademicYear>  $academicYearsByLabel
     * @param  Collection<string, Subject>  $subjectsByName
     * @param  Collection<string, array<string, mixed>>  $profileVersionsByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifyClasses(array $classesIn, Organization $destination, Collection $academicYearsByLabel, Collection $subjectsByName, Collection $profileVersionsByUlid): array
    {
        $ulids = collect($classesIn)->pluck('ulid');
        $existingByUlid = SchoolClass::query()->where('organization_id', $destination->getKey())->whereIn('ulid', $ulids)->get()->keyBy('ulid');
        $elsewhere = $this->ulidOrganizationsElsewhere(SchoolClass::class, $ulids, $destination->getKey());

        return collect($classesIn)->map(function (array $row) use ($academicYearsByLabel, $subjectsByName, $profileVersionsByUlid, $existingByUlid, $elsewhere, $destination): array {
            $existing = $existingByUlid->get($row['ulid']);
            $profileVersionUlid = $row['assessment_profile_version_ulid'] ?? null;
            $profileVersion = $profileVersionUlid !== null ? $profileVersionsByUlid->get($profileVersionUlid) : null;

            if ($existing !== null) {
                $diverges = $existing->label !== $row['label'] || $existing->status->value !== $row['status'];

                return [
                    'ulid' => $row['ulid'],
                    'label' => $row['label'],
                    'classification' => $diverges ? 'conflict' : 'existing',
                    'reason' => $diverges ? $this->t('Já existe uma turma com esta identidade, mas os dados diferem.') : null,
                    'existing_id' => $existing->getKey(),
                ];
            }

            if ($elsewhere->has($row['ulid'])) {
                return [
                    'ulid' => $row['ulid'],
                    'label' => $row['label'],
                    'classification' => 'invalid',
                    'reason' => $this->t('Esta turma pertence a outra organização e não pode ser restaurada aqui.'),
                ];
            }

            $academicYear = $academicYearsByLabel->get($row['academic_year']);
            $subject = $row['subject'] !== null ? $subjectsByName->get($row['subject']) : null;

            if ($academicYear === null || ($row['subject'] !== null && $subject === null)) {
                return [
                    'ulid' => $row['ulid'],
                    'label' => $row['label'],
                    'classification' => 'invalid',
                    'reason' => $academicYear === null
                        ? $this->t('O ano letivo «:label» ainda não existe nesta organização.', ['label' => $row['academic_year']])
                        : $this->t('A disciplina «:name» ainda não existe nesta organização.', ['name' => $row['subject']]),
                ];
            }

            $businessKeyConflict = SchoolClass::query()
                ->where('organization_id', $destination->getKey())
                ->where('academic_year_id', $academicYear->getKey())
                ->where('subject_id', $subject?->getKey())
                ->where('label', $row['label'])
                ->exists();

            if ($businessKeyConflict) {
                return [
                    'ulid' => $row['ulid'],
                    'label' => $row['label'],
                    'classification' => 'conflict',
                    'reason' => $this->t('Já existe uma turma com esta identidade, mas os dados diferem.'),
                ];
            }

            // A profile version that itself cannot be restored (invalid) is
            // not fatal to the class — a class without an assessment
            // profile is still a legitimate, common state in this app; the
            // teacher assigns one afterwards. Never inherited from a
            // profile version otherwise resolvable this same run.
            $profileVersionId = ($profileVersionUlid !== null && $profileVersion !== null && in_array($profileVersion['classification'], ['new', 'existing'], true))
                ? ($profileVersion['existing_id'] ?? null)
                : null;

            return [
                'ulid' => $row['ulid'],
                'label' => $row['label'],
                'classification' => 'new',
                'reason' => null,
                'academic_year_id' => $academicYear->getKey(),
                'subject_id' => $subject?->getKey(),
                'status' => $row['status'],
                'assessment_profile_version_ulid' => $profileVersionId === null ? $profileVersionUlid : null,
                'assessment_profile_version_id' => $profileVersionId,
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $studentsIn
     * @return array<int, array<string, mixed>>
     */
    private function classifyStudents(array $studentsIn, Organization $destination): array
    {
        $ulids = collect($studentsIn)->pluck('ulid');
        $existingByUlid = Student::query()->where('organization_id', $destination->getKey())->whereIn('ulid', $ulids)->with('identity')->get()->keyBy('ulid');
        $elsewhere = $this->ulidOrganizationsElsewhere(Student::class, $ulids, $destination->getKey());

        $codes = collect($studentsIn)->pluck('pseudonym_code');
        $existingByCode = Student::query()->where('organization_id', $destination->getKey())->whereIn('pseudonym_code', $codes)->get()->keyBy('pseudonym_code');

        return collect($studentsIn)->map(function (array $row) use ($existingByUlid, $existingByCode, $elsewhere): array {
            $existing = $existingByUlid->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->pseudonym_code !== $row['pseudonym_code']
                    || $existing->identity?->display_name !== $row['display_name'];

                return [
                    'ulid' => $row['ulid'],
                    'pseudonym_code' => $row['pseudonym_code'],
                    'classification' => $diverges ? 'conflict' : 'existing',
                    'reason' => $diverges ? $this->t('Já existe um aluno com esta identidade, mas os dados diferem.') : null,
                    'existing_id' => $existing->getKey(),
                ];
            }

            if ($elsewhere->has($row['ulid'])) {
                return [
                    'ulid' => $row['ulid'],
                    'pseudonym_code' => $row['pseudonym_code'],
                    'classification' => 'invalid',
                    'reason' => $this->t('Este aluno pertence a outra organização e não pode ser restaurado aqui.'),
                ];
            }

            $codeConflict = $existingByCode->get($row['pseudonym_code']);

            if ($codeConflict !== null) {
                return [
                    'ulid' => $row['ulid'],
                    'pseudonym_code' => $row['pseudonym_code'],
                    'classification' => 'conflict',
                    'reason' => $this->t('Já existe um aluno com esta identidade, mas os dados diferem.'),
                ];
            }

            return [
                'ulid' => $row['ulid'],
                'pseudonym_code' => $row['pseudonym_code'],
                'classification' => 'new',
                'reason' => null,
                'display_name' => $row['display_name'],
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $enrollmentsIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @param  Collection<string, array<string, mixed>>  $studentsByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifyEnrollments(array $enrollmentsIn, Organization $destination, Collection $classesByUlid, Collection $studentsByUlid): array
    {
        $ulids = collect($enrollmentsIn)->pluck('ulid');
        $existingByUlid = Enrollment::query()->where('organization_id', $destination->getKey())->whereIn('ulid', $ulids)->get()->keyBy('ulid');
        $elsewhere = $this->ulidOrganizationsElsewhere(Enrollment::class, $ulids, $destination->getKey());

        return collect($enrollmentsIn)->map(function (array $row) use ($existingByUlid, $classesByUlid, $studentsByUlid, $elsewhere): array {
            $existing = $existingByUlid->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->status->value !== $row['status'];

                return [
                    'ulid' => $row['ulid'],
                    'classification' => $diverges ? 'conflict' : 'existing',
                    'reason' => $diverges ? $this->t('Já existe uma inscrição com esta identidade, mas os dados diferem.') : null,
                    'existing_id' => $existing->getKey(),
                ];
            }

            if ($elsewhere->has($row['ulid'])) {
                return [
                    'ulid' => $row['ulid'],
                    'classification' => 'invalid',
                    'reason' => $this->t('Esta inscrição pertence a outra organização e não pode ser restaurada aqui.'),
                ];
            }

            $class = $classesByUlid->get($row['class_ulid']);
            $student = $studentsByUlid->get($row['student_ulid']);
            $classResolvable = $class !== null && in_array($class['classification'], ['new', 'existing'], true);
            $studentResolvable = $student !== null && in_array($student['classification'], ['new', 'existing'], true);

            if (! $classResolvable || ! $studentResolvable) {
                return [
                    'ulid' => $row['ulid'],
                    'classification' => 'invalid',
                    'reason' => $this->t('A turma ou o aluno desta inscrição não podem ser restaurados.'),
                ];
            }

            if ($row['enrolled_on'] === null) {
                return [
                    'ulid' => $row['ulid'],
                    'classification' => 'unsupported',
                    'reason' => $this->t('Este backup não inclui a data de inscrição — não é possível criar esta inscrição nesta versão.'),
                ];
            }

            return [
                'ulid' => $row['ulid'],
                'classification' => 'new',
                'reason' => null,
                'class_ulid' => $row['class_ulid'],
                'student_ulid' => $row['student_ulid'],
                'status' => $row['status'],
                'enrolled_on' => $row['enrolled_on'],
                'left_on' => $row['left_on'],
                'class_number' => $row['class_number'],
            ];
        })->values()->all();
    }
}
