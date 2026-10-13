<?php

namespace App\Services\Import\Backup;

use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\EvidenceRecord;
use App\Models\Organization;
use App\Models\RecordFollowupCase;
use App\Models\RecordFollowupCategory;
use App\Models\RecordFollowupConfirmation;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Import\Backup\Concerns\ResolvesBackupReferences;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Schema v15 — the plan tier for the logbook follow-up (docs/backup-schema.md,
 * «Acompanhamento dos registos»): cases and «Registado no Inovar»
 * declarations. Runs after classes, enrollments, periods and the logbook
 * records are classified, because a case points at the first three and a
 * declaration covers records.
 *
 * MAPPED, NEVER BY NUMERIC ID: every reference resolves through the plan rows
 * of this same restore (new or existing in the destination). A case whose
 * student does not belong to its class, or whose period is not of the class's
 * year, is refused — in the backup or, when both already exist, in the
 * destination.
 *
 * NEVER OVERWRITES. A case that already exists in the destination (same ulid,
 * or the same student × class × period × category) is kept as it is; a
 * different alert is reported as a conflict and the destination wins. A
 * declaration never stacks a second one in force on the same case.
 *
 * NEVER INVENTS AN AUTHOR. Who confirmed or undid is restored only when the
 * email is the account confirming this restore — the rule of every author
 * field in this importer (ResolvesBackupReferences::resolveAuthor()); otherwise
 * the declaration is restored without an author, with a notice.
 *
 * NEVER CLAIMS MORE THAN IT RESTORED. Each covered occurrence is mapped to a
 * record of the destination (already there, or created by this restore). An
 * occurrence whose record cannot be recovered — not in the backup, refused,
 * or deleted in the destination (never resurrected) — keeps its date without
 * a record, and the declaration is restored as INCOMPLETE, said in the preview
 * and on the case afterwards.
 *
 * NOTHING HERE ALERTS. The plan never runs the threshold: a restore brings the
 * cases as they were, with the original alert date, and creates none.
 */
final class BuildRecordFollowupPlan
{
    use ResolvesBackupReferences;

    /**
     * @param  list<array<string, mixed>>  $casesIn
     * @param  list<array<string, mixed>>  $confirmationsIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid  plan rows
     * @param  Collection<string, array<string, mixed>>  $enrollmentsByUlid  plan rows
     * @param  Collection<string, array<string, mixed>>  $periodsByUlid  plan rows
     * @param  array<string, mixed>  $canonical
     * @param  array<int, array<string, mixed>>  $evidenceRows  plan rows of `evidence_records`
     * @return array{rows: array{record_followup_cases: list<array<string, mixed>>, record_followup_confirmations: list<array<string, mixed>>}}
     */
    public function build(array $casesIn, array $confirmationsIn, Organization $destination, User $actor, Collection $classesByUlid, Collection $enrollmentsByUlid, Collection $periodsByUlid, array $canonical, array $evidenceRows): array
    {
        $caseRows = $this->classifyCases($casesIn, $destination, $classesByUlid, $enrollmentsByUlid, $periodsByUlid, $canonical);
        $confirmationRows = $this->classifyConfirmations($confirmationsIn, $destination, $actor, collect($caseRows)->keyBy('ulid'), $canonical, $evidenceRows);

        return ['rows' => ['record_followup_cases' => $caseRows, 'record_followup_confirmations' => $confirmationRows]];
    }

    /**
     * @param  list<array<string, mixed>>  $casesIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @param  Collection<string, array<string, mixed>>  $enrollmentsByUlid
     * @param  Collection<string, array<string, mixed>>  $periodsByUlid
     * @param  array<string, mixed>  $canonical
     * @return list<array<string, mixed>>
     */
    private function classifyCases(array $casesIn, Organization $destination, Collection $classesByUlid, Collection $enrollmentsByUlid, Collection $periodsByUlid, array $canonical): array
    {
        if ($casesIn === []) {
            return [];
        }

        $canonicalClasses = $this->keyed($canonical['classes'] ?? []);
        $canonicalEnrollments = $this->keyed($canonical['enrollments'] ?? []);
        $canonicalPeriods = $this->keyed($canonical['academic_periods'] ?? []);

        $ulids = collect($casesIn)->pluck('ulid');
        $existingByUlid = RecordFollowupCase::query()->where('organization_id', $destination->getKey())->whereIn('ulid', $ulids)->get()->keyBy('ulid');
        $elsewhere = $this->ulidOrganizationsElsewhere(RecordFollowupCase::class, $ulids, $destination->getKey());

        // Destination facts for references that already exist there — read
        // once for the whole plan, never per row.
        $classIds = collect($casesIn)->map(fn (array $row): ?int => $this->existingId($classesByUlid, $row['class_ulid']))->filter()->unique()->values();
        $enrollmentIds = collect($casesIn)->map(fn (array $row): ?int => $this->existingId($enrollmentsByUlid, $row['enrollment_ulid']))->filter()->unique()->values();
        $periodIds = collect($casesIn)->map(fn (array $row): ?int => $this->existingId($periodsByUlid, $row['academic_period_ulid']))->filter()->unique()->values();
        $classYears = $classIds->isEmpty() ? collect() : SchoolClass::query()->whereIn('id', $classIds)->pluck('academic_year_id', 'id');
        $enrollmentClasses = $enrollmentIds->isEmpty() ? collect() : Enrollment::query()->whereIn('id', $enrollmentIds)->pluck('class_id', 'id');
        $periodYears = $periodIds->isEmpty() ? collect() : AcademicPeriod::query()->whereIn('id', $periodIds)->pluck('academic_year_id', 'id');
        $byKey = $classIds->isEmpty() ? collect() : RecordFollowupCase::query()->whereIn('class_id', $classIds)->get()
            ->keyBy(fn (RecordFollowupCase $case): string => $this->caseKey($case->class_id, $case->enrollment_id, $case->academic_period_id, $case->category->value));

        return array_values(collect($casesIn)->map(function (array $row) use ($classesByUlid, $enrollmentsByUlid, $periodsByUlid, $canonicalClasses, $canonicalEnrollments, $canonicalPeriods, $existingByUlid, $elsewhere, $classYears, $enrollmentClasses, $periodYears, $byKey): array {
            $ulid = $row['ulid'];
            $label = $this->caseLabel($row, $canonicalClasses, $canonicalPeriods);
            $invalid = fn (string $reason): array => ['ulid' => $ulid, 'label' => $label, 'classification' => 'invalid', 'reason' => $this->t($reason)];

            if (! $this->resolvable($classesByUlid, $row['class_ulid']) || ! $this->resolvable($enrollmentsByUlid, $row['enrollment_ulid']) || ! $this->resolvable($periodsByUlid, $row['academic_period_ulid'])) {
                return $invalid('A turma, o aluno (inscrição) ou o período deste acompanhamento não podem ser restaurados.');
            }

            // Integrity in the backup itself.
            $canonicalEnrollment = $canonicalEnrollments->get($row['enrollment_ulid']);
            $canonicalClass = $canonicalClasses->get($row['class_ulid']);
            $canonicalPeriod = $canonicalPeriods->get($row['academic_period_ulid']);

            if (($canonicalEnrollment['class_ulid'] ?? null) !== $row['class_ulid']) {
                return $invalid('O aluno deste acompanhamento não pertence à turma indicada.');
            }

            if ($canonicalClass === null || $canonicalPeriod === null || ($canonicalClass['academic_year'] ?? null) !== ($canonicalPeriod['academic_year'] ?? null)) {
                return $invalid('O período deste acompanhamento não é do ano letivo da turma.');
            }

            $classId = $this->existingId($classesByUlid, $row['class_ulid']);
            $enrollmentId = $this->existingId($enrollmentsByUlid, $row['enrollment_ulid']);
            $periodId = $this->existingId($periodsByUlid, $row['academic_period_ulid']);

            // And in the destination, when both sides already exist there.
            if ($classId !== null && $enrollmentId !== null && (int) $enrollmentClasses->get($enrollmentId) !== $classId) {
                return $invalid('No destino, o aluno deste acompanhamento pertence a outra turma.');
            }

            if ($classId !== null && $periodId !== null && (int) $periodYears->get($periodId) !== (int) $classYears->get($classId)) {
                return $invalid('No destino, o período deste acompanhamento é de outro ano letivo.');
            }

            $key = $classId !== null && $enrollmentId !== null && $periodId !== null
                ? $this->caseKey($classId, $enrollmentId, $periodId, $row['category'])
                : null;

            $existing = $existingByUlid->get($ulid);

            if ($existing !== null) {
                $existingKey = $this->caseKey($existing->class_id, $existing->enrollment_id, $existing->academic_period_id, $existing->category->value);

                if ($key === null || $existingKey !== $key) {
                    return [
                        'ulid' => $ulid, 'label' => $label, 'classification' => 'conflict', 'existing_id' => $existing->getKey(), 'key_matches' => false,
                        'reason' => $this->t('Já existe um acompanhamento com esta identidade, de outro aluno, turma, período ou tipo. Não é reassociado.'),
                    ];
                }

                return $this->againstExisting($ulid, $label, $existing, $row);
            }

            if ($key !== null && $byKey->has($key)) {
                return $this->againstExisting($ulid, $label, $byKey->get($key), $row);
            }

            return [
                'ulid' => $ulid, 'label' => $label, 'classification' => 'new', 'reason' => null,
                'preserve_ulid' => ! $elsewhere->has($ulid),
                'class_ulid' => $row['class_ulid'],
                'enrollment_ulid' => $row['enrollment_ulid'],
                'academic_period_ulid' => $row['academic_period_ulid'],
                'category' => $row['category'],
                'alerted_at' => $row['alerted_at'],
                'alerted_count' => $row['alerted_count'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'notice' => null,
                // Where the case's records must already be, when its class and
                // student already exist in the destination (null = created by
                // this restore) — read by the declarations' occurrence check.
                'destination_class_id' => $classId,
                'destination_enrollment_id' => $enrollmentId,
            ];
        })->all());
    }

    /**
     * The same case already in the destination: identical alert ⇒ existing;
     * a different one ⇒ conflict, the destination kept. Either way the
     * declarations can attach to it (`key_matches`).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function againstExisting(string $ulid, string $label, RecordFollowupCase $existing, array $row): array
    {
        $sameAlert = $this->sameInstant($existing->alerted_at, $row['alerted_at']) && $existing->alerted_count === $row['alerted_count'];

        return [
            'ulid' => $ulid, 'label' => $label, 'classification' => $sameAlert ? 'existing' : 'conflict', 'existing_id' => $existing->getKey(), 'key_matches' => true,
            'reason' => $sameAlert ? null : $this->t('Este acompanhamento já existe no destino com outra informação de alerta. O destino é mantido; as marcações «Registado no Inovar» do backup são avaliadas à parte.'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $confirmationsIn
     * @param  Collection<string, array<string, mixed>>  $casesByUlid
     * @param  array<string, mixed>  $canonical
     * @param  array<int, array<string, mixed>>  $evidenceRows
     * @return list<array<string, mixed>>
     */
    private function classifyConfirmations(array $confirmationsIn, Organization $destination, User $actor, Collection $casesByUlid, array $canonical, array $evidenceRows): array
    {
        if ($confirmationsIn === []) {
            return [];
        }

        $canonicalCases = $this->keyed($canonical['record_followup_cases'] ?? []);
        $canonicalRecords = $this->keyed($canonical['evidence_records'] ?? []);
        $evidenceByUlid = collect($evidenceRows)->keyBy('ulid');

        $ulids = collect($confirmationsIn)->pluck('ulid');
        $existingByUlid = RecordFollowupConfirmation::query()->where('organization_id', $destination->getKey())->whereIn('ulid', $ulids)->get()->keyBy('ulid');
        $elsewhere = $this->ulidOrganizationsElsewhere(RecordFollowupConfirmation::class, $ulids, $destination->getKey());

        $attachedCaseIds = $casesByUlid->filter(fn (array $case): bool => isset($case['existing_id']) && ($case['key_matches'] ?? false))->pluck('existing_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $destinationConfirmations = $attachedCaseIds->isEmpty() ? collect() : RecordFollowupConfirmation::query()->whereIn('record_followup_case_id', $attachedCaseIds)->get();
        $inForceCaseIds = $destinationConfirmations->whereNull('undone_at')->pluck('record_followup_case_id')->map(fn ($id): int => (int) $id)->flip();
        $byInstant = $destinationConfirmations->keyBy(fn (RecordFollowupConfirmation $confirmation): string => $confirmation->record_followup_case_id.'|'.$confirmation->confirmed_at->format('Y-m-d H:i:s'));
        $destinationCases = $attachedCaseIds->isEmpty() ? collect() : RecordFollowupCase::query()->whereIn('id', $attachedCaseIds)->get()->keyBy('id');

        // Every record a declaration covers, as the destination holds it —
        // deleted ones included, so a deleted record is never taken as
        // recoverable (and never resurrected).
        $occurrenceUlids = collect($confirmationsIn)->flatMap(fn (array $row): array => array_column($row['occurrences'], 'ulid'))->filter()->unique()->values();
        $destinationRecords = $occurrenceUlids->isEmpty() ? collect() : EvidenceRecord::withTrashed()
            ->where('organization_id', $destination->getKey())->whereIn('ulid', $occurrenceUlids)->get()->keyBy('ulid');

        $claimedInForce = [];

        return array_values(collect($confirmationsIn)->map(function (array $row) use ($actor, $casesByUlid, $canonicalCases, $canonicalRecords, $evidenceByUlid, $existingByUlid, $elsewhere, $inForceCaseIds, $byInstant, $destinationCases, $destinationRecords, &$claimedInForce): array {
            $ulid = $row['ulid'];
            $case = $casesByUlid->get($row['case_ulid']);
            $label = $case['label'] ?? null;

            $attachable = $case !== null && (
                in_array($case['classification'], ['new', 'existing'], true)
                || ($case['classification'] === 'conflict' && ($case['key_matches'] ?? false))
            );

            if (! $attachable) {
                return ['ulid' => $ulid, 'label' => $label, 'classification' => 'invalid', 'reason' => $this->t('O acompanhamento desta marcação «Registado no Inovar» não pode ser restaurado (ver «Acompanhamento dos registos»).')];
            }

            $caseId = isset($case['existing_id']) ? (int) $case['existing_id'] : null;
            $existing = $existingByUlid->get($ulid);

            if ($existing !== null) {
                if ($caseId === null || (int) $existing->record_followup_case_id !== $caseId) {
                    return ['ulid' => $ulid, 'label' => $label, 'classification' => 'conflict', 'existing_id' => $existing->getKey(), 'reason' => $this->t('Já existe uma marcação com esta identidade, de outro acompanhamento. Não é reassociada.')];
                }

                return $this->confirmationAgainstExisting($ulid, $label, $existing, $row);
            }

            if ($caseId !== null) {
                $twin = $byInstant->get($caseId.'|'.Carbon::parse($row['confirmed_at'])->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s'));

                if ($twin !== null) {
                    return $this->confirmationAgainstExisting($ulid, $label, $twin, $row);
                }
            }

            $inForce = $row['undone_at'] === null;
            $caseSlot = $caseId !== null ? 'id:'.$caseId : 'ulid:'.$row['case_ulid'];

            if ($inForce && (($caseId !== null && $inForceCaseIds->has($caseId)) || isset($claimedInForce[$caseSlot]))) {
                return ['ulid' => $ulid, 'label' => $label, 'classification' => 'conflict', 'reason' => $this->t('Já há uma marcação «Registado no Inovar» em vigor para este caso no destino; não se cria uma segunda.')];
            }

            if ($inForce) {
                $claimedInForce[$caseSlot] = true;
            }

            // Which student and class the covered records must belong to:
            // the destination case's, or — for a case this restore creates —
            // the destination ids its class and student resolve to.
            $canonicalCase = $canonicalCases->get($row['case_ulid']);
            $destinationCase = $caseId === null ? null : $destinationCases->get($caseId);
            if ($destinationCase !== null) {
                $expectedClassId = $destinationCase->class_id;
                $expectedEnrollmentId = $destinationCase->enrollment_id;
            } else {
                $expectedClassId = isset($case['destination_class_id']) ? (int) $case['destination_class_id'] : null;
                $expectedEnrollmentId = isset($case['destination_enrollment_id']) ? (int) $case['destination_enrollment_id'] : null;
            }

            $occurrences = [];
            $missing = 0;

            foreach ($row['occurrences'] as $occurrence) {
                $via = $this->recoverOccurrence($occurrence['ulid'], $canonicalCase, $expectedClassId, $expectedEnrollmentId, $destinationRecords, $evidenceByUlid, $canonicalRecords);
                $occurrences[] = ['ulid' => $occurrence['ulid'], 'date' => $occurrence['date'], 'via' => $via];

                if ($via === null) {
                    $missing++;
                }
            }

            $confirmedBy = $this->resolveAuthor($row['confirmed_by_email'], $actor);
            $undoneBy = $row['undone_at'] === null ? null : $this->resolveAuthor($row['undone_by_email'], $actor);
            $authorUnresolved = ($row['confirmed_by_email'] !== null && $confirmedBy === null) || ($row['undone_at'] !== null && $row['undone_by_email'] !== null && $undoneBy === null);

            return [
                'ulid' => $ulid, 'label' => $label, 'classification' => 'new', 'reason' => null,
                'preserve_ulid' => ! $elsewhere->has($ulid),
                'case_ulid' => $row['case_ulid'],
                'confirmed_by' => $confirmedBy,
                'confirmed_at' => $row['confirmed_at'],
                'occurrence_count' => $row['occurrence_count'],
                'restore_occurrences' => $occurrences,
                'missing_occurrences' => $missing,
                'undone_by' => $undoneBy,
                'undone_at' => $row['undone_at'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'author_unresolved' => $authorUnresolved,
                'notice' => $authorUnresolved ? $this->unresolvedAuthorNotice('desta marcação «Registado no Inovar»') : null,
                'warning' => $missing === 0 ? null : $this->t('Esta marcação «Registado no Inovar» abrange ocorrências cujos registos não existem neste restauro (não vieram no backup, foram recusados ou foram eliminados no destino). É restaurada como incompleta, e o caso na consulta de registos indica as ocorrências não recuperadas.'),
            ];
        })->all());
    }

    /**
     * How one covered occurrence is found in the destination after this
     * restore: `existing` (the record is there, under the same ulid, live, of
     * the same student and class), `new` (this restore creates it), or null
     * (it cannot be recovered).
     *
     * A record already in the destination must still belong to the case's
     * class and student there: one moved to another student after the export
     * no longer is this case's occurrence. When the class or the student is
     * created by this restore, no record already in the destination can be
     * theirs.
     *
     * @param  array<mixed, mixed>|null  $canonicalCase
     * @param  Collection<string, EvidenceRecord>  $destinationRecords
     * @param  Collection<string, array<string, mixed>>  $evidenceByUlid
     * @param  Collection<string, array<mixed, mixed>>  $canonicalRecords
     */
    private function recoverOccurrence(?string $recordUlid, ?array $canonicalCase, ?int $expectedClassId, ?int $expectedEnrollmentId, Collection $destinationRecords, Collection $evidenceByUlid, Collection $canonicalRecords): ?string
    {
        if ($recordUlid === null) {
            return null;
        }

        $inDestination = $destinationRecords->get($recordUlid);

        if ($inDestination !== null) {
            if ($inDestination->trashed() || $expectedClassId === null || $expectedEnrollmentId === null) {
                return null;
            }

            return $inDestination->class_id === $expectedClassId && $inDestination->enrollment_id === $expectedEnrollmentId ? 'existing' : null;
        }

        if (($evidenceByUlid->get($recordUlid)['classification'] ?? null) !== 'new') {
            return null;
        }

        $record = $canonicalRecords->get($recordUlid);

        return $record !== null && $canonicalCase !== null
            && ($record['class_ulid'] ?? null) === ($canonicalCase['class_ulid'] ?? null)
            && ($record['enrollment_ulid'] ?? null) === ($canonicalCase['enrollment_ulid'] ?? null)
            ? 'new' : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function confirmationAgainstExisting(string $ulid, ?string $label, RecordFollowupConfirmation $existing, array $row): array
    {
        $same = $this->sameInstant($existing->confirmed_at, $row['confirmed_at'])
            && (($existing->undone_at === null) === ($row['undone_at'] === null));

        return [
            'ulid' => $ulid, 'label' => $label, 'classification' => $same ? 'existing' : 'conflict', 'existing_id' => $existing->getKey(),
            'reason' => $same ? null : $this->t('Esta marcação já existe no destino com outro estado (por exemplo, desfeita depois da exportação). O destino é mantido.'),
        ];
    }

    private function sameInstant(?\DateTimeInterface $existing, ?string $fromBackup): bool
    {
        if ($existing === null || $fromBackup === null) {
            return $existing === null && $fromBackup === null;
        }

        $zone = (string) config('app.timezone');

        return Carbon::instance($existing)->setTimezone($zone)->format('Y-m-d H:i:s') === Carbon::parse($fromBackup)->setTimezone($zone)->format('Y-m-d H:i:s');
    }

    /**
     * Whether a reference is restorable in this plan: `new` or `existing`.
     *
     * @param  Collection<string, array<string, mixed>>  $rows
     */
    private function resolvable(Collection $rows, mixed $ulid): bool
    {
        if (! is_string($ulid)) {
            return false;
        }

        $row = $rows->get($ulid);

        return $row !== null && in_array($row['classification'] ?? null, ['new', 'existing'], true);
    }

    /**
     * The destination id of a reference that already exists there, or null.
     *
     * @param  Collection<string, array<string, mixed>>  $rows
     */
    private function existingId(Collection $rows, mixed $ulid): ?int
    {
        $row = is_string($ulid) ? $rows->get($ulid) : null;

        return $row !== null && ($row['classification'] ?? null) === 'existing' && isset($row['existing_id']) ? (int) $row['existing_id'] : null;
    }

    private function caseKey(int $classId, int $enrollmentId, int $periodId, string $category): string
    {
        return "{$classId}:{$enrollmentId}:{$periodId}:{$category}";
    }

    /**
     * «Atrasos · 7.º A · 1.º Período» — enough to recognise the case in the
     * preview, never the student's name.
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<string, array<mixed, mixed>>  $canonicalClasses
     * @param  Collection<string, array<mixed, mixed>>  $canonicalPeriods
     */
    private function caseLabel(array $row, Collection $canonicalClasses, Collection $canonicalPeriods): string
    {
        return implode(' · ', array_filter([
            RecordFollowupCategory::tryFrom((string) $row['category'])?->label(),
            $canonicalClasses->get($row['class_ulid'])['label'] ?? null,
            $canonicalPeriods->get($row['academic_period_ulid'])['label'] ?? null,
        ]));
    }

    /**
     * @return Collection<string, array<mixed, mixed>>
     */
    private function keyed(mixed $rows): Collection
    {
        /** @var array<string, array<mixed, mixed>> $keyed */
        $keyed = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && is_string($row['ulid'] ?? null)) {
                    $keyed[$row['ulid']] = $row;
                }
            }
        }

        return collect($keyed);
    }
}
