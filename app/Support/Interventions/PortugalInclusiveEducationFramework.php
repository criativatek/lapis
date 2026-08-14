<?php

namespace App\Support\Interventions;

use App\Models\EvaluationAdaptationCode;
use App\Models\InterventionType;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use Carbon\CarbonInterface;

/**
 * Portugal's inclusive-education regime as currently implemented — one
 * implementation of InterventionLegalFramework, not the core of the module.
 *
 * Everything Portuguese lives here: the measure levels (universal, seletiva,
 * adicional), the measures themselves, the assessment adaptations, and the
 * reading of each pedagogical type in their terms. Nothing outside this class
 * needs to know any of it, which is what lets another jurisdiction be added
 * without touching the catalogue, the model, the controller or the UI.
 *
 * `coversDate()` returns true unconditionally: this is the only Portuguese
 * version encoded, so it answers for the whole timeline. The revision approved
 * in 2026 with effect announced for 2027 is deliberately NOT encoded here —
 * it will be a second framework with its own effective dates, added once the
 * final text exists. Writing it from a preliminary draft would put speculative
 * law in front of teachers.
 *
 * None of the match() arms has a default. A new intervention type without a
 * decided reading fails the tests rather than silently defaulting to "no legal
 * framing", which is the mistake that would be hardest to notice.
 */
final class PortugalInclusiveEducationFramework implements InterventionLegalFramework
{
    public function code(): string
    {
        return 'pt-inclusive-education-current';
    }

    public function jurisdiction(): string
    {
        return 'PT';
    }

    public function coversDate(CarbonInterface $date): bool
    {
        return true;
    }

    public function hasLegalTaxonomy(): bool
    {
        return true;
    }

    /**
     * The reading of each pedagogical type under this regime.
     *
     * «Apoio individualizado» and «Promoção da autonomia» map to None on
     * purpose: they are ordinary teaching strategies whose everyday meaning is
     * far broader than any formal measure, so equating them automatically would
     * put words in the teacher's mouth. The formal measures they are sometimes
     * confused with exist as their own types — «Apoio psicopedagógico», «Apoio
     * tutorial» and «Desenvolvimento de competências de autonomia pessoal e
     * social» — chosen deliberately, and those do carry a direct mapping.
     */
    public function mappingFor(InterventionType $type): LegalMapping
    {
        return match ($type) {
            // Direct: the type IS the measure.
            InterventionType::PedagogicalDifferentiation => LegalMapping::direct(SupportMeasureCode::PedagogicalDifferentiation),
            InterventionType::CurricularAccommodation => LegalMapping::direct(SupportMeasureCode::CurricularAccommodation),
            InterventionType::NonSignificantCurricularAdaptation => LegalMapping::direct(SupportMeasureCode::NonSignificantCurricularAdaptation),
            InterventionType::SignificantCurricularAdaptation => LegalMapping::direct(SupportMeasureCode::SignificantCurricularAdaptation),
            InterventionType::PsychopedagogicalSupport => LegalMapping::direct(SupportMeasureCode::PsychopedagogicalSupport),
            InterventionType::TutorialSupport => LegalMapping::direct(SupportMeasureCode::TutorialSupport),
            InterventionType::PersonalSocialAutonomySkills => LegalMapping::direct(SupportMeasureCode::PersonalSocialAutonomySkills),

            // Contextual: plausible, but also ordinary practice — suggestion only.
            InterventionType::LearningReinforcement => LegalMapping::contextual(SupportMeasureCode::AnticipationLearningReinforcement),
            InterventionType::SmallGroupSupport => LegalMapping::contextual(SupportMeasureCode::AcademicFocusSmallGroup),

            // Assessment adaptations: a code, never a measure level.
            InterventionType::StatementReading => LegalMapping::evaluation(EvaluationAdaptationCode::StatementReading),
            InterventionType::AssessmentInstrumentAdaptation => LegalMapping::evaluation(EvaluationAdaptationCode::InstrumentAdaptation),
            InterventionType::ExtraTime => LegalMapping::evaluation(EvaluationAdaptationCode::ExtraTime),
            InterventionType::SeparateRoomAssessment => LegalMapping::evaluation(EvaluationAdaptationCode::SeparateRoom),
            InterventionType::DirectAnswerQuestions => LegalMapping::evaluation(EvaluationAdaptationCode::DirectAnswerQuestions),

            // Ordinary pedagogical practice.
            InterventionType::IndividualSupport,
            InterventionType::AutonomyPromotion,
            InterventionType::StatementInterpretationSupport,
            InterventionType::TextInterpretationSupport,
            InterventionType::WritingOrganizationSupport,
            InterventionType::TextPlanningSupport,
            InterventionType::TextRevisionSupport,
            InterventionType::GrammarSystematisation,
            InterventionType::OralSkillsSupport,
            InterventionType::StudyOrganizationSupport,
            InterventionType::TimeManagementSupport,
            InterventionType::AttentionRegulation,
            InterventionType::BehaviourRegulation,
            InterventionType::ClassIntegration,
            InterventionType::Other => LegalMapping::none(),
        };
    }

    /**
     * @return array<int, array{value: string, label: string, measures: array<int, array{value: string, label: string}>}>
     */
    public function supportMeasureLevels(): array
    {
        return array_map(fn (SupportMeasureLevel $level) => [
            'value' => $level->value,
            'label' => $level->label(),
            'measures' => array_map(
                fn (SupportMeasureCode $measure) => ['value' => $measure->value, 'label' => $measure->label()],
                SupportMeasureCode::forLevel($level),
            ),
        ], SupportMeasureLevel::cases());
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function evaluationAdaptations(): array
    {
        return array_map(
            fn (EvaluationAdaptationCode $code) => ['value' => $code->value, 'label' => $code->label()],
            EvaluationAdaptationCode::cases(),
        );
    }
}
