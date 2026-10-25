<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Models\Classification;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Support\Assessment\ClassificationDecisionException;

/**
 * Turns what a teacher asked for into a decision valid on the class's own
 * scale (§3.3): a level of THAT scale, or a number inside its limits. It is the
 * one place those rules live, so that confirming, changing a decision before
 * publication and rectifying one after it can never disagree about what is a
 * valid classification.
 */
class DecisionResolver
{
    public function __construct(
        protected ScaleProposalResolver $proposals,
    ) {}

    /**
     * What the teacher decided, expressed on the class's own scale.
     *
     * @return array{scale_level_id: int|null, value: string|null, is_override: bool, readable: string}
     */
    public function resolve(Classification $locked, ?int $finalScaleLevelId, ?string $finalValue): array
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
            // Differing from a proposal that was never made is not differing
            // from anything.
            'is_override' => $locked->proposed_scale_level_id !== null
                && $level->id !== $locked->proposed_scale_level_id,
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
            // As above: with no proposal there is nothing to differ from.
            'is_override' => $proposed !== null && Bc::compare(Bc::of($value), Bc::of($proposed)) !== 0,
            'readable' => $value,
        ];
    }

    /** The proposal as a teacher reads it — «3 — Suficiente», «16», «—». */
    public function proposalReadable(Classification $classification): string
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
}
