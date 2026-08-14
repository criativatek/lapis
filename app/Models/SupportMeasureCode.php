<?php

namespace App\Models;

/**
 * The specific support measure an intervention was framed under, within its
 * SupportMeasureLevel. Only the measures the catalogue actually maps to today
 * are listed — this enum is deliberately NOT a transcription of the whole
 * legal framework, because a value here is only ever written when a teacher
 * accepted or chose it, and inventing pedagogical categories nobody approved
 * is exactly what the project forbids.
 *
 * Codes are stable: renaming a label is safe, changing a code is not, since
 * the code is what history stores.
 */
enum SupportMeasureCode: string
{
    // Universal.
    case PedagogicalDifferentiation = 'pedagogical_differentiation';
    case CurricularAccommodation = 'curricular_accommodation';
    case AcademicFocusSmallGroup = 'academic_focus_small_group';

    // Selective.
    case NonSignificantCurricularAdaptation = 'non_significant_curricular_adaptation';
    case AnticipationLearningReinforcement = 'anticipation_learning_reinforcement';
    case PsychopedagogicalSupport = 'psychopedagogical_support';
    case TutorialSupport = 'tutorial_support';

    // Additional.
    case SignificantCurricularAdaptation = 'significant_curricular_adaptation';
    case PersonalSocialAutonomySkills = 'personal_social_autonomy_skills';

    public function label(): string
    {
        return match ($this) {
            self::PedagogicalDifferentiation => __('Diferenciação pedagógica'),
            self::CurricularAccommodation => __('Acomodação curricular'),
            self::AcademicFocusSmallGroup => __('Intervenção com foco académico em pequeno grupo'),
            self::NonSignificantCurricularAdaptation => __('Adaptação curricular não significativa'),
            self::AnticipationLearningReinforcement => __('Antecipação e reforço das aprendizagens'),
            self::PsychopedagogicalSupport => __('Apoio psicopedagógico'),
            self::TutorialSupport => __('Apoio tutorial'),
            self::SignificantCurricularAdaptation => __('Adaptação curricular significativa'),
            self::PersonalSocialAutonomySkills => __('Desenvolvimento de competências de autonomia pessoal e social'),
        };
    }

    /**
     * The level this measure belongs to. A measure never floats free of its
     * level, so the pair is always consistent no matter which of the two the
     * caller happens to have.
     */
    public function level(): SupportMeasureLevel
    {
        return match ($this) {
            self::PedagogicalDifferentiation,
            self::CurricularAccommodation,
            self::AcademicFocusSmallGroup => SupportMeasureLevel::Universal,

            self::NonSignificantCurricularAdaptation,
            self::AnticipationLearningReinforcement,
            self::PsychopedagogicalSupport,
            self::TutorialSupport => SupportMeasureLevel::Selective,

            self::SignificantCurricularAdaptation,
            self::PersonalSocialAutonomySkills => SupportMeasureLevel::Additional,
        };
    }

    /**
     * @return list<self>
     */
    public static function forLevel(SupportMeasureLevel $level): array
    {
        return array_values(array_filter(self::cases(), fn (self $case) => $case->level() === $level));
    }
}
