<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Domain\Assessment\CalculationEngine;
use App\Domain\Assessment\CalculationOutcome;
use App\Models\CalculationSnapshot;
use App\Models\Classification;
use App\Models\ClassificationStatus;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\SnapshotTrigger;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Assessment\ClassificationDecisionException;
use Illuminate\Support\Facades\DB;

/**
 * The moment the system's proposal becomes the teacher's grade (§3.3, §7.1). It
 * freezes a snapshot of exactly how the proposal was reached, then records the
 * decision beside the deterministic proposal, which is never overwritten.
 *
 * THE DECISION IS TAKEN ON THE CLASSIFICATION SCALE, never in the engine's
 * normalized percentage. On a 1–5 the teacher assigns a LEVEL and it is stored
 * in `final_scale_level_id`, with `final_value` carrying that level's own number
 * so the two can never say different things. On a scale that is an interval —
 * 0–20, a percentage — there are no bands to choose from and the decision is the
 * number itself, in `final_value`.
 *
 * `proposed_value` is left exactly as the engine wrote it: the rounded
 * normalized percentage. It is a technical figure, it is what the staleness
 * check compares, and it is NOT the grade — which is why the decision is never
 * copied from it.
 */
class ConfirmClassification
{
    public function __construct(
        protected ClassResultsCalculator $calculator,
        protected ScaleProposalResolver $proposals,
        protected AuditLog $audit,
    ) {}

    /**
     * @param  int|null  $finalScaleLevelId  the level the teacher assigned, on a scale made of levels
     * @param  string|null  $finalValue  the classification the teacher wrote, on a scale that is an interval
     * @param  string|null  $observation  optional, always — a decision that differs from the proposal needs no defence
     */
    public function confirm(
        Classification $classification,
        User $teacher,
        ?int $finalScaleLevelId = null,
        ?string $finalValue = null,
        ?string $observation = null,
    ): Classification {
        // The whole decision runs under a row lock: two teachers confirming the
        // same proposal at once would otherwise let the second write overwrite
        // the first's decision, silently erasing the trail. The re-fetch and
        // status re-check happen INSIDE the transaction, on the locked row.
        return DB::transaction(function () use ($classification, $teacher, $finalScaleLevelId, $finalValue, $observation): Classification {
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

            $decision = $this->decide($locked, $finalScaleLevelId, $finalValue);
            $written = $observation === null || trim($observation) === '' ? null : trim($observation);

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
                'result_scale_level_id' => $outcome->scaleLevelId,
                'created_by' => $teacher->id,
                'created_at' => now(),
            ]);

            $locked->fill([
                'status' => ClassificationStatus::Confirmed,
                'calculation_snapshot_id' => $snapshot->id,
                'confirmed_by' => $teacher->id,
                'confirmed_at' => now(),
                // Always written explicitly, never left implied — accepting the
                // proposal records the proposal AS the decision (§6).
                'final_scale_level_id' => $decision['scale_level_id'],
                'final_value' => $decision['value'],
                // Kept when given, for the pedagogical record. No longer a
                // precondition for deciding differently (§7).
                'override_reason' => $written,
                'overridden_by' => $decision['is_override'] ? $teacher->id : null,
                'overridden_at' => $decision['is_override'] ? now() : null,
            ])->save();

            // Audit (§22.5): a confirmation, and — when the teacher decided
            // differently — the change, each carrying what was proposed, what was
            // decided, and the observation if there was one.
            $this->audit->record(
                $decision['is_override'] ? 'classification.overridden' : 'classification.confirmed',
                $locked,
                $teacher,
                $decision['is_override']
                    ? "Classificação alterada de {$this->proposalReadable($locked)} para {$decision['readable']}."
                    : "Classificação confirmada em {$decision['readable']}.",
                [
                    'proposed_value' => $locked->proposed_value,
                    'proposed_scale_level_id' => $locked->proposed_scale_level_id,
                    'final_value' => $locked->final_value,
                    'final_scale_level_id' => $locked->final_scale_level_id,
                    'override_reason' => $locked->override_reason,
                    'snapshot_id' => $snapshot->id,
                ],
            );

            return $locked;
        });
    }

    /**
     * What the teacher decided, expressed on the class's own scale.
     *
     * @return array{scale_level_id: int|null, value: string|null, is_override: bool, readable: string}
     */
    protected function decide(Classification $locked, ?int $finalScaleLevelId, ?string $finalValue): array
    {
        $scale = $this->scaleFor($locked);
        $asked = $finalScaleLevelId !== null || ($finalValue !== null && trim($finalValue) !== '');

        // Nothing chosen means «use the proposal» — an explicit act by the
        // teacher, arriving here as an explicit confirmation, and written down
        // rather than inferred later from the proposal columns (§6, §11).
        if (! $asked) {
            return [
                'scale_level_id' => $locked->proposed_scale_level_id,
                'value' => $this->proposalValue($locked),
                'is_override' => false,
                'readable' => $this->proposalReadable($locked),
            ];
        }

        if ($scale === null) {
            throw ClassificationDecisionException::withoutScale();
        }

        return $scale->classifiesByLevel()
            ? $this->decideByLevel($scale, $locked, $finalScaleLevelId)
            : $this->decideByValue($scale, $locked, $finalValue);
    }

    /**
     * @return array{scale_level_id: int|null, value: string|null, is_override: bool, readable: string}
     */
    protected function decideByLevel(Scale $scale, Classification $locked, ?int $finalScaleLevelId): array
    {
        // A level of THIS scale. Another scale's band, or an id that is nothing
        // at all, is not a classification this class can carry.
        $level = $finalScaleLevelId === null
            ? null
            : ScaleLevel::query()->where('scale_id', $scale->id)->whereKey($finalScaleLevelId)->first();

        if ($level === null) {
            throw ClassificationDecisionException::levelNotOnScale();
        }

        return [
            'scale_level_id' => $level->id,
            // The level's own number, so the row never says «level 4» in one
            // column and something else in the other. A purely qualitative
            // level has none, and null is then the truthful answer (§10.4).
            'value' => $level->numeric_value === null ? null : (string) $level->numeric_value,
            'is_override' => $level->id !== $locked->proposed_scale_level_id,
            'readable' => "{$level->code} — {$level->label}",
        ];
    }

    /**
     * @return array{scale_level_id: int|null, value: string|null, is_override: bool, readable: string}
     */
    protected function decideByValue(Scale $scale, Classification $locked, ?string $finalValue): array
    {
        $value = trim((string) $finalValue);

        // The scale's own limits decide what is valid on it — never a range
        // written down here.
        if (Bc::compare(Bc::of($value), Bc::of((string) $scale->min_value)) < 0
            || Bc::compare(Bc::of($value), Bc::of((string) $scale->max_value)) > 0) {
            throw ClassificationDecisionException::outsideScale((string) $scale->min_value, (string) $scale->max_value);
        }

        $proposed = $this->proposalValue($locked);

        return [
            'scale_level_id' => null,
            'value' => $value,
            'is_override' => $proposed === null || Bc::compare(Bc::of($value), Bc::of($proposed)) !== 0,
            'readable' => $value,
        ];
    }

    /** The proposal as a teacher reads it — «3 — Suficiente», «16», «—». */
    protected function proposalReadable(Classification $classification): string
    {
        $level = $classification->proposedScaleLevel;

        if ($level !== null) {
            return "{$level->code} — {$level->label}";
        }

        return $this->proposalValue($classification) ?? '—';
    }

    /**
     * The proposal read on the class's scale — a 3, a 16 — and never the
     * normalized percentage that produced it.
     */
    protected function proposalValue(Classification $classification): ?string
    {
        $scale = $this->scaleFor($classification);

        if ($scale === null) {
            return null;
        }

        if ($scale->classifiesByLevel()) {
            $level = $classification->proposedScaleLevel;

            return $level?->numeric_value === null ? null : (string) $level->numeric_value;
        }

        $version = $classification->enrollment->schoolClass->profileVersion;

        $proposal = $this->proposals->resolve(
            $scale,
            $classification->proposed_scale_level_id,
            $classification->proposed_normalized_value,
            $classification->proposed_value,
            $version->rounding_mode ?? 'half_up',
            $version->rounding_scale ?? 0,
        );

        // A qualitative descriptor is a name, not a value: it cannot go into a
        // decimal column, and saying so is better than coercing it to zero.
        return $proposal->isResolved() && is_numeric((string) $proposal->value) ? $proposal->value : null;
    }

    protected function scaleFor(Classification $classification): ?Scale
    {
        return $classification->enrollment->schoolClass->profileVersion?->scale()->with('levels')->first();
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
