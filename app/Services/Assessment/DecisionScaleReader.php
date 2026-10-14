<?php

namespace App\Services\Assessment;

use App\Models\AssessmentProfileVersion;
use App\Models\Classification;
use App\Models\Scale;

/**
 * THE ONE PLACE that answers «on which scale was this decision taken?».
 *
 * A decision stored as a bare value (`final_scale_level_id` NULL, `final_value`
 * set) is a number on the scale of the profile version that PRODUCED the
 * classification (`classifications.assessment_profile_version_id`) — not on the
 * scale the class happens to be assessed on today. A class migrated from
 * «Percentagem (0 a 100)» to «Escala 1 a 5» keeps its published 65 as 65 %, and
 * reading it as «nível 65» of a 1–5 scale would be saying something the teacher
 * never decided.
 *
 * This only PRESENTS. It never converts the number, never recalculates it and
 * never reinterprets it on another scale. A decision made on a level keeps being
 * shown by the level, as before.
 *
 * Versions are cached by id: the classifications of one class almost always
 * share a version, and asking for it per row would be a query per student.
 */
class DecisionScaleReader
{
    /** @var array<int, Scale|null> version id → the scale of that version */
    protected array $scaleByVersion = [];

    /**
     * @return array{is_percentage: bool, foreign_scale: string|null}
     */
    public function read(Classification $classification, ?Scale $currentScale): array
    {
        $scale = $this->decidedScale($classification);

        if ($scale === null) {
            return ['is_percentage' => false, 'foreign_scale' => null];
        }

        return [
            'is_percentage' => $scale->kind === 'percentage',
            'foreign_scale' => $currentScale !== null && $currentScale->id !== $scale->id ? (string) $scale->name : null,
        ];
    }

    /**
     * The scale a VALUE decision was taken on; null when the classification has
     * no such decision (undecided, or decided by level — the level says it all).
     */
    public function decidedScale(Classification $classification): ?Scale
    {
        if ($classification->final_scale_level_id !== null || $classification->final_value === null) {
            return null;
        }

        return $this->scaleOfVersion((int) $classification->assessment_profile_version_id);
    }

    /**
     * A decided value written as a percentage: «65.000» is «65%». The number is
     * only trimmed of its storage zeros, never changed, and the sign is the
     * convention every screen already uses for a proposal on this kind of scale.
     */
    public static function percentText(string $value): string
    {
        $trimmed = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;

        return ($trimmed === '' || $trimmed === '-' ? '0' : $trimmed).'%';
    }

    public function scaleOfVersion(int $versionId): ?Scale
    {
        if (! array_key_exists($versionId, $this->scaleByVersion)) {
            $this->scaleByVersion[$versionId] = AssessmentProfileVersion::query()->find($versionId)?->scale;
        }

        return $this->scaleByVersion[$versionId];
    }
}
