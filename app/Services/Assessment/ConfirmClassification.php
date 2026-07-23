<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Domain\Assessment\CalculationEngine;
use App\Domain\Assessment\CalculationOutcome;
use App\Models\CalculationSnapshot;
use App\Models\Classification;
use App\Models\ClassificationStatus;
use App\Models\SnapshotTrigger;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Assessment\ClassificationDecisionException;
use Illuminate\Support\Facades\DB;

/**
 * The moment the system's proposal becomes the teacher's grade (§3.3, §7.1). It
 * freezes a snapshot of exactly how the proposal was reached, then records the
 * decision — the deterministic proposal is kept, the teacher's final value is
 * written beside it, and any change from the proposal carries a mandatory reason
 * (A10). The snapshot is written first so a confirmed grade is never without its
 * explanation.
 */
class ConfirmClassification
{
    public function __construct(
        protected ClassResultsCalculator $calculator,
        protected AuditLog $audit,
    ) {}

    /**
     * @param  string|null  $finalValue  the teacher's value; null or equal to the proposal means "accept the proposal"
     */
    public function confirm(
        Classification $classification,
        User $teacher,
        ?string $finalValue = null,
        ?string $overrideReason = null,
    ): Classification {
        // The whole decision runs under a row lock: two teachers confirming the
        // same proposal at once would otherwise let the second write overwrite
        // the first's override, silently erasing the A10 trail. The re-fetch and
        // status re-check happen INSIDE the transaction, on the locked row.
        return DB::transaction(function () use ($classification, $teacher, $finalValue, $overrideReason): Classification {
            $locked = Classification::query()->whereKey($classification->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ClassificationStatus::Proposed) {
                throw ClassificationDecisionException::notProposed();
            }

            // A proposal generated under an old profile version is stale: the
            // class moved to a new version, so the frozen snapshot would attribute
            // the result to the wrong rule (§10.2). Force a re-propose.
            $currentVersionId = $locked->enrollment->schoolClass->assessment_profile_version_id;
            if ($currentVersionId !== $locked->assessment_profile_version_id) {
                throw ClassificationDecisionException::stale();
            }

            $outcome = $this->freshOutcomeFor($locked);

            // A proposal confirmed must match the numbers currently on the grid; a
            // stale proposal is refused, not silently confirmed at an old value.
            if (! $this->matchesProposal($outcome, $locked)) {
                throw ClassificationDecisionException::stale();
            }

            $isOverride = $finalValue !== null
                && $locked->proposed_value !== null
                && Bc::compare(Bc::of($finalValue), Bc::of($locked->proposed_value)) !== 0;

            if ($isOverride && ($overrideReason === null || trim($overrideReason) === '')) {
                throw ClassificationDecisionException::missingOverrideReason();
            }

            $payload = $this->payloadFor($locked, $outcome);

            $snapshot = CalculationSnapshot::create([
                'enrollment_id' => $locked->enrollment_id,
                'academic_period_id' => $locked->academic_period_id,
                'scope' => $locked->scope,
                'assessment_profile_version_id' => $locked->assessment_profile_version_id,
                'trigger' => SnapshotTrigger::ProposalConfirmed,
                'engine_version' => CalculationEngine::VERSION,
                'payload' => $payload,
                'payload_hash' => CalculationSnapshot::hashPayload($payload),
                'result_normalized_value' => $outcome->normalizedValue,
                'result_value' => $outcome->proposedValue,
                'result_scale_level_id' => null,
                'created_by' => $teacher->id,
                'created_at' => now(),
            ]);

            $locked->fill([
                'status' => ClassificationStatus::Confirmed,
                'calculation_snapshot_id' => $snapshot->id,
                'confirmed_by' => $teacher->id,
                'confirmed_at' => now(),
                // The final value is always written explicitly. Accepting the
                // proposal sets final = proposed (satisfying the CHECK with no
                // reason); overriding writes a different value and the reason.
                'final_value' => $isOverride ? $finalValue : $locked->proposed_value,
                'final_scale_level_id' => $locked->proposed_scale_level_id,
                'override_reason' => $isOverride ? $overrideReason : null,
                'overridden_by' => $isOverride ? $teacher->id : null,
                'overridden_at' => $isOverride ? now() : null,
            ])->save();

            // Audit (§22.5): a confirmation, and — when the teacher changed the
            // proposal — the override, each carry the values and the reason.
            $this->audit->record(
                $isOverride ? 'classification.overridden' : 'classification.confirmed',
                $locked,
                $teacher,
                $isOverride
                    ? "Classificação alterada de {$locked->proposed_value} para {$finalValue}."
                    : "Classificação confirmada em {$locked->final_value}.",
                [
                    'proposed_value' => $locked->proposed_value,
                    'final_value' => $locked->final_value,
                    'override_reason' => $locked->override_reason,
                    'snapshot_id' => $snapshot->id,
                ],
            );

            return $locked;
        });
    }

    protected function freshOutcomeFor(Classification $classification): CalculationOutcome
    {
        $enrollment = $classification->enrollment;
        $class = $enrollment->schoolClass;
        $period = $classification->academicPeriod;

        // Recompute in the same scope the proposal was generated in — an
        // accumulated proposal must be re-checked against the accumulated result.
        foreach ($this->calculator->forScope($class, $period, $classification->scope) as $row) {
            if ($row['enrollment']->id === $enrollment->id) {
                return $row['outcome'];
            }
        }

        // No row for this enrollment means nothing computable — treated as stale,
        // because a proposal cannot be confirmed against a vanished result.
        throw ClassificationDecisionException::stale();
    }

    protected function matchesProposal(CalculationOutcome $outcome, Classification $classification): bool
    {
        if ($outcome->proposedValue === null || $classification->proposed_value === null) {
            return false;
        }

        return Bc::compare(Bc::of($outcome->proposedValue), Bc::of($classification->proposed_value)) === 0;
    }

    /**
     * The frozen document (§13.5): literal copies only — the engine's structured
     * explanation plus the identifying context. No FK into live scores.
     *
     * @return array<string, mixed>
     */
    protected function payloadFor(Classification $classification, CalculationOutcome $outcome): array
    {
        return [
            'engine_version' => CalculationEngine::VERSION,
            'assessment_profile_version_id' => $classification->assessment_profile_version_id,
            'academic_period_id' => $classification->academic_period_id,
            'scope' => $classification->scope->value,
            'normalized_value' => $outcome->normalizedValue,
            'proposed_value' => $outcome->proposedValue,
            'coverage_warning' => $outcome->coverageWarning,
            'explanation' => $outcome->explanation,
        ];
    }
}
