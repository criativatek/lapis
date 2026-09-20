<?php

namespace App\Models;

/**
 * The stable identity of a support measure a teacher may frame an intervention
 * under.
 *
 * A code here is a NAME, not a classification. Which level a measure sits at,
 * which article names it, and whether it is still in force are all properties
 * of a legal framework VERSION, and live in the framework — see
 * InterventionLegalFramework::levelFor() and ::legalReferenceFor(). That
 * separation is what lets a diploma move a measure between levels, or revoke
 * it, without rewriting a single stored row (§2).
 *
 * Codes are stable: renaming a label is safe, changing a code is not, since the
 * code is what history stores.
 *
 * The list is no longer deliberately partial. It now names every measure of
 * Decreto-Lei n.º 54/2018 (articles 8.º, 9.º and 10.º, in the wording given by
 * Lei n.º 116/2019 and Decreto-Lei n.º 62/2023), because a catalogue that can
 * only express two thirds of the measures in force makes the missing third
 * unrecordable — a worse failure than offering a measure some teacher never
 * uses. Nothing speculative is here: no measure from the announced revision of
 * the regime appears, and none will until its final text exists.
 */
enum SupportMeasureCode: string
{
    // Article 8.º — universal, in the order the diploma lists them.
    case PedagogicalDifferentiation = 'pedagogical_differentiation';
    case CurricularAccommodation = 'curricular_accommodation';
    case CurricularEnrichment = 'curricular_enrichment';
    case ProSocialBehaviourPromotion = 'pro_social_behaviour_promotion';
    case AcademicFocusSmallGroup = 'academic_focus_small_group';

    // Article 9.º — selective.
    case DifferentiatedCurricularPaths = 'differentiated_curricular_paths';
    case NonSignificantCurricularAdaptation = 'non_significant_curricular_adaptation';
    case PsychopedagogicalSupport = 'psychopedagogical_support';
    case AnticipationLearningReinforcement = 'anticipation_learning_reinforcement';
    case TutorialSupport = 'tutorial_support';

    // Article 10.º — additional.
    case SubjectBySubjectYearAttendance = 'subject_by_subject_year_attendance';
    case SignificantCurricularAdaptation = 'significant_curricular_adaptation';
    case IndividualTransitionPlan = 'individual_transition_plan';
    case StructuredTeachingMethodologies = 'structured_teaching_methodologies';
    case PersonalSocialAutonomySkills = 'personal_social_autonomy_skills';

    /**
     * The name shown on screen.
     *
     * Close to the diploma's own wording but not bound to it: the legal
     * designation, verbatim, is carried by LegalReference::$designation, so the
     * UI may read naturally while a report can still quote the law exactly.
     */
    public function label(): string
    {
        return match ($this) {
            self::PedagogicalDifferentiation => __('Diferenciação pedagógica'),
            self::CurricularAccommodation => __('Acomodações curriculares'),
            self::CurricularEnrichment => __('Enriquecimento curricular'),
            self::ProSocialBehaviourPromotion => __('Promoção do comportamento pró-social'),
            self::AcademicFocusSmallGroup => __('Intervenção com foco académico ou comportamental em pequenos grupos'),

            self::DifferentiatedCurricularPaths => __('Percursos curriculares diferenciados'),
            self::NonSignificantCurricularAdaptation => __('Adaptações curriculares não significativas'),
            self::PsychopedagogicalSupport => __('Apoio psicopedagógico'),
            self::AnticipationLearningReinforcement => __('Antecipação e reforço das aprendizagens'),
            self::TutorialSupport => __('Apoio tutorial'),

            self::SubjectBySubjectYearAttendance => __('Frequência do ano de escolaridade por disciplinas'),
            self::SignificantCurricularAdaptation => __('Adaptações curriculares significativas'),
            self::IndividualTransitionPlan => __('Plano individual de transição'),
            self::StructuredTeachingMethodologies => __('Desenvolvimento de metodologias e estratégias de ensino estruturado'),
            self::PersonalSocialAutonomySkills => __('Desenvolvimento de competências de autonomia pessoal e social'),
        };
    }

    /**
     * The level this measure sits at under the regime CURRENTLY in force in
     * Portugal.
     *
     * Kept as the convenience for the one framework that exists today, and used
     * by PortugalInclusiveEducationFramework as its own answer. It is not the
     * authority: anything that must be right for a past record, or for another
     * jurisdiction, asks the applicable framework instead, because only the
     * framework knows which regime applied. Reading a historical record through
     * this method would reclassify it the day the law changes — the exact
     * failure this module is built to prevent.
     */
    public function currentPortugueseLevel(): SupportMeasureLevel
    {
        return match ($this) {
            self::PedagogicalDifferentiation,
            self::CurricularAccommodation,
            self::CurricularEnrichment,
            self::ProSocialBehaviourPromotion,
            self::AcademicFocusSmallGroup => SupportMeasureLevel::Universal,

            self::DifferentiatedCurricularPaths,
            self::NonSignificantCurricularAdaptation,
            self::PsychopedagogicalSupport,
            self::AnticipationLearningReinforcement,
            self::TutorialSupport => SupportMeasureLevel::Selective,

            self::SubjectBySubjectYearAttendance,
            self::SignificantCurricularAdaptation,
            self::IndividualTransitionPlan,
            self::StructuredTeachingMethodologies,
            self::PersonalSocialAutonomySkills => SupportMeasureLevel::Additional,
        };
    }

    /**
     * The level under the regime in force today.
     *
     * Prefer InterventionLegalFramework::levelFor() wherever a framework is in
     * hand — it is the versioned answer. This stays because a handful of
     * callers legitimately mean "today", and because the pair (level, code) is
     * validated for coherence before anything is written.
     */
    public function level(): SupportMeasureLevel
    {
        return $this->currentPortugueseLevel();
    }

    /**
     * @return list<self>
     */
    public static function forLevel(SupportMeasureLevel $level): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case) => $case->currentPortugueseLevel() === $level,
        ));
    }
}
