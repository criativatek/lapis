<?php

namespace App\Services\Import\Backup;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
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
 * DESTINATION organization, never created. The backup carries them only as
 * flat strings on each class row (no starts_on/ends_on for a year, no code
 * for a subject) — inventing dates or a code to satisfy a create would be
 * exactly the "dados inexistentes" §3 forbids. A class whose year or
 * subject is missing in the destination is classified `invalid`: the
 * teacher configures it first (Configuração → Estrutura do Ano Letivo),
 * then re-runs the same backup, which is naturally idempotent.
 *
 * Instruments and classifications are always `unsupported`: the current
 * backup format does not carry the foreign keys (instrument_type_id,
 * academic_period_id, assessment_profile_version_id) a valid row needs —
 * inventing them would be worse than not restoring them. They are still
 * counted and shown, never silently dropped from the teacher's view.
 */
class BuildImportPlan
{
    public function __construct(private readonly AcademicYearRetentionClassifier $retentionClassifier) {}

    /**
     * @param  array<string, mixed>  $canonical
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array{rows: array<string, array<int, array<string, mixed>>>, counts: array<string, array<string, int>>, can_confirm: bool}
     */
    public function build(array $canonical, Organization $destination, array $rowIssues): array
    {
        $classesIn = $canonical['classes'] ?? [];
        $studentsIn = $canonical['students'] ?? [];
        $enrollmentsIn = $canonical['enrollments'] ?? [];
        $instrumentsIn = $canonical['instruments'] ?? [];
        $classificationsIn = $canonical['classifications'] ?? [];

        $academicYears = $this->resolveAcademicYears($classesIn, $destination);
        $subjects = $this->resolveSubjects($classesIn, $destination);

        $classRows = $this->classifyClasses($classesIn, $destination, $academicYears['byLabel'], $subjects['byName']);
        $classesByUlid = collect($classRows)->keyBy('ulid');

        $studentRows = $this->classifyStudents($studentsIn, $destination);
        $studentsByUlid = collect($studentRows)->keyBy('ulid');

        $enrollmentRows = $this->classifyEnrollments($enrollmentsIn, $destination, $classesByUlid, $studentsByUlid);

        $instrumentRows = $this->classifyUnsupported($instrumentsIn, 'instruments');
        $classificationRows = $this->classifyUnsupported($classificationsIn, 'classifications');

        $issuesByDomain = collect($rowIssues)->groupBy('domain');

        $rows = [
            'academic_years' => $academicYears['rows'],
            'subjects' => $subjects['rows'],
            'classes' => $this->appendRowIssues($classRows, $issuesByDomain->get('classes', collect())),
            'students' => $this->appendRowIssues($studentRows, $issuesByDomain->get('students', collect())),
            'enrollments' => $this->appendRowIssues($enrollmentRows, $issuesByDomain->get('enrollments', collect())),
            'instruments' => $this->appendRowIssues($instrumentRows, $issuesByDomain->get('instruments', collect())),
            'classifications' => $this->appendRowIssues($classificationRows, $issuesByDomain->get('classifications', collect())),
        ];

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
     * A translated string, never the string|array union __() is typed to
     * return — every call site here passes a literal key with placeholders,
     * which always resolves to a string.
     *
     * @param  array<string, string>  $replace
     */
    private function t(string $key, array $replace = []): string
    {
        return (string) __($key, $replace);
    }

    /**
     * `ulid` is unique across the WHOLE table, not per-organization — the
     * database will refuse a second row with a ulid another organization's
     * row already holds. Checked up front, cross-tenant on purpose
     * (`withoutGlobalScope`, the same legitimate pattern already used for
     * admin reports), so this surfaces as an `invalid` row in the preview
     * instead of a raw constraint violation at write time.
     *
     * @param  class-string<SchoolClass|Student|Enrollment>  $modelClass
     * @param  Collection<int, string>  $ulids
     * @return Collection<string, int>
     */
    private function ulidOrganizationsElsewhere(string $modelClass, Collection $ulids, int $destinationOrganizationId): Collection
    {
        return $modelClass::query()
            ->withoutGlobalScope('organization')
            ->whereIn('ulid', $ulids)
            ->where('organization_id', '!=', $destinationOrganizationId)
            ->pluck('organization_id', 'ulid');
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
     * @return array<int, array<string, mixed>>
     */
    private function classifyClasses(array $classesIn, Organization $destination, Collection $academicYearsByLabel, Collection $subjectsByName): array
    {
        $ulids = collect($classesIn)->pluck('ulid');
        $existingByUlid = SchoolClass::query()->where('organization_id', $destination->getKey())->whereIn('ulid', $ulids)->get()->keyBy('ulid');
        $elsewhere = $this->ulidOrganizationsElsewhere(SchoolClass::class, $ulids, $destination->getKey());

        return collect($classesIn)->map(function (array $row) use ($destination, $academicYearsByLabel, $subjectsByName, $existingByUlid, $elsewhere): array {
            $existing = $existingByUlid->get($row['ulid']);

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

            return [
                'ulid' => $row['ulid'],
                'label' => $row['label'],
                'classification' => 'new',
                'reason' => null,
                'academic_year_id' => $academicYear->getKey(),
                'subject_id' => $subject?->getKey(),
                'status' => $row['status'],
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

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  iterable<array{domain: string, ulid: string|null, reason: string}>  $issues
     * @return array<int, array<string, mixed>>
     */
    private function appendRowIssues(array $rows, iterable $issues): array
    {
        foreach ($issues as $issue) {
            $rows[] = ['ulid' => $issue['ulid'], 'classification' => 'invalid', 'reason' => $issue['reason']];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rowsIn
     * @return array<int, array<string, mixed>>
     */
    private function classifyUnsupported(array $rowsIn, string $reasonDomain): array
    {
        $reason = $reasonDomain === 'instruments'
            ? $this->t('Este backup não inclui os campos necessários (tipo, período letivo) para restaurar elementos de avaliação nesta versão.')
            : $this->t('Este backup não inclui os campos necessários (período letivo, versão do perfil de avaliação) para restaurar classificações nesta versão.');

        return collect($rowsIn)->map(fn (array $row): array => [
            'ulid' => $row['ulid'],
            'classification' => 'unsupported',
            'reason' => $reason,
        ])->values()->all();
    }
}
