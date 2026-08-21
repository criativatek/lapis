<?php

namespace App\Actions\DataImports;

use App\Actions\DataImports\Concerns\ResolvesWrittenReferences;
use App\Models\Classification;
use App\Models\Instrument;
use App\Models\InstrumentGroup;
use App\Models\InstrumentItem;
use App\Models\ItemDomainAllocation;
use App\Models\Organization;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentQuestion;
use App\Models\SelfAssessmentResponse;
use App\Models\SelfAssessmentTemplate;
use App\Models\StudentItemScore;

/**
 * Writes the assessment DATA tier a validated backup's plan already
 * classified: elements/groups/items/domain allocations, recorded scores,
 * the full classification record, self-assessment templates/questions/
 * answers. Split out of `ExecuteDataImport` purely to keep each file small
 * enough for PHPStan to analyse — see that class for the transaction/
 * idempotency guarantees this writer runs inside.
 *
 * Only rows classified `new` by the plan are ever written (§12 of the
 * import brief) — never a value this layer computes: every weighted
 * average, proposal or evolution figure a teacher will later see comes
 * from `BuildResultsProgression`/`ClassResultsCalculator` reading exactly
 * the raw facts this writer restores, the same way it always does.
 */
class WriteAssessmentData
{
    use ResolvesWrittenReferences;

    /**
     * @param  array<int, array<string, mixed>>  $instrumentRows
     * @param  array<int, array<string, mixed>>  $instrumentGroupRows
     * @param  array<int, array<string, mixed>>  $instrumentItemRows
     * @param  array<int, array<string, mixed>>  $itemDomainAllocationRows
     * @param  array<int, array<string, mixed>>  $studentItemScoreRows
     * @param  array<int, array<string, mixed>>  $classificationRows
     * @param  array<int, array<string, mixed>>  $selfAssessmentTemplateRows
     * @param  array<int, array<string, mixed>>  $selfAssessmentQuestionRows
     * @param  array<int, array<string, mixed>>  $selfAssessmentRows
     * @param  array<int, array<string, mixed>>  $selfAssessmentResponseRows
     * @param  array<string, int>  $classesByUlid
     * @param  array<string, int>  $enrollmentsByUlid
     * @param  array<string, int>  $periodsByUlid
     * @param  array<string, int>  $domainsByUlid
     * @param  array<string, int>  $profileVersionsByUlid
     * @param  array<string, int>  $scalesByRef
     * @param  array<string, int>  $scaleLevelsByRef
     * @param  array<string, int>  $instrumentTypesByRef
     * @return array<string, int>
     */
    public function write(
        array $instrumentRows,
        array $instrumentGroupRows,
        array $instrumentItemRows,
        array $itemDomainAllocationRows,
        array $studentItemScoreRows,
        array $classificationRows,
        array $selfAssessmentTemplateRows,
        array $selfAssessmentQuestionRows,
        array $selfAssessmentRows,
        array $selfAssessmentResponseRows,
        Organization $organization,
        array $classesByUlid,
        array $enrollmentsByUlid,
        array $periodsByUlid,
        array $domainsByUlid,
        array $profileVersionsByUlid,
        array $scalesByRef,
        array $scaleLevelsByRef,
        array $instrumentTypesByRef,
    ): array {
        ['byUlid' => $instrumentsByUlid, 'created' => $instrumentsCreated] = $this->writeInstruments($instrumentRows, $classesByUlid, $periodsByUlid, $instrumentTypesByRef, $scalesByRef);
        ['byUlid' => $groupsByUlid, 'created' => $groupsCreated] = $this->writeInstrumentGroups($instrumentGroupRows, $instrumentsByUlid);
        ['byUlid' => $itemsByUlid, 'created' => $itemsCreated] = $this->writeInstrumentItems($instrumentItemRows, $instrumentsByUlid, $groupsByUlid, $scalesByRef);
        $allocationsCreated = $this->writeItemDomainAllocations($itemDomainAllocationRows, $itemsByUlid, $domainsByUlid);
        $scoresCreated = $this->writeStudentItemScores($studentItemScoreRows, $organization, $itemsByUlid, $enrollmentsByUlid, $scalesByRef, $scaleLevelsByRef);
        $classificationsCreated = $this->writeClassifications($classificationRows, $enrollmentsByUlid, $periodsByUlid, $profileVersionsByUlid, $scalesByRef, $scaleLevelsByRef);
        ['byUlid' => $templatesByUlid, 'created' => $templatesCreated] = $this->writeSelfAssessmentTemplates($selfAssessmentTemplateRows, $profileVersionsByUlid, $classesByUlid);
        $questionsByKey = $this->writeSelfAssessmentQuestions($selfAssessmentQuestionRows, $templatesByUlid, $domainsByUlid, $scalesByRef);
        ['byUlid' => $selfAssessmentsByUlid, 'created' => $selfAssessmentsCreated] = $this->writeSelfAssessments($selfAssessmentRows, $enrollmentsByUlid, $periodsByUlid, $templatesByUlid);
        $responsesCreated = $this->writeSelfAssessmentResponses($selfAssessmentResponseRows, $selfAssessmentsByUlid, $templatesByUlid, $questionsByKey, $scalesByRef, $scaleLevelsByRef);

        return [
            'instruments' => $instrumentsCreated,
            'instrument_groups' => $groupsCreated,
            'instrument_items' => $itemsCreated,
            'item_domain_allocations' => $allocationsCreated,
            'student_item_scores' => $scoresCreated,
            'classifications' => $classificationsCreated,
            'self_assessment_templates' => $templatesCreated,
            'self_assessments' => $selfAssessmentsCreated,
            'self_assessment_responses' => $responsesCreated,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $classesByUlid
     * @param  array<string, int>  $periodsByUlid
     * @param  array<string, int>  $instrumentTypesByRef
     * @param  array<string, int>  $scalesByRef
     * @return array{byUlid: array<string, int>, created: int}
     */
    private function writeInstruments(array $rows, array $classesByUlid, array $periodsByUlid, array $instrumentTypesByRef, array $scalesByRef): array
    {
        $byUlid = [];
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $classId = $this->resolveId($row['class_ulid'], $classesByUlid);

                if ($classId === null) {
                    continue;
                }

                $instrument = new Instrument;
                $instrument->forceFill([
                    'ulid' => $this->writableUlid($row), 'class_id' => $classId, 'title' => $row['title'], 'status' => $row['status'],
                    'applied_on' => $row['applied_on'],
                    'academic_period_id' => $this->resolveId($row['academic_period_ulid'], $periodsByUlid),
                    'instrument_type_id' => $this->resolveInstrumentTypeId($row['instrument_type'], $instrumentTypesByRef),
                    'purpose' => $row['purpose'], 'counts_toward_classification' => $row['counts_toward_classification'],
                    'total_points' => $row['total_points'], 'scale_id' => $this->resolveScaleId($row['scale'], $scalesByRef),
                    'weight' => $row['weight'], 'allow_bonus' => $row['allow_bonus'],
                ]);
                $instrument->save();
                $byUlid[$row['ulid']] = $instrument->getKey();
                $created++;
            } elseif (in_array($row['classification'], ['existing', 'conflict'], true) && isset($row['existing_id'])) {
                $byUlid[$row['ulid']] = (int) $row['existing_id'];
            }
        }

        return ['byUlid' => $byUlid, 'created' => $created];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $instrumentsByUlid
     * @return array{byUlid: array<string, int>, created: int}
     */
    private function writeInstrumentGroups(array $rows, array $instrumentsByUlid): array
    {
        $byUlid = [];
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $instrumentId = $this->resolveId($row['instrument_ulid'], $instrumentsByUlid);

                if ($instrumentId === null) {
                    continue;
                }

                $group = new InstrumentGroup;
                $group->forceFill(['ulid' => $this->writableUlid($row), 'instrument_id' => $instrumentId, 'label' => $row['label'], 'sequence' => $row['sequence']]);
                $group->save();
                $byUlid[$row['ulid']] = $group->getKey();
                $created++;
            } elseif (in_array($row['classification'], ['existing', 'conflict'], true) && isset($row['existing_id'])) {
                $byUlid[$row['ulid']] = (int) $row['existing_id'];
            }
        }

        return ['byUlid' => $byUlid, 'created' => $created];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $instrumentsByUlid
     * @param  array<string, int>  $groupsByUlid
     * @param  array<string, int>  $scalesByRef
     * @return array{byUlid: array<string, int>, created: int}
     */
    private function writeInstrumentItems(array $rows, array $instrumentsByUlid, array $groupsByUlid, array $scalesByRef): array
    {
        $byUlid = [];
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $instrumentId = $this->resolveId($row['instrument_ulid'], $instrumentsByUlid);
                $groupId = $this->resolveId($row['group_ulid'], $groupsByUlid);

                if ($instrumentId === null || $groupId === null) {
                    continue;
                }

                $item = new InstrumentItem;
                $item->forceFill([
                    'ulid' => $this->writableUlid($row), 'instrument_id' => $instrumentId, 'instrument_group_id' => $groupId, 'code' => $row['code'],
                    'label' => $row['label'], 'sequence' => $row['sequence'], 'points_possible' => $row['points_possible'],
                    'scoring_mode' => $row['scoring_mode'], 'scale_id' => $this->resolveScaleId($row['scale'], $scalesByRef),
                    'is_bonus' => $row['is_bonus'], 'source_group_label' => $row['source_group_label'],
                ]);
                $item->save();
                $byUlid[$row['ulid']] = $item->getKey();
                $created++;
            } elseif (in_array($row['classification'], ['existing', 'conflict'], true) && isset($row['existing_id'])) {
                $byUlid[$row['ulid']] = (int) $row['existing_id'];
            }
        }

        return ['byUlid' => $byUlid, 'created' => $created];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $itemsByUlid
     * @param  array<string, int>  $domainsByUlid
     */
    private function writeItemDomainAllocations(array $rows, array $itemsByUlid, array $domainsByUlid): int
    {
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $itemId = $this->resolveId($row['item_ulid'], $itemsByUlid);
            $domainId = $this->resolveId($row['domain_ulid'], $domainsByUlid);

            if ($itemId === null || $domainId === null) {
                continue;
            }

            $allocation = new ItemDomainAllocation;
            $allocation->forceFill(['instrument_item_id' => $itemId, 'domain_id' => $domainId, 'allocation_percent' => $row['allocation_percent']]);
            $allocation->save();
            $created++;
        }

        return $created;
    }

    /**
     * `instrument_id` is denormalized onto `student_item_scores` — read
     * straight off the just-resolved item, never re-derived.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $itemsByUlid
     * @param  array<string, int>  $enrollmentsByUlid
     * @param  array<string, int>  $scalesByRef
     * @param  array<string, int>  $scaleLevelsByRef
     */
    private function writeStudentItemScores(array $rows, Organization $organization, array $itemsByUlid, array $enrollmentsByUlid, array $scalesByRef, array $scaleLevelsByRef): int
    {
        $created = 0;
        $itemInstrumentIds = InstrumentItem::query()->whereIn('id', array_values($itemsByUlid))->pluck('instrument_id', 'id');

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $itemId = $this->resolveId($row['item_ulid'], $itemsByUlid);
            $enrollmentId = $this->resolveId($row['enrollment_ulid'], $enrollmentsByUlid);
            $instrumentId = $itemId === null ? null : $itemInstrumentIds->get($itemId);

            if ($itemId === null || $enrollmentId === null || $instrumentId === null) {
                continue;
            }

            $score = new StudentItemScore;
            $score->forceFill([
                'organization_id' => $organization->getKey(), 'instrument_id' => $instrumentId, 'instrument_item_id' => $itemId,
                'enrollment_id' => $enrollmentId, 'result_state' => $row['result_state'], 'points_earned' => $row['points_earned'],
                'scale_level_id' => $this->resolveScaleLevelId($row['scale_level'], $scalesByRef, $scaleLevelsByRef),
                'state_reason' => $row['state_reason'], 'assessed_at' => $row['assessed_at'], 'assessed_by' => $row['assessed_by'],
            ]);
            $score->save();
            $created++;
        }

        return $created;
    }

    /**
     * `superseded_by_id` wires up in a second pass, only when the target
     * classification also resolved to a real destination row in this same
     * run — never invented, never left dangling.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $enrollmentsByUlid
     * @param  array<string, int>  $periodsByUlid
     * @param  array<string, int>  $profileVersionsByUlid
     * @param  array<string, int>  $scalesByRef
     * @param  array<string, int>  $scaleLevelsByRef
     */
    private function writeClassifications(array $rows, array $enrollmentsByUlid, array $periodsByUlid, array $profileVersionsByUlid, array $scalesByRef, array $scaleLevelsByRef): int
    {
        $created = 0;
        /** @var array<string, int> $byUlid */
        $byUlid = [];
        /** @var array<string, string> $pendingSupersedes */
        $pendingSupersedes = [];

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $enrollmentId = $this->resolveId($row['enrollment_ulid'], $enrollmentsByUlid);

            if ($enrollmentId === null) {
                continue;
            }

            $classification = new Classification;
            $classification->forceFill([
                'ulid' => $this->writableUlid($row), 'enrollment_id' => $enrollmentId,
                'academic_period_id' => $this->resolveId($row['academic_period_ulid'], $periodsByUlid),
                'scope' => $row['scope'], 'assessment_profile_version_id' => $this->resolveId($row['assessment_profile_version_ulid'], $profileVersionsByUlid),
                'status' => $row['status'], 'proposed_normalized_value' => $row['proposed_normalized_value'],
                'proposed_value' => $row['proposed_value'], 'proposed_scale_level_id' => $this->resolveScaleLevelId($row['proposed_scale_level'], $scalesByRef, $scaleLevelsByRef),
                'final_value' => $row['final_value'], 'final_scale_level_id' => $this->resolveScaleLevelId($row['final_scale_level'], $scalesByRef, $scaleLevelsByRef),
                'override_reason' => $row['override_reason'], 'overridden_by' => $row['overridden_by'], 'overridden_at' => $row['overridden_at'],
                'confirmed_by' => $row['confirmed_by'], 'confirmed_at' => $row['confirmed_at'], 'published_at' => $row['published_at'],
            ]);
            $classification->save();
            $byUlid[$row['ulid']] = $classification->getKey();
            $created++;

            if ($row['superseded_by_ulid'] !== null) {
                $pendingSupersedes[$row['ulid']] = $row['superseded_by_ulid'];
            }
        }

        foreach ($pendingSupersedes as $ulid => $supersededByUlid) {
            $targetId = $byUlid[$supersededByUlid] ?? null;

            if ($targetId !== null) {
                Classification::query()->whereKey($byUlid[$ulid])->update(['superseded_by_id' => $targetId]);
            }
        }

        return $created;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $profileVersionsByUlid
     * @param  array<string, int>  $classesByUlid
     * @return array{byUlid: array<string, int>, created: int}
     */
    private function writeSelfAssessmentTemplates(array $rows, array $profileVersionsByUlid, array $classesByUlid): array
    {
        $byUlid = [];
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $template = new SelfAssessmentTemplate;
                $template->forceFill([
                    'ulid' => $this->writableUlid($row), 'name' => $row['name'], 'is_active' => $row['is_active'],
                    'assessment_profile_version_id' => $this->resolveId($row['assessment_profile_version_ulid'], $profileVersionsByUlid),
                    'class_id' => $this->resolveId($row['class_ulid'], $classesByUlid),
                ]);
                $template->save();
                $byUlid[$row['ulid']] = $template->getKey();
                $created++;
            } elseif (in_array($row['classification'], ['existing', 'conflict'], true) && isset($row['existing_id'])) {
                $byUlid[$row['ulid']] = (int) $row['existing_id'];
            }
        }

        return ['byUlid' => $byUlid, 'created' => $created];
    }

    /**
     * Questions have no ulid of their own — resolved by (template, role)
     * when role is set, else (template, sequence). Indexes BOTH the
     * questions just created AND the questions of a MATCHED existing
     * template (queried fresh), so a response can resolve either kind
     * identically.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $templatesByUlid
     * @param  array<string, int>  $domainsByUlid
     * @param  array<string, int>  $scalesByRef
     * @return array<string, int>
     */
    private function writeSelfAssessmentQuestions(array $rows, array $templatesByUlid, array $domainsByUlid, array $scalesByRef): array
    {
        /** @var array<string, int> $byKey */
        $byKey = [];
        $existingTemplateIds = [];

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $templateId = $this->resolveId($row['template_ulid'], $templatesByUlid);

                if ($templateId === null) {
                    continue;
                }

                $question = new SelfAssessmentQuestion;
                $question->forceFill([
                    'self_assessment_template_id' => $templateId, 'role' => $row['role'], 'prompt' => $row['prompt'],
                    'answer_kind' => $row['answer_kind'], 'domain_id' => $this->resolveId($row['domain_ulid'], $domainsByUlid),
                    'scale_id' => $this->resolveScaleId($row['scale'], $scalesByRef), 'sequence' => $row['sequence'],
                ]);
                $question->save();
                $byKey[$this->questionKey($templateId, $row['role'], $row['sequence'])] = $question->getKey();
            } elseif ($row['classification'] === 'existing') {
                $templateId = $this->resolveId($row['template_ulid'], $templatesByUlid);

                if ($templateId !== null) {
                    $existingTemplateIds[$templateId] = true;
                }
            }
        }

        if ($existingTemplateIds !== []) {
            foreach (SelfAssessmentQuestion::query()->whereIn('self_assessment_template_id', array_keys($existingTemplateIds))->get() as $question) {
                $byKey[$this->questionKey($question->self_assessment_template_id, $question->role?->value, $question->sequence)] = $question->getKey();
            }
        }

        return $byKey;
    }

    private function questionKey(int $templateId, ?string $role, int $sequence): string
    {
        return $role !== null ? "{$templateId}:role:{$role}" : "{$templateId}:seq:{$sequence}";
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $enrollmentsByUlid
     * @param  array<string, int>  $periodsByUlid
     * @param  array<string, int>  $templatesByUlid
     * @return array{byUlid: array<string, int>, created: int}
     */
    private function writeSelfAssessments(array $rows, array $enrollmentsByUlid, array $periodsByUlid, array $templatesByUlid): array
    {
        $byUlid = [];
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $enrollmentId = $this->resolveId($row['enrollment_ulid'], $enrollmentsByUlid);
                $periodId = $this->resolveId($row['academic_period_ulid'], $periodsByUlid);
                $templateId = $this->resolveId($row['template_ulid'], $templatesByUlid);

                if ($enrollmentId === null || $periodId === null || $templateId === null) {
                    continue;
                }

                $selfAssessment = new SelfAssessment;
                $selfAssessment->forceFill([
                    'ulid' => $this->writableUlid($row), 'enrollment_id' => $enrollmentId, 'academic_period_id' => $periodId,
                    'self_assessment_template_id' => $templateId, 'status' => $row['status'], 'filled_by' => $row['filled_by'],
                    'reflection' => $row['reflection'], 'submitted_at' => $row['submitted_at'], 'reviewed_at' => $row['reviewed_at'],
                    'reviewed_by' => $row['reviewed_by'],
                ]);
                $selfAssessment->save();
                $byUlid[$row['ulid']] = $selfAssessment->getKey();
                $created++;
            } elseif (in_array($row['classification'], ['existing', 'conflict'], true) && isset($row['existing_id'])) {
                $byUlid[$row['ulid']] = (int) $row['existing_id'];
            }
        }

        return ['byUlid' => $byUlid, 'created' => $created];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $selfAssessmentsByUlid
     * @param  array<string, int>  $templatesByUlid
     * @param  array<string, int>  $questionsByKey
     * @param  array<string, int>  $scalesByRef
     * @param  array<string, int>  $scaleLevelsByRef
     */
    private function writeSelfAssessmentResponses(array $rows, array $selfAssessmentsByUlid, array $templatesByUlid, array $questionsByKey, array $scalesByRef, array $scaleLevelsByRef): int
    {
        $created = 0;
        $templateIdBySelfAssessmentId = SelfAssessment::query()->whereIn('id', array_values($selfAssessmentsByUlid))->pluck('self_assessment_template_id', 'id');

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $selfAssessmentId = $this->resolveId($row['self_assessment_ulid'], $selfAssessmentsByUlid);
            $templateId = $selfAssessmentId === null ? null : $templateIdBySelfAssessmentId->get($selfAssessmentId);

            if ($selfAssessmentId === null || $templateId === null) {
                continue;
            }

            $key = $row['question_role'] !== null
                ? "{$templateId}:role:{$row['question_role']}"
                : "{$templateId}:seq:{$row['question_sequence']}";
            $questionId = $questionsByKey[$key] ?? null;

            if ($questionId === null) {
                continue;
            }

            $response = new SelfAssessmentResponse;
            $response->forceFill([
                'self_assessment_id' => $selfAssessmentId, 'self_assessment_question_id' => $questionId,
                'scale_level_id' => $this->resolveScaleLevelId($row['scale_level'], $scalesByRef, $scaleLevelsByRef),
                'text_value' => $row['text_value'], 'boolean_value' => $row['boolean_value'],
            ]);
            $response->save();
            $created++;
        }

        return $created;
    }
}
