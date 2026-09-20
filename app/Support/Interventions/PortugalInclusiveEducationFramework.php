<?php

namespace App\Support\Interventions;

use App\Models\CatalogueFamily;
use App\Models\EvaluationAdaptationCode;
use App\Models\InterventionType;
use App\Models\LegalFrameworkStatus;
use App\Models\LegalMappingMode;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use Carbon\CarbonImmutable;
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
    /**
     * Named for the diploma, not for its position in time. The previous code
     * was 'pt-inclusive-education-current', which would have become a lie the
     * day a second version arrived — and worse, a lie stamped onto rows.
     * Nothing has stored it yet, so renaming it now is free; after the first
     * snapshot is written it would not be.
     */
    public function code(): string
    {
        return 'pt-inclusive-education-2018';
    }

    public function title(): string
    {
        return __('Regime jurídico da educação inclusiva');
    }

    public function legalReference(): string
    {
        return __('Decreto-Lei n.º 54/2018, de 6 de julho, alterado pela Lei n.º 116/2019, de 13 de setembro, e pelo Decreto-Lei n.º 62/2023, de 25 de julho');
    }

    public function validFrom(): CarbonInterface
    {
        return CarbonImmutable::parse('2018-07-07');
    }

    public function validUntil(): ?CarbonInterface
    {
        return null;
    }

    public function status(): LegalFrameworkStatus
    {
        return LegalFrameworkStatus::Active;
    }

    public function jurisdiction(): string
    {
        return 'PT';
    }

    /**
     * True for the whole timeline, deliberately.
     *
     * This is the only Portuguese version encoded, so it has to answer for
     * interventions dated before 2018-07-07 too — a record imported from an
     * earlier year must still resolve to something rather than silently losing
     * its framing. The day a second version exists this becomes a real
     * comparison against validFrom()/validUntil(), and the earlier regime gets
     * a framework of its own; until then, narrowing it would only open a hole.
     */
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
            InterventionType::ClassificationSupportInstruments => LegalMapping::evaluation(EvaluationAdaptationCode::ClassificationSupportInstruments),

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
     * How this framework reads each pedagogical type.
     *
     * A Direct mapping means the type IS a named measure, so it is a legal
     * measure. Contextual does NOT: «Apoio em pequeno grupo» is good teaching
     * that a teacher may or may not have mobilised as a measure, and calling it
     * one before the teacher confirms would be the app deciding a legal fact.
     * A contextual type therefore stays a pedagogical strategy.
     */
    public function familyFor(InterventionType $type): CatalogueFamily
    {
        return match ($this->mappingFor($type)->mode) {
            LegalMappingMode::Direct => CatalogueFamily::LegalMeasure,
            LegalMappingMode::EvaluationOnly => CatalogueFamily::EvaluationAdaptation,
            LegalMappingMode::Contextual,
            LegalMappingMode::None => CatalogueFamily::PedagogicalStrategy,
        };
    }

    /**
     * Narrowed to non-null against the interface, and that narrowing is the
     * claim: under this version every measure in the catalogue is named by the
     * diploma, so there is no measure it cannot place. A later version will
     * return null for one it revoked — which is what the interface's nullable
     * return type is for.
     */
    public function levelFor(SupportMeasureCode $measure): SupportMeasureLevel
    {
        return $measure->currentPortugueseLevel();
    }

    /**
     * Article, número and alínea for each measure, with the diploma's own
     * wording as `designation`.
     *
     * The alíneas follow the order the diploma prints them, which is why the
     * enum cases are declared in that order rather than alphabetically.
     */
    public function legalReferenceFor(SupportMeasureCode $measure): LegalReference
    {
        return match ($measure) {
            SupportMeasureCode::PedagogicalDifferentiation => new LegalReference('8.º', '2', 'a)', 'A diferenciação pedagógica'),
            SupportMeasureCode::CurricularAccommodation => new LegalReference('8.º', '2', 'b)', 'As acomodações curriculares'),
            SupportMeasureCode::CurricularEnrichment => new LegalReference('8.º', '2', 'c)', 'O enriquecimento curricular'),
            SupportMeasureCode::ProSocialBehaviourPromotion => new LegalReference('8.º', '2', 'd)', 'A promoção do comportamento pró-social'),
            SupportMeasureCode::AcademicFocusSmallGroup => new LegalReference('8.º', '2', 'e)', 'A intervenção com foco académico ou comportamental em pequenos grupos'),

            SupportMeasureCode::DifferentiatedCurricularPaths => new LegalReference('9.º', '2', 'a)', 'Os percursos curriculares diferenciados'),
            SupportMeasureCode::NonSignificantCurricularAdaptation => new LegalReference('9.º', '2', 'b)', 'As adaptações curriculares não significativas'),
            SupportMeasureCode::PsychopedagogicalSupport => new LegalReference('9.º', '2', 'c)', 'O apoio psicopedagógico'),
            SupportMeasureCode::AnticipationLearningReinforcement => new LegalReference('9.º', '2', 'd)', 'A antecipação e o reforço das aprendizagens'),
            SupportMeasureCode::TutorialSupport => new LegalReference('9.º', '2', 'e)', 'O apoio tutorial'),

            SupportMeasureCode::SubjectBySubjectYearAttendance => new LegalReference('10.º', '4', 'a)', 'A frequência do ano de escolaridade por disciplinas'),
            SupportMeasureCode::SignificantCurricularAdaptation => new LegalReference('10.º', '4', 'b)', 'As adaptações curriculares significativas'),
            SupportMeasureCode::IndividualTransitionPlan => new LegalReference('10.º', '4', 'c)', 'O plano individual de transição'),
            SupportMeasureCode::StructuredTeachingMethodologies => new LegalReference('10.º', '4', 'd)', 'O desenvolvimento de metodologias e estratégias de ensino estruturado'),
            SupportMeasureCode::PersonalSocialAutonomySkills => new LegalReference('10.º', '4', 'e)', 'O desenvolvimento de competências de autonomia pessoal e social'),
        };
    }

    /**
     * Only measures this version still names are offered. A revoked or
     * superseded one keeps resolving for history — legalReferenceFor() still
     * answers for it — but never appears in a dropdown again.
     *
     * @return array<int, array{value: string, label: string, measures: array<int, array{value: string, label: string, article: string, citation: string, designation: string}>}>
     */
    public function supportMeasureLevels(): array
    {
        return array_map(function (SupportMeasureLevel $level) {
            $measures = array_values(array_filter(
                SupportMeasureCode::cases(),
                fn (SupportMeasureCode $measure) => $this->levelFor($measure) === $level
                    && $this->legalReferenceFor($measure)->status->isSelectable(),
            ));

            return [
                'value' => $level->value,
                'label' => $level->label(),
                'measures' => array_map(function (SupportMeasureCode $measure) {
                    $reference = $this->legalReferenceFor($measure);

                    return [
                        'value' => $measure->value,
                        'label' => $measure->label(),
                        'article' => $reference->article,
                        'citation' => $reference->citation(),
                        'designation' => $reference->designation,
                    ];
                }, $measures),
            ];
        }, SupportMeasureLevel::cases());
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
