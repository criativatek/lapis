<?php

namespace App\Services\Import\Backup;

use App\Models\Classification;
use App\Models\ClassificationStatus;
use App\Models\Instrument;
use App\Models\InstrumentGroup;
use App\Models\InstrumentItem;
use App\Models\Organization;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentTemplate;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Services\Import\Backup\Concerns\ResolvesBackupReferences;
use Illuminate\Support\Collection;

/**
 * The assessment DATA tier of the import plan (§14 of the import brief,
 * docs/backup-schema.md): elements/groups/items/domain allocations,
 * recorded scores, the full classification record, self-assessment
 * templates/questions/answers. Split out of `BuildImportPlan` purely to
 * keep each file small enough for PHPStan to analyse — see that class for
 * the shared new/existing/conflict/invalid vocabulary.
 *
 * Runs after classes/students/enrollments (`BuildImportPlan`) and after
 * `BuildAssessmentStructurePlan` — everything here references a class, an
 * enrollment, a period, a profile version, a domain or a scale that one of
 * those already resolved (or classified invalid).
 *
 * @phpstan-import-type ScaleResolution from ResolvesBackupReferences
 * @phpstan-import-type InstrumentTypeResolution from ResolvesBackupReferences
 */
class BuildAssessmentDataPlan
{
    use ResolvesBackupReferences;

    /**
     * @param  array<int, array<string, mixed>>  $instrumentsIn
     * @param  array<int, array<string, mixed>>  $instrumentGroupsIn
     * @param  array<int, array<string, mixed>>  $instrumentItemsIn
     * @param  array<int, array<string, mixed>>  $itemDomainAllocationsIn
     * @param  array<int, array<string, mixed>>  $studentItemScoresIn
     * @param  array<int, array<string, mixed>>  $classificationsIn
     * @param  array<int, array<string, mixed>>  $selfAssessmentTemplatesIn
     * @param  array<int, array<string, mixed>>  $selfAssessmentQuestionsIn
     * @param  array<int, array<string, mixed>>  $selfAssessmentsIn
     * @param  array<int, array<string, mixed>>  $selfAssessmentResponsesIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @param  Collection<string, array<string, mixed>>  $enrollmentsByUlid
     * @param  Collection<string, array<string, mixed>>  $periodsByUlid
     * @param  Collection<string, array<string, mixed>>  $domainsByUlid
     * @param  Collection<string, array<string, mixed>>  $profileVersionsByUlid
     * @param  ScaleResolution  $scaleResolution
     * @param  InstrumentTypeResolution  $instrumentTypeResolution
     * @return array{rows: array<string, array<int, array<string, mixed>>>}
     */
    public function build(
        array $instrumentsIn,
        array $instrumentGroupsIn,
        array $instrumentItemsIn,
        array $itemDomainAllocationsIn,
        array $studentItemScoresIn,
        array $classificationsIn,
        array $selfAssessmentTemplatesIn,
        array $selfAssessmentQuestionsIn,
        array $selfAssessmentsIn,
        array $selfAssessmentResponsesIn,
        Organization $destination,
        User $actor,
        Collection $classesByUlid,
        Collection $enrollmentsByUlid,
        Collection $periodsByUlid,
        Collection $domainsByUlid,
        Collection $profileVersionsByUlid,
        array $scaleResolution,
        array $instrumentTypeResolution,
    ): array {
        $instrumentRows = $this->classifyInstruments($instrumentsIn, $destination, $classesByUlid, $periodsByUlid, $instrumentTypeResolution, $scaleResolution);
        $instrumentsByUlid = collect($instrumentRows)->keyBy('ulid');

        $instrumentGroupRows = $this->classifyInstrumentGroups($instrumentGroupsIn, $destination, $instrumentsByUlid);
        $instrumentGroupsByUlid = collect($instrumentGroupRows)->keyBy('ulid');

        $instrumentItemRows = $this->classifyInstrumentItems($instrumentItemsIn, $destination, $instrumentsByUlid, $instrumentGroupsByUlid, $scaleResolution);
        $instrumentItemsByUlid = collect($instrumentItemRows)->keyBy('ulid');

        $itemDomainAllocationRows = $this->classifyItemDomainAllocations($itemDomainAllocationsIn, $instrumentItemsByUlid, $domainsByUlid);

        $studentItemScoreRows = $this->classifyStudentItemScores($studentItemScoresIn, $destination, $instrumentItemsByUlid, $enrollmentsByUlid, $scaleResolution, $actor);

        $classificationRows = $this->classifyClassifications($classificationsIn, $destination, $enrollmentsByUlid, $periodsByUlid, $profileVersionsByUlid, $scaleResolution, $actor);

        $selfAssessmentTemplateRows = $this->classifySelfAssessmentTemplates($selfAssessmentTemplatesIn, $destination, $profileVersionsByUlid, $classesByUlid);
        $selfAssessmentTemplatesByUlid = collect($selfAssessmentTemplateRows)->keyBy('ulid');

        $selfAssessmentQuestionRows = $this->classifySelfAssessmentQuestions($selfAssessmentQuestionsIn, $selfAssessmentTemplatesByUlid, $domainsByUlid, $scaleResolution);

        $selfAssessmentRows = $this->classifySelfAssessments($selfAssessmentsIn, $destination, $enrollmentsByUlid, $periodsByUlid, $selfAssessmentTemplatesByUlid, $actor);
        $selfAssessmentsByUlid = collect($selfAssessmentRows)->keyBy('ulid');

        $selfAssessmentResponseRows = $this->classifySelfAssessmentResponses($selfAssessmentResponsesIn, $selfAssessmentsByUlid, $scaleResolution);

        return [
            'rows' => [
                'instruments' => $instrumentRows,
                'instrument_groups' => $instrumentGroupRows,
                'instrument_items' => $instrumentItemRows,
                'item_domain_allocations' => $itemDomainAllocationRows,
                'student_item_scores' => $studentItemScoreRows,
                'classifications' => $classificationRows,
                'self_assessment_templates' => $selfAssessmentTemplateRows,
                'self_assessment_questions' => $selfAssessmentQuestionRows,
                'self_assessments' => $selfAssessmentRows,
                'self_assessment_responses' => $selfAssessmentResponseRows,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $instrumentsIn
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @param  Collection<string, array<string, mixed>>  $periodsByUlid
     * @param  InstrumentTypeResolution  $instrumentTypeResolution
     * @param  ScaleResolution  $scaleResolution
     * @return array<int, array<string, mixed>>
     */
    private function classifyInstruments(array $instrumentsIn, Organization $destination, Collection $classesByUlid, Collection $periodsByUlid, array $instrumentTypeResolution, array $scaleResolution): array
    {
        $ulids = collect($instrumentsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(Instrument::class, $ulids, $destination);
        $classIds = $classesByUlid->pluck('existing_id')->filter();
        $byBusinessKey = $classIds->isEmpty() ? collect() : Instrument::query()->where('organization_id', $destination->getKey())->whereIn('class_id', $classIds)->get()
            ->keyBy(fn (Instrument $instrument): string => "{$instrument->class_id}:".($instrument->academic_period_id ?? 'null').":{$instrument->title}:".$instrument->applied_on->toDateString());

        return collect($instrumentsIn)->map(function (array $row) use ($classesByUlid, $periodsByUlid, $instrumentTypeResolution, $scaleResolution, $lookups, $byBusinessKey): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->title !== $row['title'] || $existing->status->value !== $row['status'];

                return [
                    'ulid' => $row['ulid'], 'title' => $row['title'],
                    'classification' => $diverges ? 'conflict' : 'existing',
                    'reason' => $diverges ? $this->conflictReason() : null,
                    'existing_id' => $existing->getKey(),
                ];
            }

            $class = $classesByUlid->get($row['class_ulid']);
            $classResolvable = $class !== null && in_array($class['classification'], ['new', 'existing'], true);
            $period = $row['academic_period_ulid'] !== null ? $periodsByUlid->get($row['academic_period_ulid']) : null;
            $periodResolvable = $row['academic_period_ulid'] === null || ($period !== null && in_array($period['classification'], ['new', 'existing'], true));
            $type = $this->resolveInstrumentTypeRef($row['instrument_type'], $instrumentTypeResolution['byRef']);
            $scale = $this->resolveScaleRef($row['scale'], $scaleResolution);

            if (! $classResolvable || ! $periodResolvable || ! $type['resolvable'] || ! $scale['resolvable']) {
                return [
                    'ulid' => $row['ulid'], 'title' => $row['title'], 'classification' => 'invalid',
                    'reason' => ! $classResolvable
                        ? $this->t('A turma deste elemento de avaliação não pode ser restaurada.')
                        : (! $periodResolvable
                            ? $this->t('O período letivo deste elemento de avaliação não pode ser restaurado.')
                            : $this->t('O tipo ou a escala deste elemento de avaliação não podem ser restaurados.')),
                ];
            }

            if ($lookups['elsewhere']->has($row['ulid']) && $class['classification'] === 'existing' && ($period === null || $period['classification'] === 'existing')) {
                $match = $byBusinessKey->get("{$class['existing_id']}:".($period['existing_id'] ?? 'null').":{$row['title']}:{$row['applied_on']}");

                if ($match !== null) {
                    $diverges = $match->title !== $row['title'] || $match->status->value !== $row['status'];

                    return ['ulid' => $row['ulid'], 'title' => $row['title'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $match->getKey()];
                }
            }

            return [
                'ulid' => $row['ulid'], 'title' => $row['title'], 'classification' => 'new', 'reason' => null, 'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']),
                'class_ulid' => $row['class_ulid'], 'status' => $row['status'], 'applied_on' => $row['applied_on'],
                'academic_period_ulid' => $row['academic_period_ulid'],
                'instrument_type' => $row['instrument_type'], 'purpose' => $row['purpose'],
                'counts_toward_classification' => $row['counts_toward_classification'], 'total_points' => $row['total_points'],
                'scale' => $row['scale'], 'weight' => $row['weight'], 'allow_bonus' => $row['allow_bonus'],
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $groupsIn
     * @param  Collection<string, array<string, mixed>>  $instrumentsByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifyInstrumentGroups(array $groupsIn, Organization $destination, Collection $instrumentsByUlid): array
    {
        $ulids = collect($groupsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(InstrumentGroup::class, $ulids, $destination);
        $instrumentIds = $instrumentsByUlid->pluck('existing_id')->filter();
        $byBusinessKey = $instrumentIds->isEmpty() ? collect() : InstrumentGroup::query()->where('organization_id', $destination->getKey())->whereIn('instrument_id', $instrumentIds)->get()
            ->keyBy(fn (InstrumentGroup $group): string => "{$group->instrument_id}:{$group->sequence}");

        return collect($groupsIn)->map(function (array $row) use ($instrumentsByUlid, $lookups, $byBusinessKey): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                return ['ulid' => $row['ulid'], 'classification' => $existing->label === $row['label'] ? 'existing' : 'conflict', 'reason' => $existing->label === $row['label'] ? null : $this->conflictReason(), 'existing_id' => $existing->getKey()];
            }

            $instrument = $instrumentsByUlid->get($row['instrument_ulid']);
            $resolvable = $instrument !== null && in_array($instrument['classification'], ['new', 'existing'], true);

            if (! $resolvable) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('O elemento de avaliação deste grupo não pode ser restaurado.')];
            }

            if ($lookups['elsewhere']->has($row['ulid']) && $instrument['classification'] === 'existing') {
                $match = $byBusinessKey->get("{$instrument['existing_id']}:{$row['sequence']}");

                if ($match !== null) {
                    return ['ulid' => $row['ulid'], 'classification' => $match->label === $row['label'] ? 'existing' : 'conflict', 'reason' => $match->label === $row['label'] ? null : $this->conflictReason(), 'existing_id' => $match->getKey()];
                }
            }

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null, 'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']),
                'instrument_ulid' => $row['instrument_ulid'], 'label' => $row['label'], 'sequence' => $row['sequence'],
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $itemsIn
     * @param  Collection<string, array<string, mixed>>  $instrumentsByUlid
     * @param  Collection<string, array<string, mixed>>  $groupsByUlid
     * @param  ScaleResolution  $scaleResolution
     * @return array<int, array<string, mixed>>
     */
    private function classifyInstrumentItems(array $itemsIn, Organization $destination, Collection $instrumentsByUlid, Collection $groupsByUlid, array $scaleResolution): array
    {
        $ulids = collect($itemsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(InstrumentItem::class, $ulids, $destination);
        $instrumentIds = $instrumentsByUlid->pluck('existing_id')->filter();
        $byBusinessKey = $instrumentIds->isEmpty() ? collect() : InstrumentItem::query()->where('organization_id', $destination->getKey())->whereIn('instrument_id', $instrumentIds)->get()
            ->keyBy(fn (InstrumentItem $item): string => "{$item->instrument_id}:{$item->code}");

        return collect($itemsIn)->map(function (array $row) use ($instrumentsByUlid, $groupsByUlid, $scaleResolution, $lookups, $byBusinessKey): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->code !== $row['code'] || (string) $existing->points_possible !== (string) $row['points_possible'];

                return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $existing->getKey()];
            }

            $instrument = $instrumentsByUlid->get($row['instrument_ulid']);
            $instrumentResolvable = $instrument !== null && in_array($instrument['classification'], ['new', 'existing'], true);
            $group = $row['group_ulid'] !== null ? $groupsByUlid->get($row['group_ulid']) : null;
            $groupResolvable = $group !== null && in_array($group['classification'], ['new', 'existing'], true);
            $scale = $this->resolveScaleRef($row['scale'], $scaleResolution);

            if (! $instrumentResolvable || ! $groupResolvable || ! $scale['resolvable']) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('O elemento de avaliação, o grupo ou a escala deste item não podem ser restaurados.')];
            }

            if ($lookups['elsewhere']->has($row['ulid']) && $instrument['classification'] === 'existing') {
                $match = $byBusinessKey->get("{$instrument['existing_id']}:{$row['code']}");

                if ($match !== null) {
                    $diverges = $match->code !== $row['code'] || (string) $match->points_possible !== (string) $row['points_possible'];

                    return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $match->getKey()];
                }
            }

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null, 'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']),
                'instrument_ulid' => $row['instrument_ulid'], 'group_ulid' => $row['group_ulid'], 'code' => $row['code'], 'label' => $row['label'],
                'sequence' => $row['sequence'], 'points_possible' => $row['points_possible'], 'scoring_mode' => $row['scoring_mode'],
                'scale' => $row['scale'], 'is_bonus' => $row['is_bonus'], 'source_group_label' => $row['source_group_label'],
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rowsIn
     * @param  Collection<string, array<string, mixed>>  $itemsByUlid
     * @param  Collection<string, array<string, mixed>>  $domainsByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifyItemDomainAllocations(array $rowsIn, Collection $itemsByUlid, Collection $domainsByUlid): array
    {
        return collect($rowsIn)->map(function (array $row) use ($itemsByUlid, $domainsByUlid): array {
            $item = $itemsByUlid->get($row['item_ulid']);
            $domain = $domainsByUlid->get($row['domain_ulid']);
            $itemResolvable = $item !== null && in_array($item['classification'], ['new', 'existing'], true);
            $domainResolvable = $domain !== null && in_array($domain['classification'], ['new', 'existing'], true);

            if (! $itemResolvable || ! $domainResolvable) {
                return ['classification' => 'invalid', 'reason' => $this->t('O item ou o domínio desta alocação não podem ser restaurados.')];
            }

            if ($item['classification'] === 'existing') {
                return ['classification' => 'existing', 'reason' => null];
            }

            return ['classification' => 'new', 'reason' => null, 'item_ulid' => $row['item_ulid'], 'domain_ulid' => $row['domain_ulid'], 'allocation_percent' => $row['allocation_percent']];
        })->values()->all();
    }

    /**
     * No ulid — matched for idempotency by (item, enrollment), the same
     * pair the database itself enforces as unique.
     *
     * @param  array<int, array<string, mixed>>  $scoresIn
     * @param  Collection<string, array<string, mixed>>  $itemsByUlid
     * @param  Collection<string, array<string, mixed>>  $enrollmentsByUlid
     * @param  ScaleResolution  $scaleResolution
     * @return array<int, array<string, mixed>>
     */
    private function classifyStudentItemScores(array $scoresIn, Organization $destination, Collection $itemsByUlid, Collection $enrollmentsByUlid, array $scaleResolution, User $actor): array
    {
        $itemIds = $itemsByUlid->pluck('existing_id')->filter();
        $existing = $itemIds->isEmpty()
            ? collect()
            : StudentItemScore::query()->where('organization_id', $destination->getKey())->whereIn('instrument_item_id', $itemIds)->get()
                ->keyBy(fn (StudentItemScore $score): string => "{$score->instrument_item_id}:{$score->enrollment_id}");

        return collect($scoresIn)->map(function (array $row) use ($itemsByUlid, $enrollmentsByUlid, $scaleResolution, $actor, $existing): array {
            $item = $itemsByUlid->get($row['item_ulid']);
            $enrollment = $enrollmentsByUlid->get($row['enrollment_ulid']);
            $itemResolvable = $item !== null && in_array($item['classification'], ['new', 'existing'], true);
            $enrollmentResolvable = $enrollment !== null && in_array($enrollment['classification'], ['new', 'existing'], true);

            if (! $itemResolvable || ! $enrollmentResolvable) {
                return ['classification' => 'invalid', 'reason' => $this->t('O item ou a inscrição deste registo de avaliação não podem ser restaurados.')];
            }

            if ($item['classification'] === 'existing' && $enrollment['classification'] === 'existing') {
                $key = "{$item['existing_id']}:{$enrollment['existing_id']}";
                $match = $existing->get($key);

                if ($match !== null) {
                    $diverges = $match->result_state->value !== $row['result_state'] || (string) $match->points_earned !== (string) $row['points_earned'];

                    return ['classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null];
                }
            }

            $scaleLevel = $this->resolveScaleLevelRef($row['scale_level'], $scaleResolution);

            if (! $scaleLevel['resolvable']) {
                return ['classification' => 'invalid', 'reason' => $this->t('O nível de escala deste registo de avaliação não pode ser restaurado.')];
            }

            return [
                'classification' => 'new', 'reason' => null, 'item_ulid' => $row['item_ulid'], 'enrollment_ulid' => $row['enrollment_ulid'],
                'result_state' => $row['result_state'], 'points_earned' => $row['points_earned'], 'scale_level' => $row['scale_level'],
                'state_reason' => $row['state_reason'], 'assessed_at' => $row['assessed_at'],
                'assessed_by' => $this->resolveAuthor($row['assessed_by_email'], $actor),
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $classificationsIn
     * @param  Collection<string, array<string, mixed>>  $enrollmentsByUlid
     * @param  Collection<string, array<string, mixed>>  $periodsByUlid
     * @param  Collection<string, array<string, mixed>>  $profileVersionsByUlid
     * @param  ScaleResolution  $scaleResolution
     * @return array<int, array<string, mixed>>
     */
    private function classifyClassifications(array $classificationsIn, Organization $destination, Collection $enrollmentsByUlid, Collection $periodsByUlid, Collection $profileVersionsByUlid, array $scaleResolution, User $actor): array
    {
        $ulids = collect($classificationsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(Classification::class, $ulids, $destination);
        $byUlidForSelfRef = collect($classificationsIn)->keyBy('ulid');
        $enrollmentIds = $enrollmentsByUlid->pluck('existing_id')->filter();
        $byBusinessKey = $enrollmentIds->isEmpty() ? collect() : Classification::query()->where('organization_id', $destination->getKey())->whereIn('enrollment_id', $enrollmentIds)->whereNull('superseded_by_id')->get()
            ->keyBy(fn (Classification $classification): string => "{$classification->enrollment_id}:".($classification->academic_period_id ?? 'null').":{$classification->scope->value}");

        return collect($classificationsIn)->map(function (array $row) use ($enrollmentsByUlid, $periodsByUlid, $profileVersionsByUlid, $scaleResolution, $actor, $lookups, $byUlidForSelfRef, $byBusinessKey): array {
            // THE CONFIRMER DECIDES THE STATUS THIS IMPORT MAY WRITE, and it
            // is settled here, before any comparison, because everything
            // downstream must agree on ONE status.
            //
            // `classifications_confirmed_has_author_check` refuses
            // `status = 'confirmed'` with an empty `confirmed_by`. A backup
            // confirmed by an account this installation cannot map used to
            // reach the writer as `confirmed` + `null` and take the WHOLE
            // transaction down with a constraint violation — invisible in
            // the test suite, because `addCheck()` only runs on MySQL and
            // the suite is SQLite. Now the values the teacher recorded are
            // restored in full and only the confirmation step goes back to
            // them (§3.3 — the teacher decides).
            //
            // Computed BEFORE the existing/conflict comparisons on purpose:
            // comparing the SOURCE status against a row this same importer
            // wrote as `proposed` would classify every re-run as a conflict
            // and break idempotency (§11, §44).
            $confirmedBy = $this->resolveAuthor($row['confirmed_by_email'], $actor);
            $unconfirmable = $row['status'] === ClassificationStatus::Confirmed->value && $confirmedBy === null;
            $status = $unconfirmable ? ClassificationStatus::Proposed->value : $row['status'];

            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->status->value !== $status || (string) $existing->final_value !== (string) $row['final_value'];

                return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $existing->getKey()];
            }

            $enrollment = $enrollmentsByUlid->get($row['enrollment_ulid']);
            $enrollmentResolvable = $enrollment !== null && in_array($enrollment['classification'], ['new', 'existing'], true);
            $period = $row['academic_period_ulid'] !== null ? $periodsByUlid->get($row['academic_period_ulid']) : null;
            $periodResolvable = $row['academic_period_ulid'] === null || ($period !== null && in_array($period['classification'], ['new', 'existing'], true));
            $version = $row['assessment_profile_version_ulid'] !== null ? $profileVersionsByUlid->get($row['assessment_profile_version_ulid']) : null;
            $versionResolvable = $row['assessment_profile_version_ulid'] === null || ($version !== null && in_array($version['classification'], ['new', 'existing'], true));
            $proposedLevel = $this->resolveScaleLevelRef($row['proposed_scale_level'], $scaleResolution);
            $finalLevel = $this->resolveScaleLevelRef($row['final_scale_level'], $scaleResolution);

            if (! $enrollmentResolvable || ! $periodResolvable || ! $versionResolvable || ! $proposedLevel['resolvable'] || ! $finalLevel['resolvable']) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A inscrição, o período, a versão do perfil ou o nível de escala desta classificação não podem ser restaurados.')];
            }

            if ($lookups['elsewhere']->has($row['ulid']) && $enrollment['classification'] === 'existing' && ($period === null || $period['classification'] === 'existing')) {
                $match = $byBusinessKey->get("{$enrollment['existing_id']}:".($period['existing_id'] ?? 'null').":{$row['scope']}");

                if ($match !== null) {
                    $diverges = $match->status->value !== $status || (string) $match->final_value !== (string) $row['final_value'];

                    return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $match->getKey()];
                }
            }

            // superseded_by_ulid only wires up if that other classification
            // is ALSO restorable in this same backup — never invented, and
            // never left dangling at a row that stayed out of this import.
            $supersededByUlid = $row['superseded_by_ulid'];
            $supersedes = $supersededByUlid !== null ? $byUlidForSelfRef->get($supersededByUlid) : null;

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null, 'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']),
                'enrollment_ulid' => $row['enrollment_ulid'], 'academic_period_ulid' => $row['academic_period_ulid'],
                'assessment_profile_version_ulid' => $row['assessment_profile_version_ulid'], 'scope' => $row['scope'], 'status' => $status,
                'proposed_normalized_value' => $row['proposed_normalized_value'], 'proposed_value' => $row['proposed_value'],
                'proposed_scale_level' => $row['proposed_scale_level'], 'final_value' => $row['final_value'],
                'final_scale_level' => $row['final_scale_level'], 'override_reason' => $row['override_reason'],
                'overridden_by' => $this->resolveAuthor($row['overridden_by_email'], $actor), 'overridden_at' => $row['overridden_at'],
                'confirmed_by' => $confirmedBy, 'confirmed_at' => $unconfirmable ? null : $row['confirmed_at'],
                'published_at' => $row['published_at'], 'superseded_by_ulid' => $supersedes !== null ? $supersededByUlid : null,
                'author_unresolved' => $unconfirmable,
                'notice' => $unconfirmable ? $this->unconfirmableDecisionNotice() : null,
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $templatesIn
     * @param  Collection<string, array<string, mixed>>  $profileVersionsByUlid
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifySelfAssessmentTemplates(array $templatesIn, Organization $destination, Collection $profileVersionsByUlid, Collection $classesByUlid): array
    {
        $ulids = collect($templatesIn)->pluck('ulid');
        $lookups = $this->ulidLookups(SelfAssessmentTemplate::class, $ulids, $destination);
        $byBusinessKey = SelfAssessmentTemplate::query()->where('organization_id', $destination->getKey())->whereIn('name', collect($templatesIn)->pluck('name'))->get()
            ->keyBy(fn (SelfAssessmentTemplate $template): string => ($template->assessment_profile_version_id ?? 'null').':'.($template->class_id ?? 'null').":{$template->name}");

        return collect($templatesIn)->map(function (array $row) use ($profileVersionsByUlid, $classesByUlid, $lookups, $byBusinessKey): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                return ['ulid' => $row['ulid'], 'classification' => $existing->name === $row['name'] ? 'existing' : 'conflict', 'reason' => $existing->name === $row['name'] ? null : $this->conflictReason(), 'existing_id' => $existing->getKey()];
            }

            $version = $row['assessment_profile_version_ulid'] !== null ? $profileVersionsByUlid->get($row['assessment_profile_version_ulid']) : null;
            $versionResolvable = $row['assessment_profile_version_ulid'] === null || ($version !== null && in_array($version['classification'], ['new', 'existing'], true));
            $class = $row['class_ulid'] !== null ? $classesByUlid->get($row['class_ulid']) : null;
            $classResolvable = $row['class_ulid'] === null || ($class !== null && in_array($class['classification'], ['new', 'existing'], true));

            if (! $versionResolvable || ! $classResolvable) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A versão de perfil ou a turma deste modelo de autoavaliação não podem ser restauradas.')];
            }

            if ($lookups['elsewhere']->has($row['ulid']) && ($version === null || $version['classification'] === 'existing') && ($class === null || $class['classification'] === 'existing')) {
                $match = $byBusinessKey->get(($version['existing_id'] ?? 'null').':'.($class['existing_id'] ?? 'null').":{$row['name']}");

                if ($match !== null) {
                    return ['ulid' => $row['ulid'], 'classification' => $match->name === $row['name'] ? 'existing' : 'conflict', 'reason' => $match->name === $row['name'] ? null : $this->conflictReason(), 'existing_id' => $match->getKey()];
                }
            }

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null, 'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']), 'name' => $row['name'], 'is_active' => $row['is_active'],
                'assessment_profile_version_ulid' => $row['assessment_profile_version_ulid'], 'class_ulid' => $row['class_ulid'],
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $questionsIn
     * @param  Collection<string, array<string, mixed>>  $templatesByUlid
     * @param  Collection<string, array<string, mixed>>  $domainsByUlid
     * @param  ScaleResolution  $scaleResolution
     * @return array<int, array<string, mixed>>
     */
    private function classifySelfAssessmentQuestions(array $questionsIn, Collection $templatesByUlid, Collection $domainsByUlid, array $scaleResolution): array
    {
        return collect($questionsIn)->map(function (array $row) use ($templatesByUlid, $domainsByUlid, $scaleResolution): array {
            $template = $templatesByUlid->get($row['template_ulid']);
            $templateResolvable = $template !== null && in_array($template['classification'], ['new', 'existing'], true);
            $domain = $row['domain_ulid'] !== null ? $domainsByUlid->get($row['domain_ulid']) : null;
            $domainResolvable = $row['domain_ulid'] === null || ($domain !== null && in_array($domain['classification'], ['new', 'existing'], true));
            $scale = $this->resolveScaleRef($row['scale'], $scaleResolution);

            if (! $templateResolvable || ! $domainResolvable || ! $scale['resolvable']) {
                return ['classification' => 'invalid', 'reason' => $this->t('O modelo, o domínio ou a escala desta pergunta não podem ser restaurados.')];
            }

            if ($template['classification'] === 'existing') {
                return ['classification' => 'existing', 'reason' => null, 'template_ulid' => $row['template_ulid']];
            }

            return [
                'classification' => 'new', 'reason' => null, 'template_ulid' => $row['template_ulid'], 'role' => $row['role'],
                'prompt' => $row['prompt'], 'answer_kind' => $row['answer_kind'], 'domain_ulid' => $row['domain_ulid'],
                'scale' => $row['scale'], 'sequence' => $row['sequence'],
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $assessmentsIn
     * @param  Collection<string, array<string, mixed>>  $enrollmentsByUlid
     * @param  Collection<string, array<string, mixed>>  $periodsByUlid
     * @param  Collection<string, array<string, mixed>>  $templatesByUlid
     * @return array<int, array<string, mixed>>
     */
    private function classifySelfAssessments(array $assessmentsIn, Organization $destination, Collection $enrollmentsByUlid, Collection $periodsByUlid, Collection $templatesByUlid, User $actor): array
    {
        $ulids = collect($assessmentsIn)->pluck('ulid');
        $lookups = $this->ulidLookups(SelfAssessment::class, $ulids, $destination);
        $enrollmentIds = $enrollmentsByUlid->pluck('existing_id')->filter();
        $byBusinessKey = $enrollmentIds->isEmpty() ? collect() : SelfAssessment::query()->where('organization_id', $destination->getKey())->whereIn('enrollment_id', $enrollmentIds)->get()
            ->keyBy(fn (SelfAssessment $assessment): string => "{$assessment->enrollment_id}:{$assessment->academic_period_id}:{$assessment->self_assessment_template_id}");

        return collect($assessmentsIn)->map(function (array $row) use ($enrollmentsByUlid, $periodsByUlid, $templatesByUlid, $actor, $lookups, $byBusinessKey): array {
            $existing = $lookups['existing']->get($row['ulid']);

            if ($existing !== null) {
                $diverges = $existing->status->value !== $row['status'] || $existing->reflection !== $row['reflection'];

                return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $existing->getKey()];
            }

            $enrollment = $enrollmentsByUlid->get($row['enrollment_ulid']);
            $enrollmentResolvable = $enrollment !== null && in_array($enrollment['classification'], ['new', 'existing'], true);
            $period = $periodsByUlid->get($row['academic_period_ulid']);
            $periodResolvable = $period !== null && in_array($period['classification'], ['new', 'existing'], true);
            $template = $templatesByUlid->get($row['template_ulid']);
            $templateResolvable = $template !== null && in_array($template['classification'], ['new', 'existing'], true);

            if (! $enrollmentResolvable || ! $periodResolvable || ! $templateResolvable) {
                return ['ulid' => $row['ulid'], 'classification' => 'invalid', 'reason' => $this->t('A inscrição, o período ou o modelo desta autoavaliação não podem ser restaurados.')];
            }

            if ($lookups['elsewhere']->has($row['ulid']) && $enrollment['classification'] === 'existing' && $period['classification'] === 'existing' && $template['classification'] === 'existing') {
                $match = $byBusinessKey->get("{$enrollment['existing_id']}:{$period['existing_id']}:{$template['existing_id']}");

                if ($match !== null) {
                    $diverges = $match->status->value !== $row['status'] || $match->reflection !== $row['reflection'];

                    return ['ulid' => $row['ulid'], 'classification' => $diverges ? 'conflict' : 'existing', 'reason' => $diverges ? $this->conflictReason() : null, 'existing_id' => $match->getKey()];
                }
            }

            return [
                'ulid' => $row['ulid'], 'classification' => 'new', 'reason' => null, 'preserve_ulid' => ! $lookups['elsewhere']->has($row['ulid']), 'enrollment_ulid' => $row['enrollment_ulid'],
                'academic_period_ulid' => $row['academic_period_ulid'], 'template_ulid' => $row['template_ulid'],
                'status' => $row['status'], 'filled_by' => $row['filled_by'], 'reflection' => $row['reflection'],
                'submitted_at' => $row['submitted_at'], 'reviewed_at' => $row['reviewed_at'],
                'reviewed_by' => $this->resolveAuthor($row['reviewed_by_email'], $actor),
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $responsesIn
     * @param  Collection<string, array<string, mixed>>  $selfAssessmentsByUlid
     * @param  ScaleResolution  $scaleResolution
     * @return array<int, array<string, mixed>>
     */
    private function classifySelfAssessmentResponses(array $responsesIn, Collection $selfAssessmentsByUlid, array $scaleResolution): array
    {
        return collect($responsesIn)->map(function (array $row) use ($selfAssessmentsByUlid, $scaleResolution): array {
            $selfAssessment = $selfAssessmentsByUlid->get($row['self_assessment_ulid']);
            $resolvable = $selfAssessment !== null && in_array($selfAssessment['classification'], ['new', 'existing'], true);
            $scaleLevel = $this->resolveScaleLevelRef($row['scale_level'], $scaleResolution);

            if (! $resolvable || ! $scaleLevel['resolvable']) {
                return ['classification' => 'invalid', 'reason' => $this->t('A autoavaliação ou o nível de escala desta resposta não podem ser restaurados.')];
            }

            if ($selfAssessment['classification'] === 'existing') {
                return ['classification' => 'existing', 'reason' => null];
            }

            return [
                'classification' => 'new', 'reason' => null, 'self_assessment_ulid' => $row['self_assessment_ulid'],
                'question_role' => $row['question_role'], 'question_sequence' => $row['question_sequence'],
                'scale_level' => $row['scale_level'], 'text_value' => $row['text_value'], 'boolean_value' => $row['boolean_value'],
            ];
        })->values()->all();
    }
}
