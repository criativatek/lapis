<?php

namespace App\Services\Import\Backup;

use App\Models\EvidenceRecord;
use App\Models\InterimAssessment;
use App\Models\Intervention;
use App\Models\InterventionReview;
use App\Models\Organization;
use App\Models\Report;
use App\Models\User;
use App\Services\Import\Backup\Concerns\ResolvesBackupReferences;
use Illuminate\Support\Collection;

/**
 * The pedagogical-follow-up tier of the import plan (§14 of the import
 * brief, docs/backup-schema.md): interim assessments ("avaliações
 * intercalares"), pedagogical records ("Registos"), interventions
 * ("Estratégias e Medidas") and their reviews, and finalized reports. Split
 * out of `BuildImportPlan` purely to keep each file small enough for
 * PHPStan to analyse — see that class for the shared new/existing/
 * conflict/invalid vocabulary.
 *
 * None of these feed `BuildResultsProgression`/`ClassResultsCalculator` —
 * they are independent, parallel restorable domains, not calculation
 * inputs — so they run last, needing only classes/enrollments/periods/
 * domains already resolved by `BuildImportPlan` and
 * `BuildAssessmentStructurePlan`.
 */
class BuildPedagogicalRecordsPlan
{
    use ResolvesBackupReferences;

    /**
     * @param  array<int, array<string, mixed>>  $interimIn
     * @param  array<int, array<string, mixed>>  $evidenceIn
     * @param  array<int, array<string, mixed>>  $interventionsIn
     * @param  array<int, array<string, mixed>>  $interventionReviewsIn
     * @param  array<int, array<string, mixed>>  $reportsIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @param  Collection<string, array<string, mixed>>  $enrollmentsByUlid
     * @param  Collection<string, array<string, mixed>>  $periodsByUlid
     * @param  Collection<string, array<string, mixed>>  $domainsByUlid
     * @param  Collection<string, array<string, mixed>>  $academicYearsByLabel
     * @return array{rows: array<string, array<int, array<string, mixed>>>}
     */
    public function build(
        array $interimIn,
        array $evidenceIn,
        array $interventionsIn,
        array $interventionReviewsIn,
        array $reportsIn,
        Organization $destination,
        User $actor,
        Collection $classesByUlid,
        Collection $enrollmentsByUlid,
        Collection $periodsByUlid,
        Collection $domainsByUlid,
        Collection $academicYearsByLabel,
    ): array {
        $interimRows = $this->classifyInterimAssessments($interimIn, $destination, $classesByUlid, $periodsByUlid, $actor);
        $interimByUlid = collect($interimRows)->keyBy('ulid');

        $evidenceRows = $this->classifyEvidenceRecords($evidenceIn, $destination, $classesByUlid, $enrollmentsByUlid, $periodsByUlid, $domainsByUlid, $actor);

        $interventionRows = $this->classifyInterventions($interventionsIn, $destination, $classesByUlid, $enrollmentsByUlid, $periodsByUlid, $domainsByUlid, $actor);
        $interventionsByUlid = collect($interventionRows)->keyBy('ulid');

        $interventionReviewRows = $this->classifyInterventionReviews($interventionReviewsIn, $destination, $interventionsByUlid, $actor);

        $reportRows = $this->classifyReports($reportsIn, $destination, $classesByUlid, $enrollmentsByUlid, $academicYearsByLabel, $periodsByUlid, $interimByUlid, $actor);

        return [
            'rows' => [
                'interim_assessments' => $interimRows,
                'evidence_records' => $evidenceRows,
                'interventions' => $interventionRows,
                'intervention_reviews' => $interventionReviewRows,
                'reports' => $reportRows,
            ],
        ];
    }

    /**
     * `created_by` is NOT NULL on `interim_assessments` — an unmappable
     * author blocks the row entirely (§31 option 1) rather than inventing
     * one. The snapshot itself is copied verbatim, never recomputed: this
     * model is "written once and never updated" by design (§25).
     *
     * @param  array<int, array<string, mixed>>  $interimIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @param  Collection<string, array<string, mixed>>  $periodsByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifyInterimAssessments(array $interimIn, Organization $destination, Collection $classesByUlid, Collection $periodsByUlid, User $actor): array
    {
        $ulids = collect($interimIn)->pluck('ulid');
        $lookups = $this->ulidLookups(InterimAssessment::class, $ulids, $destination);
        $classIds = $classesByUlid->pluck('existing_id')->filter();
        $byBusinessKey = $classIds->isEmpty() ? collect() : InterimAssessment::query()->where('organization_id', $destination->getKey())->whereIn('class_id', $classIds)->whereIn('snapshot_hash', collect($interimIn)->pluck('snapshot_hash'))->get()
            ->keyBy(fn (InterimAssessment $interim): string => "{$interim->class_id}:{$interim->snapshot_hash}");

        return collect($interimIn)->map(function (array $row) use ($classesByUlid, $periodsByUlid, $actor, $lookups, $byBusinessKey): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                return ['ulid' => $row['ulid'], 'classification' => $existing->snapshot_hash === $row['snapshot_hash'] ? 'existing' : 'conflict', 'reason' => $existing->snapshot_hash === $row['snapshot_hash'] ? null : $this->conflictReason(), 'existing_id' => $existing->getKey()];
            }

            $class = $classesByUlid->get($row['class_ulid']);
            $classResolvable = $class !== null && in_array($class['classification'], ['new', 'existing'], true);
            $period = $periodsByUlid->get($row['academic_period_ulid']);
            $periodResolvable = $period !== null && in_array($period['classification'], ['new', 'existing'], true);
            $authorId = $this->resolveAuthor($row['created_by_email'], $actor);

            if (! $classResolvable || ! $periodResolvable) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A turma ou o período desta avaliação intercalar não podem ser restaurados.')];
            }

            if ($authorId === null) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A autoria desta avaliação intercalar não pode ser confirmada com segurança.')];
            }

            if ($lookups['elsewhere']->has($row['ulid']) && $class['classification'] === 'existing') {
                $match = $byBusinessKey->get("{$class['existing_id']}:{$row['snapshot_hash']}");

                if ($match !== null) {
                    return ['ulid' => $row['ulid'], 'classification' => $match->snapshot_hash === $row['snapshot_hash'] ? 'existing' : 'conflict', 'reason' => $match->snapshot_hash === $row['snapshot_hash'] ? null : $this->conflictReason(), 'existing_id' => $match->getKey()];
                }
            }

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null, 'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']), 'class_ulid' => $row['class_ulid'],
                'academic_period_ulid' => $row['academic_period_ulid'], 'name' => $row['name'], 'reference_date' => $row['reference_date'],
                'note' => $row['note'], 'snapshot_version' => $row['snapshot_version'], 'snapshot' => $row['snapshot'],
                'snapshot_hash' => $row['snapshot_hash'], 'created_by' => $authorId,
            ];
        })->values()->all();
    }

    /**
     * `created_by` is NOT NULL on `evidence_records` — same authorship
     * block as interim assessments. Blank stays blank throughout: every
     * type-specific field (homework_status, participation_level, …) is
     * carried through exactly as validated, never defaulted.
     *
     * @param  array<int, array<string, mixed>>  $recordsIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @param  Collection<string, array<string, mixed>>  $enrollmentsByUlid
     * @param  Collection<string, array<string, mixed>>  $periodsByUlid
     * @param  Collection<string, array<string, mixed>>  $domainsByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifyEvidenceRecords(array $recordsIn, Organization $destination, Collection $classesByUlid, Collection $enrollmentsByUlid, Collection $periodsByUlid, Collection $domainsByUlid, User $actor): array
    {
        $ulids = collect($recordsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(EvidenceRecord::class, $ulids, $destination);
        $classIds = $classesByUlid->pluck('existing_id')->filter();
        $candidates = $classIds->isEmpty() ? collect() : EvidenceRecord::query()->where('organization_id', $destination->getKey())->whereIn('class_id', $classIds)->get();

        return collect($recordsIn)->map(function (array $row) use ($classesByUlid, $enrollmentsByUlid, $periodsByUlid, $domainsByUlid, $actor, $lookups, $candidates): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                return ['ulid' => $row['ulid'], 'classification' => $existing->description === $row['description'] ? 'existing' : 'conflict', 'reason' => $existing->description === $row['description'] ? null : $this->conflictReason(), 'existing_id' => $existing->getKey()];
            }

            $class = $classesByUlid->get($row['class_ulid']);
            $classResolvable = $class !== null && in_array($class['classification'], ['new', 'existing'], true);
            $enrollment = $row['enrollment_ulid'] !== null ? $enrollmentsByUlid->get($row['enrollment_ulid']) : null;
            $enrollmentResolvable = $row['enrollment_ulid'] === null || ($enrollment !== null && in_array($enrollment['classification'], ['new', 'existing'], true));
            $period = $row['academic_period_ulid'] !== null ? $periodsByUlid->get($row['academic_period_ulid']) : null;
            $periodResolvable = $row['academic_period_ulid'] === null || ($period !== null && in_array($period['classification'], ['new', 'existing'], true));
            $domain = $row['domain_ulid'] !== null ? $domainsByUlid->get($row['domain_ulid']) : null;
            $domainResolvable = $row['domain_ulid'] === null || ($domain !== null && in_array($domain['classification'], ['new', 'existing'], true));
            $authorId = $this->resolveAuthor($row['created_by_email'], $actor);

            if (! $classResolvable || ! $enrollmentResolvable || ! $periodResolvable || ! $domainResolvable) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A turma, a inscrição, o período ou o domínio deste registo não podem ser restaurados.')];
            }

            if ($authorId === null) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A autoria deste registo não pode ser confirmada com segurança.')];
            }

            if ($lookups['elsewhere']->has($row['ulid']) && $class['classification'] === 'existing'
                && ($enrollment === null || $enrollment['classification'] === 'existing')
                && ($period === null || $period['classification'] === 'existing')
                && ($domain === null || $domain['classification'] === 'existing')) {
                $match = $candidates->first(fn (EvidenceRecord $record): bool => $record->class_id === $class['existing_id']
                    && $record->enrollment_id === ($enrollment['existing_id'] ?? null)
                    && $record->academic_period_id === ($period['existing_id'] ?? null)
                    && $record->domain_id === ($domain['existing_id'] ?? null)
                    && $record->kind->value === $row['kind'] && $record->occurred_at->toIso8601String() === $row['occurred_at']
                    && $record->description === $row['description']);

                if ($match !== null) {
                    return ['ulid' => $row['ulid'], 'classification' => $match->description === $row['description'] ? 'existing' : 'conflict', 'reason' => $match->description === $row['description'] ? null : $this->conflictReason(), 'existing_id' => $match->getKey()];
                }
            }

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null, 'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']), 'class_ulid' => $row['class_ulid'],
                'enrollment_ulid' => $row['enrollment_ulid'], 'academic_period_ulid' => $row['academic_period_ulid'],
                'domain_ulid' => $row['domain_ulid'], 'quick_rating_scale_level' => $row['quick_rating_scale_level'],
                'occurred_at' => $row['occurred_at'], 'kind' => $row['kind'],
                'description' => $row['description'], 'activity_include_in_report' => $row['activity_include_in_report'],
                'homework_status' => $row['homework_status'], 'participation_level' => $row['participation_level'],
                'activity_evaluation' => $row['activity_evaluation'], 'disciplinary_severity' => $row['disciplinary_severity'],
                'created_by' => $authorId,
            ];
        })->values()->all();
    }

    /**
     * `created_by` is NOT NULL on `interventions` — same authorship block.
     * `enrollment_id` (legacy single-student) and the participant pivot are
     * both restored, but only the ulids that resolve — an unresolvable
     * participant is dropped from the list rather than blocking the whole
     * intervention, mirroring how a malformed row degrades independently
     * elsewhere in this importer.
     *
     * @param  array<int, array<string, mixed>>  $interventionsIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @param  Collection<string, array<string, mixed>>  $enrollmentsByUlid
     * @param  Collection<string, array<string, mixed>>  $periodsByUlid
     * @param  Collection<string, array<string, mixed>>  $domainsByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifyInterventions(array $interventionsIn, Organization $destination, Collection $classesByUlid, Collection $enrollmentsByUlid, Collection $periodsByUlid, Collection $domainsByUlid, User $actor): array
    {
        $ulids = collect($interventionsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(Intervention::class, $ulids, $destination);
        $classIds = $classesByUlid->pluck('existing_id')->filter();
        $candidates = $classIds->isEmpty() ? collect() : Intervention::query()->where('organization_id', $destination->getKey())->whereIn('class_id', $classIds)->get();

        return collect($interventionsIn)->map(function (array $row) use ($classesByUlid, $enrollmentsByUlid, $periodsByUlid, $domainsByUlid, $actor, $lookups, $candidates): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                return ['ulid' => $row['ulid'], 'classification' => $existing->title === $row['title'] ? 'existing' : 'conflict', 'reason' => $existing->title === $row['title'] ? null : $this->conflictReason(), 'existing_id' => $existing->getKey()];
            }

            $class = $classesByUlid->get($row['class_ulid']);
            $classResolvable = $class !== null && in_array($class['classification'], ['new', 'existing'], true);
            $enrollment = $row['enrollment_ulid'] !== null ? $enrollmentsByUlid->get($row['enrollment_ulid']) : null;
            $enrollmentResolvable = $row['enrollment_ulid'] === null || ($enrollment !== null && in_array($enrollment['classification'], ['new', 'existing'], true));
            $period = $row['academic_period_ulid'] !== null ? $periodsByUlid->get($row['academic_period_ulid']) : null;
            $periodResolvable = $row['academic_period_ulid'] === null || ($period !== null && in_array($period['classification'], ['new', 'existing'], true));
            $domain = $row['domain_ulid'] !== null ? $domainsByUlid->get($row['domain_ulid']) : null;
            $domainResolvable = $row['domain_ulid'] === null || ($domain !== null && in_array($domain['classification'], ['new', 'existing'], true));
            $authorId = $this->resolveAuthor($row['created_by_email'], $actor);

            if (! $classResolvable || ! $enrollmentResolvable || ! $periodResolvable || ! $domainResolvable) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A turma, a inscrição, o período ou o domínio desta estratégia não podem ser restaurados.')];
            }

            if ($authorId === null) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A autoria desta estratégia não pode ser confirmada com segurança.')];
            }

            if ($lookups['elsewhere']->has($row['ulid']) && $class['classification'] === 'existing'
                && ($enrollment === null || $enrollment['classification'] === 'existing')
                && ($period === null || $period['classification'] === 'existing')
                && ($domain === null || $domain['classification'] === 'existing')) {
                $match = $candidates->first(fn (Intervention $intervention): bool => $intervention->class_id === $class['existing_id']
                    && $intervention->enrollment_id === ($enrollment['existing_id'] ?? null)
                    && $intervention->academic_period_id === ($period['existing_id'] ?? null)
                    && $intervention->domain_id === ($domain['existing_id'] ?? null)
                    && $intervention->target_type->value === $row['target_type'] && $intervention->title === $row['title']
                    && $intervention->description === $row['description'] && $intervention->started_on->toDateString() === $row['started_on']);

                if ($match !== null) {
                    return ['ulid' => $row['ulid'], 'classification' => $match->title === $row['title'] ? 'existing' : 'conflict', 'reason' => $match->title === $row['title'] ? null : $this->conflictReason(), 'existing_id' => $match->getKey()];
                }
            }

            $participantUlids = $row['participant_enrollment_ulids'];
            $resolvableParticipants = is_array($participantUlids)
                ? collect($participantUlids)
                    ->filter(fn (string $ulid): bool => in_array($enrollmentsByUlid->get($ulid)['classification'] ?? null, ['new', 'existing'], true))
                    ->values()->all()
                : [];

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null, 'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']), 'class_ulid' => $row['class_ulid'],
                'enrollment_ulid' => $row['enrollment_ulid'], 'participant_enrollment_ulids' => $resolvableParticipants,
                'academic_period_ulid' => $row['academic_period_ulid'], 'domain_ulid' => $row['domain_ulid'],
                'target_type' => $row['target_type'], 'intervention_type' => $row['intervention_type'], 'motive_code' => $row['motive_code'],
                'motive_label' => $row['motive_label'], 'strategy_code' => $row['strategy_code'], 'strategy_label' => $row['strategy_label'],
                'objective' => $row['objective'], 'domain_relation' => $row['domain_relation'], 'title' => $row['title'],
                'description' => $row['description'], 'description_source' => $row['description_source'], 'status' => $row['status'],
                'started_on' => $row['started_on'], 'expected_end_on' => $row['expected_end_on'], 'concluded_on' => $row['concluded_on'],
                'review_on' => $row['review_on'], 'available_for_reports' => $row['available_for_reports'],
                'support_measure_level' => $row['support_measure_level'], 'support_measure_code' => $row['support_measure_code'],
                'evaluation_adaptation_code' => $row['evaluation_adaptation_code'], 'legal_mapping_source' => $row['legal_mapping_source'],
                'created_by' => $authorId,
            ];
        })->values()->all();
    }

    /**
     * `reviewed_by` is NOT NULL on `intervention_reviews` — same
     * authorship block.
     *
     * @param  array<int, array<string, mixed>>  $reviewsIn
     * @param  Collection<string, array<string, mixed>>  $interventionsByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifyInterventionReviews(array $reviewsIn, Organization $destination, Collection $interventionsByUlid, User $actor): array
    {
        $ulids = collect($reviewsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(InterventionReview::class, $ulids, $destination);
        $interventionIds = $interventionsByUlid->pluck('existing_id')->filter();
        $candidates = $interventionIds->isEmpty() ? collect() : InterventionReview::query()->where('organization_id', $destination->getKey())->whereIn('intervention_id', $interventionIds)->get();

        return collect($reviewsIn)->map(function (array $row) use ($interventionsByUlid, $actor, $lookups, $candidates): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                return ['ulid' => $row['ulid'], 'classification' => $existing->reviewed_on->toDateString() === $row['reviewed_on'] ? 'existing' : 'conflict', 'reason' => $existing->reviewed_on->toDateString() === $row['reviewed_on'] ? null : $this->conflictReason(), 'existing_id' => $existing->getKey()];
            }

            $intervention = $interventionsByUlid->get($row['intervention_ulid']);
            $resolvable = $intervention !== null && in_array($intervention['classification'], ['new', 'existing'], true);
            $authorId = $this->resolveAuthor($row['reviewed_by_email'], $actor);

            if (! $resolvable) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A estratégia desta revisão não pode ser restaurada.')];
            }

            if ($authorId === null) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A autoria desta revisão não pode ser confirmada com segurança.')];
            }

            if ($lookups['elsewhere']->has($row['ulid']) && $intervention['classification'] === 'existing') {
                $match = $candidates->first(fn (InterventionReview $review): bool => $review->intervention_id === $intervention['existing_id']
                    && $review->reviewed_on->toDateString() === $row['reviewed_on'] && $review->effectiveness?->value === $row['effectiveness']
                    && $review->notes === $row['notes']);

                if ($match !== null) {
                    return ['ulid' => $row['ulid'], 'classification' => 'existing', 'reason' => null, 'existing_id' => $match->getKey()];
                }
            }

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null, 'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']), 'intervention_ulid' => $row['intervention_ulid'],
                'reviewed_on' => $row['reviewed_on'], 'effectiveness' => $row['effectiveness'], 'notes' => $row['notes'], 'reviewed_by' => $authorId,
            ];
        })->values()->all();
    }

    /**
     * `created_by` is NOT NULL on `reports` — same authorship block;
     * `finalized_by` is nullable and simply left empty when unmappable.
     * Only finalized reports ever reach this method (GenerateDataExport
     * never exports a draft) — `document`/`document_hash` are the frozen
     * artifact of record and are copied verbatim, never regenerated and
     * never re-run through AI (§28-29 of the import brief: there is none
     * to call).
     *
     * @param  array<int, array<string, mixed>>  $reportsIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @param  Collection<string, array<string, mixed>>  $enrollmentsByUlid
     * @param  Collection<string, array<string, mixed>>  $academicYearsByLabel
     * @param  Collection<string, array<string, mixed>>  $periodsByUlid
     * @param  Collection<string, array<string, mixed>>  $interimAssessmentsByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifyReports(array $reportsIn, Organization $destination, Collection $classesByUlid, Collection $enrollmentsByUlid, Collection $academicYearsByLabel, Collection $periodsByUlid, Collection $interimAssessmentsByUlid, User $actor): array
    {
        $ulids = collect($reportsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(Report::class, $ulids, $destination);
        $byUlidForSelfRef = collect($reportsIn)->keyBy('ulid');
        $byBusinessKey = Report::query()->where('organization_id', $destination->getKey())->whereIn('document_hash', collect($reportsIn)->pluck('document_hash'))->get()
            ->keyBy(fn (Report $report): string => ($report->class_id ?? 'null').":{$report->document_hash}");

        return collect($reportsIn)->map(function (array $row) use ($classesByUlid, $enrollmentsByUlid, $academicYearsByLabel, $periodsByUlid, $interimAssessmentsByUlid, $actor, $lookups, $byUlidForSelfRef, $byBusinessKey): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                return ['ulid' => $row['ulid'], 'classification' => $existing->document_hash === $row['document_hash'] ? 'existing' : 'conflict', 'reason' => $existing->document_hash === $row['document_hash'] ? null : $this->conflictReason(), 'existing_id' => $existing->getKey()];
            }

            $class = $row['class_ulid'] !== null ? $classesByUlid->get($row['class_ulid']) : null;
            $classResolvable = $row['class_ulid'] === null || ($class !== null && in_array($class['classification'], ['new', 'existing'], true));
            $enrollment = $row['enrollment_ulid'] !== null ? $enrollmentsByUlid->get($row['enrollment_ulid']) : null;
            $enrollmentResolvable = $row['enrollment_ulid'] === null || ($enrollment !== null && in_array($enrollment['classification'], ['new', 'existing'], true));
            $academicYear = $row['academic_year'] !== null ? $academicYearsByLabel->get($row['academic_year']) : null;
            $academicYearResolvable = $row['academic_year'] === null || ($academicYear !== null && in_array($academicYear['classification'], ['new', 'existing'], true));
            $period = $row['academic_period_ulid'] !== null ? $periodsByUlid->get($row['academic_period_ulid']) : null;
            $periodResolvable = $row['academic_period_ulid'] === null || ($period !== null && in_array($period['classification'], ['new', 'existing'], true));
            $interim = $row['interim_assessment_ulid'] !== null ? $interimAssessmentsByUlid->get($row['interim_assessment_ulid']) : null;
            $interimResolvable = $row['interim_assessment_ulid'] === null || ($interim !== null && in_array($interim['classification'], ['new', 'existing'], true));
            $authorId = $this->resolveAuthor($row['created_by_email'], $actor);

            if (! $classResolvable || ! $enrollmentResolvable || ! $academicYearResolvable || ! $periodResolvable || ! $interimResolvable) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A turma, a inscrição, o ano letivo, o período ou a avaliação intercalar deste relatório não podem ser restaurados.')];
            }

            if ($authorId === null) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A autoria deste relatório não pode ser confirmada com segurança.')];
            }

            if ($lookups['elsewhere']->has($row['ulid']) && ($class === null || $class['classification'] === 'existing')) {
                $match = $byBusinessKey->get(($class['existing_id'] ?? 'null').":{$row['document_hash']}");

                if ($match !== null) {
                    return ['ulid' => $row['ulid'], 'classification' => $match->document_hash === $row['document_hash'] ? 'existing' : 'conflict', 'reason' => $match->document_hash === $row['document_hash'] ? null : $this->conflictReason(), 'existing_id' => $match->getKey()];
                }
            }

            $basedOnUlid = $row['based_on_report_ulid'];
            $basedOn = $basedOnUlid !== null ? $byUlidForSelfRef->get($basedOnUlid) : null;

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null, 'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']), 'type' => $row['type'], 'title' => $row['title'],
                'tone' => $row['tone'], 'scope_kind' => $row['scope_kind'], 'scope_label' => $row['scope_label'],
                'starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on'], 'class_ulid' => $row['class_ulid'],
                'enrollment_ulid' => $row['enrollment_ulid'], 'academic_year_ulid' => $academicYear['ulid'] ?? null,
                'academic_year_id' => $academicYear['existing_id'] ?? null,
                'academic_period_ulid' => $row['academic_period_ulid'], 'interim_assessment_ulid' => $row['interim_assessment_ulid'],
                'document' => $row['document'], 'document_version' => $row['document_version'], 'document_hash' => $row['document_hash'],
                'finalized_at' => $row['finalized_at'], 'finalized_by' => $this->resolveAuthor($row['finalized_by_email'], $actor),
                'created_by' => $authorId, 'based_on_report_ulid' => $basedOn !== null ? $basedOnUlid : null,
                'template_key' => $row['template_key'], 'template_snapshot' => $row['template_snapshot'],
            ];
        })->values()->all();
    }
}
