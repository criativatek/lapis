<?php

namespace Tests\Feature\Interventions;

use App\Models\CatalogueFamily;
use App\Models\EvaluationAdaptationCode;
use App\Models\Intervention;
use App\Models\InterventionDescriptionSource;
use App\Models\InterventionDomainRelation;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\InterventionType;
use App\Models\LegalMappingSource;
use App\Models\SchoolClass;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * That nothing already recorded stopped working.
 *
 * The catalogue grew, a label changed and the level stopped being an input.
 * Every one of those is a chance to break a record somebody already made, and
 * these tests are the check that none of them did.
 */
class LegalCatalogueCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{class: SchoolClass, teacher: User} */
    private function seedClass(): array
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), fn () => [
            'class' => SchoolClass::where('label', '7.º A')->firstOrFail(),
            'teacher' => $teacher,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postIntervention(string $classUlid, User $teacher, array $overrides = []): TestResponse
    {
        return $this->actingAs($teacher)->postJson("/classes/{$classUlid}/interventions", array_merge([
            'target_type' => 'class',
            'enrollment_ids' => [],
            'intervention_type' => InterventionType::WritingOrganizationSupport->value,
            'domain_relation' => 'none',
            'description' => null,
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
            'legal_framing' => null,
            'confirm_suggested_framing' => false,
            'support_measure_level' => null,
            'support_measure_code' => null,
            'evaluation_adaptation_code' => null,
        ], $overrides));
    }

    // ------------------------------------------------ the catalogue is intact

    #[Test]
    public function every_type_the_page_offered_before_is_still_offered(): void
    {
        // The exact list as it stood before this change. Nothing was removed or
        // renamed out of existence; two things were added.
        $previous = [
            'learning_reinforcement', 'individual_support', 'small_group_support',
            'pedagogical_differentiation', 'curricular_accommodation',
            'non_significant_curricular_adaptation', 'significant_curricular_adaptation',
            'psychopedagogical_support', 'tutorial_support', 'statement_interpretation_support',
            'text_interpretation_support', 'writing_organization_support', 'text_planning_support',
            'text_revision_support', 'grammar_systematisation', 'oral_skills_support',
            'statement_reading', 'assessment_instrument_adaptation', 'extra_time',
            'separate_room_assessment', 'direct_answer_questions', 'study_organization_support',
            'time_management_support', 'autonomy_promotion', 'personal_social_autonomy_skills',
            'attention_regulation', 'behaviour_regulation', 'class_integration', 'other',
        ];

        $current = array_column(InterventionType::cases(), 'value');

        foreach ($previous as $value) {
            $this->assertContains($value, $current, $value);
        }

        // And every measure that could be stored before still can.
        $previousMeasures = [
            'pedagogical_differentiation', 'curricular_accommodation', 'academic_focus_small_group',
            'non_significant_curricular_adaptation', 'anticipation_learning_reinforcement',
            'psychopedagogical_support', 'tutorial_support', 'significant_curricular_adaptation',
            'personal_social_autonomy_skills',
        ];

        foreach ($previousMeasures as $value) {
            $this->assertNotNull(SupportMeasureCode::tryFrom($value), $value);
        }
    }

    #[Test]
    public function the_page_carries_the_catalogue_its_framework_and_the_families(): void
    {
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        $this->actingAs($teacher)
            ->get("/classes/{$class->ulid}/interventions")
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->has('types', count(InterventionType::cases()))
                    ->where('legalFramework.code', 'pt-inclusive-education-2018')
                    ->where('legalFramework.status', 'active')
                    ->has('legalFramework.legal_reference')
                    ->has('catalogueFamilies', count(CatalogueFamily::cases()));

                // Every type carries the family its framework reads it as.
                $types = collect($page->toArray()['props']['types']);
                $this->assertTrue($types->every(fn (array $type) => isset($type['family'], $type['family_label'])));
                $this->assertSame(
                    CatalogueFamily::EvaluationAdaptation->value,
                    $types->firstWhere('value', 'classification_support_instruments')['family'],
                );
                $this->assertSame(
                    CatalogueFamily::LegalMeasure->value,
                    $types->firstWhere('value', 'tutorial_support')['family'],
                );
                $this->assertSame(
                    CatalogueFamily::PedagogicalStrategy->value,
                    $types->firstWhere('value', 'text_planning_support')['family'],
                );
            });
    }

    // ------------------------------------------- records made before still load

    #[Test]
    public function an_intervention_recorded_before_this_change_still_loads_and_keeps_its_title(): void
    {
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        $intervention = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), fn () => Intervention::query()->create([
            'class_id' => $class->id,
            'target_type' => InterventionTargetType::SchoolClass,
            'intervention_type' => InterventionType::LearningReinforcement,
            'domain_relation' => InterventionDomainRelation::None,
            // The title the old label wrote. It is history and must not be
            // rewritten by the rename — a report from last term still says
            // what it said.
            'title' => 'Reforço das aprendizagens',
            'description_source' => InterventionDescriptionSource::Manual,
            'status' => InterventionStatus::New,
            'started_on' => '2026-09-10',
            'support_measure_level' => SupportMeasureLevel::Selective,
            'support_measure_code' => SupportMeasureCode::AnticipationLearningReinforcement,
            'legal_mapping_source' => LegalMappingSource::Manual,
            'created_by' => $teacher->id,
        ]));

        $this->actingAs($teacher)
            ->get("/classes/{$class->ulid}/interventions")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('interventions.0.title', 'Reforço das aprendizagens'));

        $intervention->refresh();
        $this->assertSame(SupportMeasureCode::AnticipationLearningReinforcement, $intervention->support_measure_code);
        $this->assertSame(SupportMeasureLevel::Selective, $intervention->support_measure_level);
        // It was never stamped, and nothing invented a stamp for it.
        $this->assertNull($intervention->legal_framework_code);
    }

    // ---------------------------------------------------- the level is derived

    #[Test]
    public function a_measure_sent_without_a_level_is_filed_at_the_level_the_law_gives_it(): void
    {
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'legal_framing' => 'manual',
            'support_measures' => [['code' => SupportMeasureCode::IndividualTransitionPlan->value]],
        ])->assertRedirect();

        $this->assertDatabaseHas('intervention_support_measures', [
            'support_measure_code' => SupportMeasureCode::IndividualTransitionPlan->value,
            'support_measure_level' => SupportMeasureLevel::Additional->value,
            'legal_framework_code' => 'pt-inclusive-education-2018',
        ]);
    }

    #[Test]
    public function a_level_that_contradicts_the_measure_is_still_refused(): void
    {
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        // Sending the level is still allowed, for older clients and the import
        // path — but it is checked against the law, never trusted.
        $this->postIntervention($class->ulid, $teacher, [
            'legal_framing' => 'manual',
            'support_measures' => [[
                'level' => SupportMeasureLevel::Universal->value,
                'code' => SupportMeasureCode::TutorialSupport->value,
            ]],
        ])->assertJsonValidationErrors('support_measures.0.code');
    }

    #[Test]
    public function the_same_measure_twice_is_a_duplicate_however_its_level_was_spelled(): void
    {
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'legal_framing' => 'manual',
            'support_measures' => [
                ['code' => SupportMeasureCode::TutorialSupport->value],
                ['level' => SupportMeasureLevel::Selective->value, 'code' => SupportMeasureCode::TutorialSupport->value],
            ],
        ])->assertJsonValidationErrors('support_measures.1.code');
    }

    #[Test]
    public function several_measures_at_different_levels_are_recorded_cumulatively(): void
    {
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'legal_framing' => 'manual',
            'support_measures' => [
                ['code' => SupportMeasureCode::PedagogicalDifferentiation->value],
                ['code' => SupportMeasureCode::TutorialSupport->value],
                ['code' => SupportMeasureCode::StructuredTeachingMethodologies->value],
            ],
        ])->assertRedirect();

        $intervention = Intervention::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertSame(
            ['universal', 'selective', 'additional'],
            $intervention->supportMeasures()
                ->get()
                ->map(fn ($measure) => $measure->support_measure_level->value)
                ->unique()
                ->sort()
                ->values()
                ->sortBy(fn (string $level) => array_search($level, ['universal', 'selective', 'additional'], true))
                ->values()
                ->all(),
        );
    }

    // ------------------------------------------------- adaptations stay apart

    #[Test]
    public function the_new_classification_instrument_records_without_any_measure_level(): void
    {
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'intervention_type' => InterventionType::ClassificationSupportInstruments->value,
        ])->assertRedirect();

        $intervention = Intervention::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertSame(EvaluationAdaptationCode::ClassificationSupportInstruments, $intervention->evaluation_adaptation_code);
        // The assertion that matters: recording it says nothing about whether
        // the student is under measures of any level.
        $this->assertNull($intervention->support_measure_level);
        $this->assertNull($intervention->support_measure_code);
        $this->assertSame(0, $intervention->supportMeasures()->count());
        // It was framed, so it carries the version it was framed under.
        $this->assertSame('pt-inclusive-education-2018', $intervention->legal_framework_code);
    }

    // ------------------------------- a jurisdiction with no framework still works

    #[Test]
    public function an_organization_without_a_framework_can_still_edit_an_intervention_that_has_measures(): void
    {
        // The regression this exists to prevent: deriving the level from the
        // framework made every EDIT impossible for these organizations. The
        // form resends the measures it loaded, the framework places none of
        // them, and the page offers no measures to remove them with — so a
        // rejection here is a record the teacher can never save again.
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        $organization = $teacher->personalOrganization();
        $organization->forceFill(['jurisdiction' => 'ES'])->save();

        $intervention = app(CurrentOrganization::class)->runFor($organization, function () use ($class, $teacher) {
            $intervention = Intervention::query()->create([
                'class_id' => $class->id,
                'target_type' => InterventionTargetType::SchoolClass,
                'intervention_type' => InterventionType::TutorialSupport,
                'domain_relation' => InterventionDomainRelation::None,
                'title' => 'Apoio tutorial',
                'description_source' => InterventionDescriptionSource::Manual,
                'status' => InterventionStatus::New,
                'started_on' => '2026-09-10',
                'support_measure_level' => SupportMeasureLevel::Selective,
                'support_measure_code' => SupportMeasureCode::TutorialSupport,
                'legal_mapping_source' => LegalMappingSource::Manual,
                'created_by' => $teacher->id,
            ]);

            $intervention->supportMeasures()->create([
                'support_measure_level' => SupportMeasureLevel::Selective,
                'support_measure_code' => SupportMeasureCode::TutorialSupport,
                'legal_mapping_source' => LegalMappingSource::Manual,
            ]);

            return $intervention;
        });

        $this->actingAs($teacher)->putJson("/interventions/{$intervention->ulid}", [
            'target_type' => 'class',
            'enrollment_ids' => [],
            'intervention_type' => InterventionType::TutorialSupport->value,
            'domain_relation' => 'none',
            'description' => 'Ajustado.',
            'started_on' => '2026-09-10',
            'available_for_reports' => true,
            'legal_framing' => null,
            'confirm_suggested_framing' => false,
            'support_measure_level' => null,
            'support_measure_code' => null,
            'evaluation_adaptation_code' => null,
            // Exactly what the form resends: the measure with its stored level.
            'support_measures' => [[
                'level' => SupportMeasureLevel::Selective->value,
                'code' => SupportMeasureCode::TutorialSupport->value,
            ]],
        ])->assertRedirect();

        $intervention->refresh();
        $this->assertSame('Ajustado.', $intervention->description);
        // The level it already carried is kept, not re-derived and not lost.
        $this->assertSame(SupportMeasureLevel::Selective, $intervention->supportMeasures()->first()?->support_measure_level);
        // And no Portuguese framework was stamped on a Spanish organization.
        $this->assertNull($intervention->supportMeasures()->first()?->legal_framework_code);
    }

    #[Test]
    public function a_measure_kept_after_clearing_the_framing_still_carries_its_stamp(): void
    {
        // `legal_framing: 'none'` clears the intervention's own framing but
        // does not discard submitted measures. Without a fallback, the one row
        // that does hold a legal classification was the one with no stamp.
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'legal_framing' => 'none',
            'support_measures' => [['code' => SupportMeasureCode::TutorialSupport->value]],
        ])->assertRedirect();

        $this->assertDatabaseHas('intervention_support_measures', [
            'support_measure_code' => SupportMeasureCode::TutorialSupport->value,
            'legal_framework_code' => 'pt-inclusive-education-2018',
        ]);
    }

    #[Test]
    public function the_record_reports_which_version_of_the_law_framed_it(): void
    {
        // The stamp must be readable, not only written: a column nothing ever
        // reads cannot make a drifting resolution visible.
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'intervention_type' => InterventionType::TutorialSupport->value,
        ])->assertRedirect();

        $this->actingAs($teacher)
            ->get("/classes/{$class->ulid}/interventions")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('interventions.0.legal_framework_code', 'pt-inclusive-education-2018'));
    }

    #[Test]
    public function an_ordinary_teaching_strategy_records_with_no_framing_at_all(): void
    {
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'intervention_type' => InterventionType::TextPlanningSupport->value,
        ])->assertRedirect();

        $intervention = Intervention::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertNull($intervention->support_measure_code);
        $this->assertNull($intervention->evaluation_adaptation_code);
        $this->assertNull($intervention->legal_mapping_source);
        $this->assertNull($intervention->legal_framework_code);
    }
}
