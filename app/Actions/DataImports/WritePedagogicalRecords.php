<?php

namespace App\Actions\DataImports;

use App\Actions\DataImports\Concerns\ResolvesWrittenReferences;
use App\Models\EvidenceRecord;
use App\Models\InterimAssessment;
use App\Models\Intervention;
use App\Models\InterventionReview;
use App\Models\Organization;
use App\Models\Report;

/**
 * Writes the pedagogical-follow-up tier a validated backup's plan already
 * classified: interim assessments ("avaliações intercalares"), pedagogical
 * records ("Registos"), interventions ("Estratégias e Medidas") and their
 * reviews, finalized reports. Split out of `ExecuteDataImport` purely to
 * keep each file small enough for PHPStan to analyse.
 *
 * Only rows classified `new` by the plan are ever written (§12 of the
 * import brief). `document`/`document_hash` on a report and `snapshot`/
 * `snapshot_hash` on an interim assessment are copied verbatim — frozen
 * artifacts of record, never regenerated and never re-run through AI
 * (§28-29: there is none to call).
 */
class WritePedagogicalRecords
{
    use ResolvesWrittenReferences;

    /**
     * @param  array<int, array<string, mixed>>  $interimRows
     * @param  array<int, array<string, mixed>>  $evidenceRows
     * @param  array<int, array<string, mixed>>  $interventionRows
     * @param  array<int, array<string, mixed>>  $interventionReviewRows
     * @param  array<int, array<string, mixed>>  $reportRows
     * @param  array<string, int>  $classesByUlid
     * @param  array<string, int>  $enrollmentsByUlid
     * @param  array<string, int>  $periodsByUlid
     * @param  array<string, int>  $domainsByUlid
     * @param  array<string, int>  $scalesByRef
     * @param  array<string, int>  $scaleLevelsByRef
     * @return array<string, int>
     */
    public function write(
        array $interimRows,
        array $evidenceRows,
        array $interventionRows,
        array $interventionReviewRows,
        array $reportRows,
        Organization $organization,
        array $classesByUlid,
        array $enrollmentsByUlid,
        array $periodsByUlid,
        array $domainsByUlid,
        array $scalesByRef,
        array $scaleLevelsByRef,
    ): array {
        ['byUlid' => $interimByUlid, 'created' => $interimCreated] = $this->writeInterimAssessments($interimRows, $classesByUlid, $periodsByUlid);
        $evidenceCreated = $this->writeEvidenceRecords($evidenceRows, $classesByUlid, $enrollmentsByUlid, $periodsByUlid, $domainsByUlid, $scalesByRef, $scaleLevelsByRef);
        ['byUlid' => $interventionsByUlid, 'created' => $interventionsCreated] = $this->writeInterventions($interventionRows, $classesByUlid, $enrollmentsByUlid, $periodsByUlid, $domainsByUlid);
        $reviewsCreated = $this->writeInterventionReviews($interventionReviewRows, $interventionsByUlid);
        $reportsCreated = $this->writeReports($reportRows, $classesByUlid, $enrollmentsByUlid, $periodsByUlid, $interimByUlid);

        return [
            'interim_assessments' => $interimCreated,
            'evidence_records' => $evidenceCreated,
            'interventions' => $interventionsCreated,
            'intervention_reviews' => $reviewsCreated,
            'reports' => $reportsCreated,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $classesByUlid
     * @param  array<string, int>  $periodsByUlid
     * @return array{byUlid: array<string, int>, created: int}
     */
    private function writeInterimAssessments(array $rows, array $classesByUlid, array $periodsByUlid): array
    {
        $byUlid = [];
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $classId = $this->resolveId($row['class_ulid'], $classesByUlid);
                $periodId = $this->resolveId($row['academic_period_ulid'], $periodsByUlid);

                if ($classId === null || $periodId === null) {
                    continue;
                }

                $interim = new InterimAssessment;
                $interim->forceFill([
                    'ulid' => $this->writableUlid($row), 'class_id' => $classId, 'academic_period_id' => $periodId, 'name' => $row['name'],
                    'reference_date' => $row['reference_date'], 'note' => $row['note'], 'snapshot_version' => $row['snapshot_version'],
                    'snapshot' => $row['snapshot'], 'snapshot_hash' => $row['snapshot_hash'], 'created_by' => $row['created_by'],
                    'created_at' => now(),
                ]);
                $interim->save();
                $byUlid[$row['ulid']] = $interim->getKey();
                $created++;
            } elseif (in_array($row['classification'], ['existing', 'conflict'], true) && isset($row['existing_id'])) {
                $byUlid[$row['ulid']] = (int) $row['existing_id'];
            }
        }

        return ['byUlid' => $byUlid, 'created' => $created];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $classesByUlid
     * @param  array<string, int>  $enrollmentsByUlid
     * @param  array<string, int>  $periodsByUlid
     * @param  array<string, int>  $domainsByUlid
     * @param  array<string, int>  $scalesByRef
     * @param  array<string, int>  $scaleLevelsByRef
     */
    private function writeEvidenceRecords(array $rows, array $classesByUlid, array $enrollmentsByUlid, array $periodsByUlid, array $domainsByUlid, array $scalesByRef, array $scaleLevelsByRef): int
    {
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $classId = $this->resolveId($row['class_ulid'], $classesByUlid);

            if ($classId === null) {
                continue;
            }

            $record = new EvidenceRecord;
            $record->forceFill([
                'ulid' => $this->writableUlid($row), 'class_id' => $classId, 'enrollment_id' => $this->resolveId($row['enrollment_ulid'], $enrollmentsByUlid),
                'academic_period_id' => $this->resolveId($row['academic_period_ulid'], $periodsByUlid),
                'domain_id' => $this->resolveId($row['domain_ulid'], $domainsByUlid),
                'quick_rating_scale_level_id' => $this->resolveScaleLevelId($row['quick_rating_scale_level'], $scalesByRef, $scaleLevelsByRef),
                'occurred_at' => $row['occurred_at'], 'kind' => $row['kind'], 'description' => $row['description'],
                'activity_include_in_report' => $row['activity_include_in_report'], 'homework_status' => $row['homework_status'],
                'participation_level' => $row['participation_level'], 'activity_evaluation' => $row['activity_evaluation'],
                'disciplinary_severity' => $row['disciplinary_severity'], 'created_by' => $row['created_by'],
            ]);
            $record->save();
            $created++;
        }

        return $created;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $classesByUlid
     * @param  array<string, int>  $enrollmentsByUlid
     * @param  array<string, int>  $periodsByUlid
     * @param  array<string, int>  $domainsByUlid
     * @return array{byUlid: array<string, int>, created: int}
     */
    private function writeInterventions(array $rows, array $classesByUlid, array $enrollmentsByUlid, array $periodsByUlid, array $domainsByUlid): array
    {
        $byUlid = [];
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $classId = $this->resolveId($row['class_ulid'], $classesByUlid);

                if ($classId === null) {
                    continue;
                }

                $intervention = new Intervention;
                $intervention->forceFill([
                    'ulid' => $this->writableUlid($row), 'class_id' => $classId, 'enrollment_id' => $this->resolveId($row['enrollment_ulid'], $enrollmentsByUlid),
                    'academic_period_id' => $this->resolveId($row['academic_period_ulid'], $periodsByUlid),
                    'domain_id' => $this->resolveId($row['domain_ulid'], $domainsByUlid), 'target_type' => $row['target_type'],
                    'intervention_type' => $row['intervention_type'], 'motive_code' => $row['motive_code'], 'motive_label' => $row['motive_label'],
                    'strategy_code' => $row['strategy_code'], 'strategy_label' => $row['strategy_label'], 'objective' => $row['objective'],
                    'domain_relation' => $row['domain_relation'] ?? 'none', 'title' => $row['title'], 'description' => $row['description'],
                    'description_source' => $row['description_source'] ?? 'manual', 'status' => $row['status'],
                    'started_on' => $row['started_on'], 'expected_end_on' => $row['expected_end_on'], 'concluded_on' => $row['concluded_on'],
                    'review_on' => $row['review_on'], 'available_for_reports' => $row['available_for_reports'],
                    'support_measure_level' => $row['support_measure_level'], 'support_measure_code' => $row['support_measure_code'],
                    'evaluation_adaptation_code' => $row['evaluation_adaptation_code'], 'legal_mapping_source' => $row['legal_mapping_source'],
                    'created_by' => $row['created_by'],
                ]);
                $intervention->save();

                $participantIds = array_filter(array_map(
                    fn (string $ulid): ?int => $this->resolveId($ulid, $enrollmentsByUlid),
                    $row['participant_enrollment_ulids'],
                ));

                if ($participantIds !== []) {
                    $intervention->participants()->attach($participantIds);
                }

                $byUlid[$row['ulid']] = $intervention->getKey();
                $created++;
            } elseif (in_array($row['classification'], ['existing', 'conflict'], true) && isset($row['existing_id'])) {
                $byUlid[$row['ulid']] = (int) $row['existing_id'];
            }
        }

        return ['byUlid' => $byUlid, 'created' => $created];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $interventionsByUlid
     */
    private function writeInterventionReviews(array $rows, array $interventionsByUlid): int
    {
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $interventionId = $this->resolveId($row['intervention_ulid'], $interventionsByUlid);

            if ($interventionId === null) {
                continue;
            }

            $review = new InterventionReview;
            $review->forceFill([
                'ulid' => $this->writableUlid($row), 'intervention_id' => $interventionId, 'reviewed_on' => $row['reviewed_on'],
                'effectiveness' => $row['effectiveness'], 'notes' => $row['notes'], 'reviewed_by' => $row['reviewed_by'],
            ]);
            $review->save();
            $created++;
        }

        return $created;
    }

    /**
     * Only ever a `status = finalized` row — a draft is never exported, so
     * never restored (§28 of the import brief). `based_on_report_id` wires
     * up in a second pass, the same self-reference pattern as
     * classifications' `superseded_by_id`.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $classesByUlid
     * @param  array<string, int>  $enrollmentsByUlid
     * @param  array<string, int>  $periodsByUlid
     * @param  array<string, int>  $interimByUlid
     */
    private function writeReports(array $rows, array $classesByUlid, array $enrollmentsByUlid, array $periodsByUlid, array $interimByUlid): int
    {
        $created = 0;
        /** @var array<string, int> $byUlid */
        $byUlid = [];
        /** @var array<string, string> $pendingBasedOn */
        $pendingBasedOn = [];

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $report = new Report;
            $report->forceFill([
                'ulid' => $this->writableUlid($row), 'type' => $row['type'], 'status' => 'finalized', 'title' => $row['title'], 'tone' => $row['tone'],
                'class_id' => $this->resolveId($row['class_ulid'], $classesByUlid), 'enrollment_id' => $this->resolveId($row['enrollment_ulid'], $enrollmentsByUlid),
                'academic_year_id' => $row['academic_year_id'], 'academic_period_id' => $this->resolveId($row['academic_period_ulid'], $periodsByUlid),
                'interim_assessment_id' => $this->resolveId($row['interim_assessment_ulid'], $interimByUlid),
                'scope_kind' => $row['scope_kind'], 'starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on'],
                'scope_label' => $row['scope_label'], 'document' => $row['document'], 'document_version' => $row['document_version'],
                'document_hash' => $row['document_hash'], 'finalized_at' => $row['finalized_at'] ?? now(),
                'finalized_by' => $row['finalized_by'], 'created_by' => $row['created_by'],
                'template_key' => $row['template_key'], 'template_snapshot' => $row['template_snapshot'],
            ]);
            $report->save();
            $byUlid[$row['ulid']] = $report->getKey();
            $created++;

            if ($row['based_on_report_ulid'] !== null) {
                $pendingBasedOn[$row['ulid']] = $row['based_on_report_ulid'];
            }
        }

        foreach ($pendingBasedOn as $ulid => $basedOnUlid) {
            $targetId = $byUlid[$basedOnUlid] ?? null;

            if ($targetId !== null) {
                Report::query()->whereKey($byUlid[$ulid])->update(['based_on_report_id' => $targetId]);
            }
        }

        return $created;
    }
}
