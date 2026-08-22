<?php

namespace App\Models;

use App\Support\Interventions\InterventionLegalFramework;

/**
 * The catalogue of intervention types — what a teacher did, and which
 * transversal context it belongs to (§5, §6 of the module brief).
 *
 * **This enum is international and knows no law.** «Apoio à organização da
 * escrita» means the same thing in Lisbon and in Lyon; what differs is whether
 * some jurisdiction frames it legally, and that lives entirely behind
 * InterventionLegalFramework. There is deliberately no InterventionTypePortugal
 * and no InterventionType2027: legislation changes are a new framework, never a
 * fork of the pedagogical catalogue.
 *
 * The type code is the only thing an intervention stores. Context is derived
 * here, never persisted, exactly as EvidenceKind::group() derives the Registos
 * grouping — so a type's classification is defined in one place and history
 * cannot drift from it. That indirection is safe because the CODES are stable:
 * a label may be reworded freely, but changing or reusing a code rewrites the
 * meaning of every intervention already recorded under it (§17).
 *
 * Neither match() below has a default. Adding a case without deciding its
 * context is a test failure, not a silent fallback into "learning".
 */
enum InterventionType: string
{
    // Aprendizagem.
    case LearningReinforcement = 'learning_reinforcement';
    case IndividualSupport = 'individual_support';
    case SmallGroupSupport = 'small_group_support';
    case PedagogicalDifferentiation = 'pedagogical_differentiation';
    case CurricularAccommodation = 'curricular_accommodation';
    case NonSignificantCurricularAdaptation = 'non_significant_curricular_adaptation';
    case SignificantCurricularAdaptation = 'significant_curricular_adaptation';
    case PsychopedagogicalSupport = 'psychopedagogical_support';
    case TutorialSupport = 'tutorial_support';
    case StatementInterpretationSupport = 'statement_interpretation_support';
    case TextInterpretationSupport = 'text_interpretation_support';
    case WritingOrganizationSupport = 'writing_organization_support';
    case TextPlanningSupport = 'text_planning_support';
    case TextRevisionSupport = 'text_revision_support';
    case GrammarSystematisation = 'grammar_systematisation';
    case OralSkillsSupport = 'oral_skills_support';

    // Avaliação.
    case StatementReading = 'statement_reading';
    case AssessmentInstrumentAdaptation = 'assessment_instrument_adaptation';
    case ExtraTime = 'extra_time';
    case SeparateRoomAssessment = 'separate_room_assessment';
    case DirectAnswerQuestions = 'direct_answer_questions';

    // Métodos de estudo e autonomia.
    case StudyOrganizationSupport = 'study_organization_support';
    case TimeManagementSupport = 'time_management_support';
    case AutonomyPromotion = 'autonomy_promotion';
    case PersonalSocialAutonomySkills = 'personal_social_autonomy_skills';

    // Atenção e autorregulação.
    case AttentionRegulation = 'attention_regulation';

    // Comportamento.
    case BehaviourRegulation = 'behaviour_regulation';

    // Integração.
    case ClassIntegration = 'class_integration';

    // Outro.
    case Other = 'other';

    /**
     * Doubles as the intervention's visible title: the brief is explicit that a
     * teacher should never be made to type one (§3.1).
     */
    public function label(): string
    {
        return match ($this) {
            self::LearningReinforcement => __('Reforço das aprendizagens'),
            self::IndividualSupport => __('Apoio individualizado'),
            self::SmallGroupSupport => __('Apoio em pequeno grupo'),
            self::PedagogicalDifferentiation => __('Diferenciação pedagógica'),
            self::CurricularAccommodation => __('Acomodação curricular'),
            self::NonSignificantCurricularAdaptation => __('Adaptação curricular não significativa'),
            self::SignificantCurricularAdaptation => __('Adaptação curricular significativa'),
            self::PsychopedagogicalSupport => __('Apoio psicopedagógico'),
            self::TutorialSupport => __('Apoio tutorial'),
            self::StatementInterpretationSupport => __('Apoio à interpretação de enunciados'),
            self::TextInterpretationSupport => __('Apoio à interpretação textual'),
            self::WritingOrganizationSupport => __('Apoio à organização da escrita'),
            self::TextPlanningSupport => __('Apoio à planificação textual'),
            self::TextRevisionSupport => __('Apoio à revisão textual'),
            self::GrammarSystematisation => __('Sistematização gramatical'),
            self::OralSkillsSupport => __('Apoio à oralidade'),

            // Spelled out to keep it apart from «Apoio à interpretação de
            // enunciados», which is teaching, not an assessment adaptation. The
            // code stays `statement_reading`: history is anchored to the code,
            // never to the wording (§13.2).
            self::StatementReading => __('Leitura de enunciados em situação de avaliação'),
            self::AssessmentInstrumentAdaptation => __('Adaptação do elemento de avaliação'),
            // "Tempo suplementar", matching EvaluationAdaptationCode::ExtraTime,
            // so the same thing is not called two names across the screen. The
            // code stays `extra_time`.
            self::ExtraTime => __('Tempo suplementar em situação de avaliação'),
            self::SeparateRoomAssessment => __('Realização de prova em sala à parte'),
            self::DirectAnswerQuestions => __('Questões de resposta direta'),

            self::StudyOrganizationSupport => __('Apoio à organização do estudo'),
            self::TimeManagementSupport => __('Apoio à gestão do tempo'),
            self::AutonomyPromotion => __('Promoção da autonomia'),
            self::PersonalSocialAutonomySkills => __('Desenvolvimento de competências de autonomia pessoal e social'),

            self::AttentionRegulation => __('Regulação da atenção/concentração'),
            self::BehaviourRegulation => __('Regulação comportamental'),
            self::ClassIntegration => __('Integração/adaptação à turma'),
            self::Other => __('Outro'),
        };
    }

    /**
     * The transversal area this type belongs to — derived, never stored.
     */
    public function context(): InterventionContext
    {
        return match ($this) {
            self::LearningReinforcement,
            self::IndividualSupport,
            self::SmallGroupSupport,
            self::PedagogicalDifferentiation,
            self::CurricularAccommodation,
            self::NonSignificantCurricularAdaptation,
            self::SignificantCurricularAdaptation,
            self::PsychopedagogicalSupport,
            self::TutorialSupport,
            self::StatementInterpretationSupport,
            self::TextInterpretationSupport,
            self::WritingOrganizationSupport,
            self::TextPlanningSupport,
            self::TextRevisionSupport,
            self::GrammarSystematisation,
            self::OralSkillsSupport => InterventionContext::Learning,

            self::StatementReading,
            self::AssessmentInstrumentAdaptation,
            self::ExtraTime,
            self::SeparateRoomAssessment,
            self::DirectAnswerQuestions => InterventionContext::Evaluation,

            self::StudyOrganizationSupport,
            self::TimeManagementSupport,
            self::AutonomyPromotion,
            self::PersonalSocialAutonomySkills => InterventionContext::StudyAutonomy,

            self::AttentionRegulation => InterventionContext::AttentionSelfRegulation,
            self::BehaviourRegulation => InterventionContext::Behavior,
            self::ClassIntegration => InterventionContext::Integration,
            self::Other => InterventionContext::Other,
        };
    }

    /**
     * «Outro» carries no meaning of its own, so it is the one type where the
     * teacher must say what was done (§8).
     */
    public function requiresDescription(): bool
    {
        return $this === self::Other;
    }

    /**
     * The catalogue as the UI consumes it: every type with its label, its
     * derived context, and whatever the given framework proposes for it.
     *
     * The framework is a parameter rather than something this enum reaches for,
     * because the same type reads differently under different law — and under
     * NullLegalFramework it simply reads as itself, with no framing at all.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalogue(InterventionLegalFramework $framework): array
    {
        return array_map(fn (self $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'context' => $type->context()->value,
            'context_label' => $type->context()->label(),
            'requires_description' => $type->requiresDescription(),
            'legal_mapping' => $framework->mappingFor($type)->toPayload(),
        ], self::cases());
    }
}
