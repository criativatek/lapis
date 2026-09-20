<?php

namespace Tests\Unit\Interventions;

use App\Models\CatalogueFamily;
use App\Models\EvaluationAdaptationCode;
use App\Models\InterventionType;
use App\Models\LegalFrameworkStatus;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Support\Interventions\LegalFrameworkRegistry;
use App\Support\Interventions\LegalFrameworkResolver;
use App\Support\Interventions\LegalReferenceStatus;
use App\Support\Interventions\NullLegalFramework;
use App\Support\Interventions\PortugalInclusiveEducationFramework;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Interventions\FictitiousLegalFramework;
use Tests\TestCase;

/**
 * That a change in the law cannot reach backwards.
 *
 * Everything here is about one property: a record made under one regime keeps
 * being read under that regime, whatever the next diploma says. The fictitious
 * framework used below ships only in the test suite — no speculative law is in
 * the application, and one of these tests proves it.
 */
class LegalFrameworkVersioningTest extends TestCase
{
    private function portugal(): PortugalInclusiveEducationFramework
    {
        return new PortugalInclusiveEducationFramework;
    }

    // ------------------------------------------------- the catalogue in force

    #[Test]
    public function the_catalogue_names_every_measure_of_the_regime_in_force(): void
    {
        // Articles 8.º, 9.º and 10.º of Decreto-Lei n.º 54/2018, in the wording
        // given by Lei n.º 116/2019 and Decreto-Lei n.º 62/2023. Five measures
        // at each level, and they are asserted by name: a measure quietly
        // leaving this list makes it unrecordable, and one quietly joining it
        // puts a category in front of a teacher that nobody approved.
        $framework = $this->portugal();

        $byLevel = [];
        foreach (SupportMeasureCode::cases() as $measure) {
            $byLevel[$framework->levelFor($measure)->value][] = $measure->value;
        }

        $this->assertSame([
            'pedagogical_differentiation',
            'curricular_accommodation',
            'curricular_enrichment',
            'pro_social_behaviour_promotion',
            'academic_focus_small_group',
        ], $byLevel[SupportMeasureLevel::Universal->value]);

        $this->assertSame([
            'differentiated_curricular_paths',
            'non_significant_curricular_adaptation',
            'psychopedagogical_support',
            'anticipation_learning_reinforcement',
            'tutorial_support',
        ], $byLevel[SupportMeasureLevel::Selective->value]);

        $this->assertSame([
            'subject_by_subject_year_attendance',
            'significant_curricular_adaptation',
            'individual_transition_plan',
            'structured_teaching_methodologies',
            'personal_social_autonomy_skills',
        ], $byLevel[SupportMeasureLevel::Additional->value]);
    }

    #[Test]
    public function every_measure_cites_the_article_that_names_it(): void
    {
        $framework = $this->portugal();

        $expectedArticle = [
            SupportMeasureLevel::Universal->value => '8.º',
            SupportMeasureLevel::Selective->value => '9.º',
            SupportMeasureLevel::Additional->value => '10.º',
        ];

        foreach (SupportMeasureCode::cases() as $measure) {
            $reference = $framework->legalReferenceFor($measure);

            $this->assertNotNull($reference, $measure->value);
            $this->assertSame($expectedArticle[$framework->levelFor($measure)->value], $reference->article, $measure->value);
            $this->assertNotNull($reference->subparagraph, $measure->value);
            $this->assertNotSame('', trim($reference->designation), $measure->value);
            $this->assertStringContainsString($reference->article, $reference->citation(), $measure->value);
        }
    }

    #[Test]
    public function the_framework_in_force_declares_its_diploma_and_its_validity(): void
    {
        $framework = $this->portugal();

        $this->assertSame(LegalFrameworkStatus::Active, $framework->status());
        $this->assertTrue($framework->status()->isApplicable());
        $this->assertStringContainsString('54/2018', $framework->legalReference());
        // The amending diplomas are part of what is in force — citing only the
        // original would cite a text that no longer exists in that form.
        $this->assertStringContainsString('116/2019', $framework->legalReference());
        $this->assertStringContainsString('62/2023', $framework->legalReference());
        $this->assertSame('2018-07-07', $framework->validFrom()?->toDateString());
        $this->assertNull($framework->validUntil());
    }

    // ----------------------------------------------------- the four families

    #[Test]
    public function the_catalogue_distinguishes_the_four_conceptual_families(): void
    {
        $framework = $this->portugal();

        // A named measure of the diploma.
        $this->assertSame(CatalogueFamily::LegalMeasure, $framework->familyFor(InterventionType::TutorialSupport));

        // Ordinary teaching. It is on the same page and is not a measure.
        $this->assertSame(CatalogueFamily::PedagogicalStrategy, $framework->familyFor(InterventionType::TextPlanningSupport));

        // An adaptation to the assessment process.
        $this->assertSame(CatalogueFamily::EvaluationAdaptation, $framework->familyFor(InterventionType::ClassificationSupportInstruments));

        // The fourth family is representable, and only legal measures may carry
        // a level. No item in today's catalogue is a resource — Lapispro has
        // never offered one, and inventing one is exactly what is forbidden.
        $this->assertTrue(CatalogueFamily::LegalMeasure->mayCarryMeasureLevel());
        foreach ([CatalogueFamily::PedagogicalStrategy, CatalogueFamily::EvaluationAdaptation, CatalogueFamily::SupportResource] as $family) {
            $this->assertFalse($family->mayCarryMeasureLevel(), $family->value);
        }
    }

    #[Test]
    public function a_suggested_framing_is_a_strategy_until_the_teacher_confirms_it(): void
    {
        // «Apoio em pequeno grupo» is plain good teaching that MAY have been
        // mobilised as a measure. Calling it a legal measure before the teacher
        // says so would be the app deciding a legal fact about a child.
        $this->assertSame(
            CatalogueFamily::PedagogicalStrategy,
            $this->portugal()->familyFor(InterventionType::SmallGroupSupport),
        );
    }

    #[Test]
    public function an_item_may_exist_with_no_universal_selective_or_additional_classification(): void
    {
        $framework = $this->portugal();

        foreach ([InterventionType::ClassificationSupportInstruments, InterventionType::ExtraTime, InterventionType::TextPlanningSupport] as $type) {
            $this->assertNull($framework->mappingFor($type)->level(), $type->value);
        }
    }

    #[Test]
    public function the_new_classification_instrument_is_an_adaptation_and_never_a_measure(): void
    {
        $mapping = $this->portugal()->mappingFor(InterventionType::ClassificationSupportInstruments);

        $this->assertSame(EvaluationAdaptationCode::ClassificationSupportInstruments, $mapping->evaluationAdaptation);
        $this->assertNull($mapping->measure);
        $this->assertNull($mapping->level());

        // And it is not reachable as a measure by any spelling.
        $this->assertNull(SupportMeasureCode::tryFrom('classification_support_instruments'));
    }

    #[Test]
    public function the_selective_measure_carries_the_designation_the_diploma_uses(): void
    {
        // The decided rename: the visible label now matches artigo 9.º, alínea
        // d), and the code it is stored under did not move.
        $this->assertSame('Antecipação e reforço das aprendizagens', SupportMeasureCode::AnticipationLearningReinforcement->label());
        $this->assertSame('anticipation_learning_reinforcement', SupportMeasureCode::AnticipationLearningReinforcement->value);

        $this->assertSame('Antecipação e reforço das aprendizagens', InterventionType::LearningReinforcement->label());
        $this->assertSame('learning_reinforcement', InterventionType::LearningReinforcement->value);
    }

    // ----------------------------------------------------- cumulative levels

    #[Test]
    public function the_levels_mobilised_are_read_off_the_measures_and_accumulate(): void
    {
        $framework = $this->portugal();

        $levelsOf = fn (array $codes) => array_values(array_unique(array_map(
            fn (SupportMeasureCode $measure) => $framework->levelFor($measure)->value,
            $codes,
        )));

        $this->assertSame(['universal'], $levelsOf([
            SupportMeasureCode::PedagogicalDifferentiation,
            SupportMeasureCode::CurricularEnrichment,
        ]));

        $this->assertSame(['universal', 'selective'], $levelsOf([
            SupportMeasureCode::PedagogicalDifferentiation,
            SupportMeasureCode::TutorialSupport,
        ]));

        $this->assertSame(['universal', 'selective', 'additional'], $levelsOf([
            SupportMeasureCode::PedagogicalDifferentiation,
            SupportMeasureCode::TutorialSupport,
            SupportMeasureCode::IndividualTransitionPlan,
        ]));
    }

    // -------------------------------------------------- a later diploma comes

    #[Test]
    public function a_later_diploma_never_reclassifies_a_record_made_under_the_earlier_one(): void
    {
        // The fictitious 2030 regime moves «Apoio tutorial» from selective to
        // additional and revokes «Enriquecimento curricular».
        $later = new FictitiousLegalFramework(
            code: 'xx-2030',
            jurisdiction: 'XX',
            validFrom: Carbon::parse('2030-09-01'),
            levels: [
                'tutorial_support' => SupportMeasureLevel::Additional,
                'pedagogical_differentiation' => SupportMeasureLevel::Universal,
                'curricular_enrichment' => null,
            ],
        );

        $earlier = new FictitiousLegalFramework(
            code: 'xx-2018',
            jurisdiction: 'XX',
            validUntil: Carbon::parse('2030-08-31'),
            levels: [
                'tutorial_support' => SupportMeasureLevel::Selective,
                'pedagogical_differentiation' => SupportMeasureLevel::Universal,
                'curricular_enrichment' => SupportMeasureLevel::Universal,
            ],
        );

        $resolver = new LegalFrameworkResolver(new LegalFrameworkRegistry([$later, $earlier]));

        $of2029 = $resolver->resolve('XX', Carbon::parse('2029-11-10'));
        $of2031 = $resolver->resolve('XX', Carbon::parse('2031-11-10'));

        // The same stored code, read under the regime of each record's own date.
        $this->assertSame(SupportMeasureLevel::Selective, $of2029->levelFor(SupportMeasureCode::TutorialSupport));
        $this->assertSame(SupportMeasureLevel::Additional, $of2031->levelFor(SupportMeasureCode::TutorialSupport));

        // The 2029 record is untouched by the 2030 revocation.
        $this->assertSame(SupportMeasureLevel::Universal, $of2029->levelFor(SupportMeasureCode::CurricularEnrichment));
        $this->assertNull($of2031->levelFor(SupportMeasureCode::CurricularEnrichment));
    }

    #[Test]
    public function a_revoked_measure_stops_being_offered_and_keeps_resolving_for_history(): void
    {
        $framework = new FictitiousLegalFramework(
            code: 'xx-revoking',
            jurisdiction: 'XX',
            levels: [
                'curricular_enrichment' => null,
                'tutorial_support' => SupportMeasureLevel::Selective,
            ],
        );

        $offered = array_merge(...array_column($framework->supportMeasureLevels(), 'measures'));
        $this->assertNotContains('curricular_enrichment', array_column($offered, 'value'));
        $this->assertContains('tutorial_support', array_column($offered, 'value'));

        // Still readable: a record that used it must never render as a blank.
        $reference = $framework->legalReferenceFor(SupportMeasureCode::CurricularEnrichment);
        $this->assertSame(LegalReferenceStatus::Revoked, $reference?->status);
        $this->assertFalse($reference->status->isSelectable());
        $this->assertNotSame('', trim($reference->designation));
    }

    #[Test]
    public function a_later_diploma_may_rename_a_measure_without_moving_its_code(): void
    {
        $renaming = new FictitiousLegalFramework(
            code: 'xx-renaming',
            jurisdiction: 'XX',
            levels: ['tutorial_support' => SupportMeasureLevel::Selective],
            labels: ['tutorial_support' => 'Acompanhamento tutorial reforçado'],
        );

        $this->assertSame(
            'Acompanhamento tutorial reforçado',
            $renaming->legalReferenceFor(SupportMeasureCode::TutorialSupport)?->designation,
        );

        // The stored identity did not move, which is what makes the rename safe.
        $this->assertSame('tutorial_support', SupportMeasureCode::TutorialSupport->value);
    }

    #[Test]
    public function a_later_diploma_may_turn_a_measure_into_a_resource(): void
    {
        // The fourth family, exercised: the same pedagogical act reads as a
        // resource rather than a legal measure under a different regime.
        $framework = new FictitiousLegalFramework(
            code: 'xx-resource',
            jurisdiction: 'XX',
            levels: ['tutorial_support' => SupportMeasureLevel::Selective],
            families: ['tutorial_support' => CatalogueFamily::SupportResource],
        );

        $this->assertSame(CatalogueFamily::SupportResource, $framework->familyFor(InterventionType::TutorialSupport));
        $this->assertFalse($framework->familyFor(InterventionType::TutorialSupport)->mayCarryMeasureLevel());

        // And Portugal is unaffected by another jurisdiction's reading.
        $this->assertSame(CatalogueFamily::LegalMeasure, $this->portugal()->familyFor(InterventionType::TutorialSupport));
    }

    // ------------------------------------------------- drafts never leak out

    #[Test]
    public function a_draft_or_not_yet_in_force_version_is_never_applied(): void
    {
        foreach ([LegalFrameworkStatus::Draft, LegalFrameworkStatus::Future] as $status) {
            $registry = new LegalFrameworkRegistry([
                new FictitiousLegalFramework(code: 'xx-draft', jurisdiction: 'XX', status: $status),
            ]);

            // Not "returns something harmless" — returns nothing at all, so a
            // caller cannot render it by mistake.
            $this->assertNull($registry->find('XX', Carbon::parse('2026-10-01')), $status->value);

            $resolver = new LegalFrameworkResolver($registry);
            $this->assertInstanceOf(NullLegalFramework::class, $resolver->resolve('XX', Carbon::parse('2026-10-01')));
        }
    }

    #[Test]
    public function a_historical_version_stays_applicable_so_old_records_still_read(): void
    {
        $registry = new LegalFrameworkRegistry([
            new FictitiousLegalFramework(
                code: 'xx-historical',
                jurisdiction: 'XX',
                status: LegalFrameworkStatus::Historical,
                validUntil: Carbon::parse('2026-08-31'),
                levels: ['tutorial_support' => SupportMeasureLevel::Selective],
            ),
        ]);

        $this->assertSame('xx-historical', $registry->find('XX', Carbon::parse('2026-01-10'))?->code());
        // And it does not answer for dates after it ended.
        $this->assertNull($registry->find('XX', Carbon::parse('2026-10-01')));
    }

    #[Test]
    public function no_speculative_measure_from_the_announced_revision_is_in_the_catalogue(): void
    {
        // The announced revision of the regime is a draft. None of its new
        // instruments exists here, under any spelling.
        $values = array_column(SupportMeasureCode::cases(), 'value');

        foreach (['pdi', 'individual_development_plan', 'snai', 'elai', 'ccai'] as $speculative) {
            $this->assertNotContains($speculative, $values, $speculative);
        }

        // And the only framework registered is the one in force.
        $registry = new LegalFrameworkRegistry;
        $this->assertSame('pt-inclusive-education-2018', $registry->find('PT', Carbon::parse('2026-10-01'))?->code());
    }
}
