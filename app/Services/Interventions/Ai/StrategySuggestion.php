<?php

namespace App\Services\Interventions\Ai;

/**
 * One proposed strategy, in the shape the Acompanhamento do Aluno brief asks
 * for (§13): finalidade, objetivo, estratégia, frequência, duração,
 * indicador, revisão.
 *
 * A PROPOSAL, NEVER A RECORD. Nothing in this application constructs an
 * `Intervention` from one of these — turning a suggestion into a real
 * intervention is a decision only the teacher takes, through the same form
 * that already exists, which is why this class has no `save()` and no
 * relationship to the Intervention model at all.
 */
readonly class StrategySuggestion
{
    public function __construct(
        public ?string $name,
        /** Always the purpose explicitly selected by the teacher. */
        public string $purpose,
        public string $objective,
        public string $strategy,
        public ?string $frequency,
        public ?string $duration,
        public ?string $trackingIndicator,
        public ?string $reviewSuggestion,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'purpose' => $this->purpose,
            'objective' => $this->objective,
            'strategy' => $this->strategy,
            'frequency' => $this->frequency,
            'duration' => $this->duration,
            'tracking_indicator' => $this->trackingIndicator,
            'review_suggestion' => $this->reviewSuggestion,
        ];
    }
}
