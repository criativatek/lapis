<?php

namespace Tests\Unit\Interventions;

use App\Models\EvaluationAdaptationCode;
use App\Models\InterventionContext;
use App\Models\InterventionTargetType;
use App\Models\InterventionType;
use App\Models\LegalMappingMode;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Support\Interventions\PortugalInclusiveEducationFramework;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The pedagogical catalogue, and how the Portuguese framework reads it.
 *
 * These tests exist so that reading can never change by accident: a support
 * measure is a pedagogical/legal rule, and silently reclassifying one would
 * rewrite the meaning of every intervention already recorded under that type.
 * They assert the PORTUGUESE reading specifically — another jurisdiction is
 * free to read the same types differently, which is exactly why the mapping
 * lives in a framework and not in InterventionType.
 */
class InterventionCatalogueTest extends TestCase
{
    private function framework(): PortugalInclusiveEducationFramework
    {
        return new PortugalInclusiveEducationFramework;
    }

    #[Test]
    public function every_type_resolves_a_context_and_a_legal_mapping(): void
    {
        // Both match() expressions are written without a default arm, so a new
        // case with no decision made throws here rather than defaulting into
        // "learning" or "no framing" — the two silent wrongs that would matter.
        foreach (InterventionType::cases() as $type) {
            $this->assertInstanceOf(InterventionContext::class, $type->context(), $type->value);
            $this->assertNotNull($this->framework()->mappingFor($type)->mode, $type->value);
        }
    }

    #[Test]
    public function the_direct_mappings_are_exactly_these_seven(): void
    {
        $direct = array_values(array_filter(
            InterventionType::cases(),
            fn (InterventionType $type) => $this->framework()->mappingFor($type)->mode === LegalMappingMode::Direct,
        ));

        $this->assertSame([
            InterventionType::PedagogicalDifferentiation,
            InterventionType::CurricularAccommodation,
            InterventionType::NonSignificantCurricularAdaptation,
            InterventionType::SignificantCurricularAdaptation,
            InterventionType::PsychopedagogicalSupport,
            InterventionType::TutorialSupport,
            InterventionType::PersonalSocialAutonomySkills,
        ], $direct);
    }

    #[Test]
    public function the_formal_measures_carry_their_documented_level(): void
    {
        $expected = [
            [InterventionType::PsychopedagogicalSupport, SupportMeasureCode::PsychopedagogicalSupport, SupportMeasureLevel::Selective, InterventionContext::Learning],
            [InterventionType::TutorialSupport, SupportMeasureCode::TutorialSupport, SupportMeasureLevel::Selective, InterventionContext::Learning],
            [InterventionType::PersonalSocialAutonomySkills, SupportMeasureCode::PersonalSocialAutonomySkills, SupportMeasureLevel::Additional, InterventionContext::StudyAutonomy],
        ];

        foreach ($expected as [$type, $measure, $level, $context]) {
            $mapping = $this->framework()->mappingFor($type);

            $this->assertSame($measure, $mapping->measure, $type->value);
            $this->assertSame($level, $mapping->level(), $type->value);
            $this->assertSame($context, $type->context(), $type->value);
            $this->assertTrue($mapping->isAppliedAutomatically(), $type->value);
        }
    }

    #[Test]
    public function the_broad_everyday_strategies_never_stand_in_for_a_formal_measure(): void
    {
        // «Apoio individualizado» and «Promoção da autonomia» are ordinary
        // teaching, far broader than any DL 54/2018 measure. The formal
        // measures they are confused with are separate types the teacher picks
        // deliberately — so these two must propose nothing at all.
        foreach ([InterventionType::IndividualSupport, InterventionType::AutonomyPromotion] as $type) {
            $mapping = $this->framework()->mappingFor($type);

            $this->assertSame(LegalMappingMode::None, $mapping->mode, $type->value);
            $this->assertNull($mapping->measure, $type->value);
            $this->assertNull($mapping->level(), $type->value);
            $this->assertNull($mapping->toPayload(), $type->value);
        }

        // And they are still offered — the strategies were not replaced by the
        // formal measures, they coexist.
        $values = array_column(InterventionType::cases(), 'value');
        $this->assertContains('individual_support', $values);
        $this->assertContains('autonomy_promotion', $values);
        $this->assertContains('psychopedagogical_support', $values);
        $this->assertContains('tutorial_support', $values);
        $this->assertContains('personal_social_autonomy_skills', $values);
    }

    #[Test]
    public function a_direct_mapping_carries_its_measure_and_the_matching_level(): void
    {
        $mapping = $this->framework()->mappingFor(InterventionType::PedagogicalDifferentiation);

        $this->assertSame(SupportMeasureCode::PedagogicalDifferentiation, $mapping->measure);
        $this->assertSame(SupportMeasureLevel::Universal, $mapping->level());
        $this->assertTrue($mapping->isAppliedAutomatically());

        $this->assertSame(SupportMeasureLevel::Additional, $this->framework()->mappingFor(InterventionType::SignificantCurricularAdaptation)->level());
        $this->assertSame(SupportMeasureLevel::Selective, $this->framework()->mappingFor(InterventionType::NonSignificantCurricularAdaptation)->level());
    }

    #[Test]
    public function the_contextual_mappings_are_exactly_these_two_and_are_never_automatic(): void
    {
        $contextual = array_values(array_filter(
            InterventionType::cases(),
            fn (InterventionType $type) => $this->framework()->mappingFor($type)->mode === LegalMappingMode::Contextual,
        ));

        $this->assertSame([
            InterventionType::LearningReinforcement,
            InterventionType::SmallGroupSupport,
        ], $contextual);

        foreach ($contextual as $type) {
            $this->assertFalse(
                $this->framework()->mappingFor($type)->isAppliedAutomatically(),
                "{$type->value} é uma sugestão — nunca pode ser aplicada sozinha.",
            );
        }
    }

    #[Test]
    public function an_evaluation_adaptation_never_carries_a_measure_level(): void
    {
        // Using an adaptation says nothing about whether the student is under
        // universal, selective or additional measures (§12.3). Inferring one
        // from the other is the mistake this test exists to prevent.
        $evaluationTypes = array_filter(
            InterventionType::cases(),
            fn (InterventionType $type) => $this->framework()->mappingFor($type)->mode === LegalMappingMode::EvaluationOnly,
        );

        $this->assertCount(5, $evaluationTypes);

        foreach ($evaluationTypes as $type) {
            $mapping = $this->framework()->mappingFor($type);

            $this->assertNull($mapping->level(), "{$type->value} não pode inferir um nível de medida.");
            $this->assertNull($mapping->measure, "{$type->value} não pode inferir uma medida.");
            $this->assertInstanceOf(EvaluationAdaptationCode::class, $mapping->evaluationAdaptation);
            $this->assertTrue($mapping->isAppliedAutomatically());
        }
    }

    #[Test]
    public function every_evaluation_adaptation_code_has_a_label_and_a_stable_code(): void
    {
        $expected = [
            'statement_reading', 'test_reading', 'instruction_comprehension_support',
            'simplified_wording', 'response_format_adaptation', 'instrument_adaptation',
            'extra_time', 'separate_room', 'direct_answer_questions', 'other',
        ];

        $this->assertSame($expected, array_column(EvaluationAdaptationCode::cases(), 'value'));

        foreach (EvaluationAdaptationCode::cases() as $code) {
            // No default arm in label(): a new code without one throws here.
            $this->assertNotSame('', trim($code->label()), $code->value);
        }
    }

    #[Test]
    public function an_adaptation_code_never_implies_a_support_measure(): void
    {
        // These are operational adaptations. None of them is reachable from a
        // SupportMeasureCode, and none carries a level of its own — attaching
        // one says nothing about the student's formal status.
        $measureValues = array_column(SupportMeasureCode::cases(), 'value');

        foreach (EvaluationAdaptationCode::cases() as $code) {
            $this->assertNotContains($code->value, $measureValues, $code->value);
        }
    }

    #[Test]
    public function extra_time_maps_to_the_documented_adaptation(): void
    {
        $mapping = $this->framework()->mappingFor(InterventionType::ExtraTime);

        $this->assertSame(EvaluationAdaptationCode::ExtraTime, $mapping->evaluationAdaptation);
        $this->assertSame(InterventionContext::Evaluation, InterventionType::ExtraTime->context());
    }

    #[Test]
    public function contexts_match_the_documented_catalogue(): void
    {
        $this->assertSame(InterventionContext::Learning, InterventionType::TextRevisionSupport->context());
        $this->assertSame(InterventionContext::Evaluation, InterventionType::ExtraTime->context());
        $this->assertSame(InterventionContext::StudyAutonomy, InterventionType::StudyOrganizationSupport->context());
        $this->assertSame(InterventionContext::AttentionSelfRegulation, InterventionType::AttentionRegulation->context());
        $this->assertSame(InterventionContext::Behavior, InterventionType::BehaviourRegulation->context());
        $this->assertSame(InterventionContext::Integration, InterventionType::ClassIntegration->context());
        $this->assertSame(InterventionContext::Other, InterventionType::Other->context());
    }

    #[Test]
    public function a_measure_always_agrees_with_its_own_level(): void
    {
        foreach (SupportMeasureCode::cases() as $measure) {
            $this->assertContains($measure, SupportMeasureCode::forLevel($measure->level()));
        }
    }

    #[Test]
    public function only_other_requires_a_description(): void
    {
        foreach (InterventionType::cases() as $type) {
            $this->assertSame(
                $type === InterventionType::Other,
                $type->requiresDescription(),
                $type->value,
            );
        }
    }

    #[Test]
    public function contacting_a_guardian_is_not_an_intervention_type(): void
    {
        // It belongs to Registos (EvidenceKind::Contact). Duplicating it here
        // would split the same fact across two modules (§2).
        $values = array_column(InterventionType::cases(), 'value');

        $this->assertNotContains('contact', $values);
        $this->assertNotContains('guardian_contact', $values);
    }

    #[Test]
    public function the_catalogue_payload_exposes_every_type_with_its_mapping(): void
    {
        $catalogue = InterventionType::catalogue($this->framework());

        $this->assertCount(count(InterventionType::cases()), $catalogue);

        $differentiation = collect($catalogue)->firstWhere('value', 'pedagogical_differentiation');
        $this->assertSame('direct', $differentiation['legal_mapping']['mode']);
        $this->assertSame('universal', $differentiation['legal_mapping']['level']);

        $writing = collect($catalogue)->firstWhere('value', 'writing_organization_support');
        $this->assertNull($writing['legal_mapping'], 'Prática pedagógica corrente não propõe enquadramento.');
    }

    #[Test]
    public function target_types_enforce_their_participant_counts(): void
    {
        $student = InterventionTargetType::Student;
        $group = InterventionTargetType::Group;
        $class = InterventionTargetType::SchoolClass;

        $this->assertTrue($student->acceptsParticipantCount(1));
        $this->assertFalse($student->acceptsParticipantCount(0));
        $this->assertFalse($student->acceptsParticipantCount(2));

        $this->assertTrue($group->acceptsParticipantCount(2));
        $this->assertTrue($group->acceptsParticipantCount(5));
        $this->assertFalse($group->acceptsParticipantCount(1));

        $this->assertTrue($class->acceptsParticipantCount(0));
        $this->assertFalse($class->acceptsParticipantCount(1));
    }
}
