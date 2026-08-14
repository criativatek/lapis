<?php

namespace App\Models;

/**
 * Who an intervention was directed at (§4 of the module brief). The
 * participants themselves live in the intervention_enrollment pivot; this says
 * how many of them the record is required to have.
 */
enum InterventionTargetType: string
{
    case Student = 'student';
    case Group = 'group';
    case SchoolClass = 'class';

    public function label(): string
    {
        return match ($this) {
            self::Student => __('Aluno'),
            self::Group => __('Grupo'),
            self::SchoolClass => __('Turma'),
        };
    }

    /**
     * Whether a participant count is valid for this target. A class-wide
     * intervention names no individual students — the class IS the target, so
     * listing every enrollment would be redundant and would rot as soon as a
     * student joined or left.
     */
    public function acceptsParticipantCount(int $count): bool
    {
        return match ($this) {
            self::Student => $count === 1,
            self::Group => $count >= 2,
            self::SchoolClass => $count === 0,
        };
    }
}
