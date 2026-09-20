<?php

namespace App\Support\Characterisation;

/**
 * The sections a pedagogical characterisation is written in.
 *
 * Four of them — strengths, interests, needs, barriers — are deliberately the
 * words the inclusive-education framework uses. That is not an implementation
 * of it: nothing here is a legal determination, nothing is required, and
 * nothing feeds a calculation. It is only the care not to pick a structure that
 * would make the text unusable if it ever has to be reused.
 */
enum CharacterisationSection: string
{
    case Summary = 'summary';
    case Strengths = 'strengths';
    case Interests = 'interests';
    case Needs = 'needs';
    case Barriers = 'barriers';
    case Participation = 'participation';

    public function label(): string
    {
        return match ($this) {
            self::Summary => __('Caracterização'),
            self::Strengths => __('Potencialidades'),
            self::Interests => __('Interesses'),
            self::Needs => __('Necessidades'),
            self::Barriers => __('Barreiras à aprendizagem e à inclusão'),
            self::Participation => __('Participação'),
        };
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
