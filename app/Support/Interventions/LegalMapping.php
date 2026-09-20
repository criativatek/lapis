<?php

namespace App\Support\Interventions;

use App\Models\EvaluationAdaptationCode;
use App\Models\LegalMappingMode;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;

/**
 * What the catalogue knows about one intervention type's legal framing — the
 * single source of truth the controller, the UI payload and the tests all read
 * (§14 of the module brief). Nothing here is a decision yet: it is what the app
 * may propose, and under which of the four modes.
 */
final readonly class LegalMapping
{
    /**
     * @param  SupportMeasureLevel|null  $levelOverride  the level THIS framework
     *                                                   version puts the measure at, when it differs from the level in force
     *                                                   today. A framework that reclassifies a measure passes it; the one
     *                                                   encoded today never needs to, because it IS today.
     */
    private function __construct(
        public LegalMappingMode $mode,
        public ?SupportMeasureCode $measure = null,
        public ?EvaluationAdaptationCode $evaluationAdaptation = null,
        private ?SupportMeasureLevel $levelOverride = null,
    ) {}

    /** Ordinary practice: nothing to propose. */
    public static function none(): self
    {
        return new self(LegalMappingMode::None);
    }

    /** Unambiguous: the app fills this in without asking. */
    public static function direct(SupportMeasureCode $measure, ?SupportMeasureLevel $level = null): self
    {
        return new self(LegalMappingMode::Direct, measure: $measure, levelOverride: $level);
    }

    /** Plausible but not certain: offered as a suggestion, never stored unconfirmed. */
    public static function contextual(SupportMeasureCode $measure, ?SupportMeasureLevel $level = null): self
    {
        return new self(LegalMappingMode::Contextual, measure: $measure, levelOverride: $level);
    }

    /** An assessment adaptation, deliberately without a measure level. */
    public static function evaluation(EvaluationAdaptationCode $code): self
    {
        return new self(LegalMappingMode::EvaluationOnly, evaluationAdaptation: $code);
    }

    /**
     * The measure's level, derived from the measure itself so the pair can
     * never disagree. Null for evaluation adaptations and unmapped types —
     * absence here is meaningful, not missing data (§12.3).
     */
    public function level(): ?SupportMeasureLevel
    {
        return $this->levelOverride ?? $this->measure?->currentPortugueseLevel();
    }

    /**
     * Whether the app may write this framing without the teacher acting. True
     * only for Direct and EvaluationOnly: a Contextual mapping stays a
     * suggestion until confirmed (§12.2).
     */
    public function isAppliedAutomatically(): bool
    {
        return $this->mode === LegalMappingMode::Direct || $this->mode === LegalMappingMode::EvaluationOnly;
    }

    /**
     * The proposal shown to the teacher, or null when there is nothing to
     * propose. Used for both the automatic framing and the suggestion prompt —
     * they differ in what the app does with it, not in how it reads.
     *
     * @return array{mode: string, level: ?string, level_label: ?string, measure: ?string, measure_label: ?string, evaluation_adaptation: ?string, evaluation_adaptation_label: ?string}|null
     */
    public function toPayload(): ?array
    {
        if ($this->mode === LegalMappingMode::None) {
            return null;
        }

        return [
            'mode' => $this->mode->value,
            'level' => $this->level()?->value,
            'level_label' => $this->level()?->label(),
            'measure' => $this->measure?->value,
            'measure_label' => $this->measure?->label(),
            'evaluation_adaptation' => $this->evaluationAdaptation?->value,
            'evaluation_adaptation_label' => $this->evaluationAdaptation?->label(),
        ];
    }
}
