<?php

namespace App\Support\Assessment;

use App\Models\Classification;
use App\Models\Scale;
use App\Services\Assessment\DecisionScaleReader;

/**
 * What the teacher decided, ready to read: the level's own code with its
 * qualitative mention, or the value written on an interval scale. Shared by
 * Classificações (and its rectification history) and by the head's
 * «Retificações» page, so the two cannot word the same decision differently.
 */
final class DecisionPayload
{
    public function __construct(
        private readonly DecisionScaleReader $decisionScales,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(Classification $classification, ?Scale $currentScale): ?array
    {
        $level = $classification->finalScaleLevel;

        if ($level !== null) {
            return [
                'code' => (string) $level->code,
                'label' => (string) $level->label,
                'scale_level_id' => (int) $level->id,
                // A level is read as the level it is, on whatever scale.
                'is_percentage' => false,
                'scale_name' => null,
            ];
        }

        if ($classification->final_value === null) {
            return null;
        }

        // An interval scale has no band to name: the number is the whole answer,
        // on the scale the decision was TAKEN on — which after a profile
        // migration is not necessarily the class's scale today.
        $taken = $this->decisionScales->read($classification, $currentScale);

        return [
            'code' => (string) $classification->final_value,
            'label' => null,
            'scale_level_id' => null,
            'is_percentage' => $taken['is_percentage'],
            // Filled only when it differs from the class's scale now.
            'scale_name' => $taken['foreign_scale'],
        ];
    }
}
