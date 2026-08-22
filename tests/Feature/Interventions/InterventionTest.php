<?php

namespace Tests\Feature\Interventions;

use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\EvaluationAdaptationCode;
use App\Models\Intervention;
use App\Models\InterventionContext;
use App\Models\InterventionDescriptionSource;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\InterventionType;
use App\Models\LegalMappingSource;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Models\User;
use App\Support\Interventions\PortugalInclusiveEducationFramework;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Interventions (§14): what the teacher DID — for a student, a group or the
 * whole class — optionally framed pedagogically/legally, never part of the
 * calculation.
 */
class InterventionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{class: SchoolClass, enrollments: list<int>, teacher: User} */
    private function seedClass(): array
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($teacher) {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            return [
                'class' => $class,
                'enrollments' => $class->enrollments()->orderBy('class_number')->pluck('id')->all(),
                'teacher' => $teacher,
            ];
        });
    }

    /**
     * Mirrors exactly what the real form sends: every key present, nulls
     * explicit. A payload that only carries the interesting fields hides the
     * class of bug where a rule rejects an explicitly-sent null.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function postIntervention(string $classUlid, User $teacher, array $overrides = []): TestResponse
    {
        $payload = array_merge([
            'target_type' => 'student',
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
        ], $overrides);

        return $this->actingAs($teacher)->postJson("/classes/{$classUlid}/interventions", $payload);
    }

    private function inTenant(User $teacher, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), $callback);
    }

    // ---------------------------------------------------------------- targets

    #[Test]
    public function an_authorized_teacher_registers_an_intervention_for_one_student(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, ['enrollment_ids' => [$enrollments[0]]])->assertRedirect();

        $this->inTenant($teacher, function () use ($enrollments): void {
            $intervention = Intervention::firstOrFail();

            $this->assertSame(InterventionTargetType::Student, $intervention->target_type);
            $this->assertSame([$enrollments[0]], $intervention->participants->pluck('id')->all());
            // The legacy single-student column stays in step for the student case.
            $this->assertSame($enrollments[0], $intervention->enrollment_id);
            // The type's label is the title — the teacher never typed one.
            $this->assertSame(InterventionType::WritingOrganizationSupport->label(), $intervention->title);
            $this->assertSame(InterventionDescriptionSource::Manual, $intervention->description_source);
            $this->assertSame(InterventionStatus::New, $intervention->status);
        });
    }

    #[Test]
    public function a_teacher_cannot_register_in_a_class_they_do_not_teach(): void
    {
        ['class' => $class] = $this->seedClass();

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson("/classes/{$class->ulid}/interventions", [
                'target_type' => 'class',
                'enrollment_ids' => [],
                'intervention_type' => InterventionType::WritingOrganizationSupport->value,
                'domain_relation' => 'none',
                'started_on' => '2026-10-01',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function a_student_target_accepts_exactly_one_enrollment(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, ['target_type' => 'student', 'enrollment_ids' => []])
            ->assertJsonValidationErrors('enrollment_ids');

        $this->postIntervention($class->ulid, $teacher, ['target_type' => 'student', 'enrollment_ids' => [$enrollments[0], $enrollments[1]]])
            ->assertJsonValidationErrors('enrollment_ids');
    }

    #[Test]
    public function a_group_target_needs_at_least_two_enrollments(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, ['target_type' => 'group', 'enrollment_ids' => [$enrollments[0]]])
            ->assertJsonValidationErrors('enrollment_ids');

        $this->postIntervention($class->ulid, $teacher, [
            'target_type' => 'group',
            'enrollment_ids' => [$enrollments[0], $enrollments[1], $enrollments[2]],
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertSame(InterventionTargetType::Group, $intervention->target_type);
            $this->assertCount(3, $intervention->participants);
            // No single student owns a group intervention.
            $this->assertNull($intervention->enrollment_id);
        });
    }

    #[Test]
    public function a_class_target_names_no_students(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, ['target_type' => 'class', 'enrollment_ids' => [$enrollments[0]]])
            ->assertJsonValidationErrors('enrollment_ids');

        $this->postIntervention($class->ulid, $teacher, ['target_type' => 'class', 'enrollment_ids' => []])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertSame(InterventionTargetType::SchoolClass, $intervention->target_type);
            $this->assertCount(0, $intervention->participants);
            $this->assertNull($intervention->enrollment_id);
        });
    }

    #[Test]
    public function an_enrollment_from_another_class_is_rejected(): void
    {
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, ['enrollment_ids' => [999999]])
            ->assertJsonValidationErrors('enrollment_ids');
    }

    // ---------------------------------------------------------------- domains

    #[Test]
    public function a_specific_domain_relation_requires_a_domain(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'domain_relation' => 'specific',
        ])->assertJsonValidationErrors('domain_id');
    }

    #[Test]
    public function none_and_all_reject_a_domain_and_store_none(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $domainId = $this->inTenant($teacher, fn () => Domain::where('subject_id', $class->subject_id)->firstOrFail()->id);

        foreach (['none', 'all'] as $relation) {
            $this->postIntervention($class->ulid, $teacher, [
                'enrollment_ids' => [$enrollments[0]],
                'domain_relation' => $relation,
                'domain_id' => $domainId,
            ])->assertJsonValidationErrors('domain_id');
        }

        // "Todos os domínios" is a real statement, distinct from "nenhum" — it
        // just carries no single domain_id.
        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'domain_relation' => 'all',
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertSame('all', $intervention->domain_relation->value);
            $this->assertNull($intervention->domain_id);
        });
    }

    #[Test]
    public function a_domain_from_another_subject_is_rejected(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $foreignDomainId = $this->inTenant($teacher, fn () => Domain::factory()
            ->recycle($teacher->personalOrganization())
            ->create(['subject_id' => null])->id);

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'domain_relation' => 'specific',
            'domain_id' => $foreignDomainId,
        ])->assertJsonValidationErrors('domain_id');
    }

    // ------------------------------------------------------------ description

    #[Test]
    public function the_other_type_requires_a_description(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::Other->value,
        ])->assertJsonValidationErrors('description');

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::Other->value,
            'description' => 'Acompanhamento combinado com a diretora de turma.',
        ])->assertRedirect();
    }

    // ---------------------------------------------------------------- reports

    #[Test]
    public function available_for_reports_defaults_to_true_and_keeps_the_legacy_column_in_step(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $payload = [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::WritingOrganizationSupport->value,
            'domain_relation' => 'none',
            'started_on' => '2026-10-01',
        ];

        $this->actingAs($teacher)->postJson("/classes/{$class->ulid}/interventions", $payload)->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertTrue($intervention->available_for_reports);
            $this->assertTrue($intervention->include_in_report);
            $this->assertCount(1, Intervention::availableForReports()->get());
        });
    }

    #[Test]
    public function opting_out_of_reports_is_respected(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'available_for_reports' => false,
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $this->assertFalse(Intervention::firstOrFail()->available_for_reports);
            $this->assertCount(0, Intervention::availableForReports()->get());
        });
    }

    // ---------------------------------------------------------- legal framing

    #[Test]
    public function a_direct_mapping_is_applied_automatically(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::PedagogicalDifferentiation->value,
            'legal_framing' => 'auto',
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertSame(SupportMeasureLevel::Universal, $intervention->support_measure_level);
            $this->assertSame(SupportMeasureCode::PedagogicalDifferentiation, $intervention->support_measure_code);
            $this->assertSame(LegalMappingSource::SystemDirect, $intervention->legal_mapping_source);
            $this->assertTrue($intervention->hasConfirmedLegalFraming());
        });
    }

    #[Test]
    public function a_contextual_suggestion_is_not_stored_until_the_teacher_confirms_it(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        // Registered without acting on the suggestion: nothing is stored, so a
        // report can never read it back as a legal decision.
        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::LearningReinforcement->value,
            'legal_framing' => 'auto',
            'confirm_suggested_framing' => false,
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertNull($intervention->support_measure_level);
            $this->assertNull($intervention->support_measure_code);
            $this->assertNull($intervention->legal_mapping_source);
            $this->assertFalse($intervention->hasConfirmedLegalFraming());
            // The catalogue still knows what it would have suggested.
            $this->assertNotNull($intervention->catalogueLegalMapping(new PortugalInclusiveEducationFramework)?->measure);
            // And it stays out of a level-filtered query.
            $this->assertCount(0, Intervention::bySupportMeasureLevel(SupportMeasureLevel::Selective)->get());
        });
    }

    #[Test]
    public function a_confirmed_contextual_suggestion_is_stored_as_confirmed(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::LearningReinforcement->value,
            'legal_framing' => 'auto',
            'confirm_suggested_framing' => true,
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertSame(SupportMeasureLevel::Selective, $intervention->support_measure_level);
            $this->assertSame(SupportMeasureCode::AnticipationLearningReinforcement, $intervention->support_measure_code);
            $this->assertSame(LegalMappingSource::SystemSuggestedConfirmed, $intervention->legal_mapping_source);
            $this->assertCount(1, Intervention::bySupportMeasureLevel(SupportMeasureLevel::Selective)->get());
        });
    }

    #[Test]
    public function an_evaluation_adaptation_never_infers_a_measure_level(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::ExtraTime->value,
            'legal_framing' => 'auto',
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertSame('extra_time', $intervention->evaluation_adaptation_code?->value);
            // Giving a student extra time says nothing about their formal status.
            $this->assertNull($intervention->support_measure_level);
            $this->assertNull($intervention->support_measure_code);
        });
    }

    #[Test]
    public function a_manual_framing_can_be_set_and_then_removed(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::AttentionRegulation->value,
            'legal_framing' => 'manual',
            'support_measure_level' => 'universal',
            'support_measure_code' => 'pedagogical_differentiation',
        ])->assertRedirect();

        $ulid = $this->inTenant($teacher, function (): string {
            $intervention = Intervention::firstOrFail();

            $this->assertSame(LegalMappingSource::Manual, $intervention->legal_mapping_source);
            $this->assertSame(SupportMeasureCode::PedagogicalDifferentiation, $intervention->support_measure_code);

            return $intervention->ulid;
        });

        $this->actingAs($teacher)->putJson("/interventions/{$ulid}", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::AttentionRegulation->value,
            'domain_relation' => 'none',
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
            'legal_framing' => 'none',
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertNull($intervention->legal_mapping_source);
            $this->assertNull($intervention->support_measure_code);
            $this->assertNull($intervention->support_measure_level);
        });
    }

    #[Test]
    public function switching_a_direct_framing_to_an_evaluation_type_leaves_no_measure_behind(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        // Starts as an unambiguous universal measure.
        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::PedagogicalDifferentiation->value,
            'legal_framing' => 'auto',
        ])->assertRedirect();

        $ulid = $this->inTenant($teacher, fn () => Intervention::firstOrFail()->ulid);

        // Retyped as an assessment adaptation. The measure belonged to the old
        // type and must not survive: an adaptation says nothing about the
        // student's formal status.
        $this->actingAs($teacher)->putJson("/interventions/{$ulid}", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::ExtraTime->value,
            'domain_relation' => 'none',
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertSame(EvaluationAdaptationCode::ExtraTime, $intervention->evaluation_adaptation_code);
            $this->assertNull($intervention->support_measure_level, 'Uma adaptação não herda o nível da medida anterior.');
            $this->assertNull($intervention->support_measure_code, 'Diferenciação pedagógica não pode sobreviver à mudança de tipo.');
        });
    }

    #[Test]
    public function a_measure_attached_by_hand_coexists_with_an_evaluation_adaptation(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        // The teacher deliberately frames an assessment adaptation under a
        // formal measure as well. Both are recorded, neither implies the other.
        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::ExtraTime->value,
            'legal_framing' => 'manual',
            'support_measure_level' => 'universal',
            'support_measure_code' => 'pedagogical_differentiation',
            'evaluation_adaptation_code' => 'extra_time',
        ])->assertRedirect();

        $ulid = $this->inTenant($teacher, function (): string {
            $intervention = Intervention::firstOrFail();

            $this->assertSame(EvaluationAdaptationCode::ExtraTime, $intervention->evaluation_adaptation_code);
            $this->assertSame(SupportMeasureLevel::Universal, $intervention->support_measure_level);
            $this->assertSame(LegalMappingSource::Manual, $intervention->legal_mapping_source);

            return $intervention->ulid;
        });

        // Dropping the measure again must leave the adaptation standing.
        $this->actingAs($teacher)->putJson("/interventions/{$ulid}", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::ExtraTime->value,
            'domain_relation' => 'none',
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
            'legal_framing' => 'manual',
            'evaluation_adaptation_code' => 'extra_time',
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertSame(EvaluationAdaptationCode::ExtraTime, $intervention->evaluation_adaptation_code);
            $this->assertNull($intervention->support_measure_level);
            $this->assertNull($intervention->support_measure_code);
        });
    }

    #[Test]
    public function an_evaluation_intervention_defaults_to_no_specific_domain(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::ExtraTime->value,
            'legal_framing' => 'auto',
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            // Not "todos os domínios" — extra time in an assessment is not by
            // itself a statement about which domains it covered (§12).
            $this->assertSame('none', $intervention->domain_relation->value);
            $this->assertNull($intervention->domain_id);
        });
    }

    #[Test]
    public function a_direct_framing_can_be_removed_by_the_teacher(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::PedagogicalDifferentiation->value,
            'legal_framing' => 'auto',
        ])->assertRedirect();

        $ulid = $this->inTenant($teacher, fn () => Intervention::firstOrFail()->ulid);

        // The catalogue proposes, the teacher disposes: an unambiguous mapping
        // is still theirs to clear.
        $this->actingAs($teacher)->putJson("/interventions/{$ulid}", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::PedagogicalDifferentiation->value,
            'domain_relation' => 'none',
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
            'legal_framing' => 'none',
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertNull($intervention->support_measure_level);
            $this->assertNull($intervention->support_measure_code);
            $this->assertNull($intervention->legal_mapping_source);
            $this->assertFalse($intervention->hasConfirmedLegalFraming());
        });
    }

    #[Test]
    public function an_adaptation_can_be_attached_by_hand_to_a_type_that_is_not_an_evaluation_one(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        // A learning-context intervention that the teacher also wants recorded
        // as an assessment adaptation. Still no measure level is inferred.
        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::WritingOrganizationSupport->value,
            'legal_framing' => 'manual',
            'evaluation_adaptation_code' => 'simplified_wording',
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertSame(EvaluationAdaptationCode::SimplifiedWording, $intervention->evaluation_adaptation_code);
            $this->assertSame(LegalMappingSource::Manual, $intervention->legal_mapping_source);
            $this->assertNull($intervention->support_measure_level);
            $this->assertNull($intervention->support_measure_code);
        });
    }

    #[Test]
    public function evaluation_is_never_smuggled_in_as_a_domain(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        // "Avaliação" is a context, not a subject domain. Nothing in this
        // module may create one, and an evaluation-context intervention is
        // stored with no domain at all unless the teacher picks a real one.
        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::ExtraTime->value,
            'legal_framing' => 'auto',
        ])->assertRedirect();

        $this->inTenant($teacher, function () use ($class): void {
            $intervention = Intervention::firstOrFail();

            $this->assertNull($intervention->domain_id);
            $this->assertSame(InterventionContext::Evaluation, $intervention->context());

            foreach (['Avaliação', 'Transversal', 'Comportamento', 'Métodos de estudo', 'Integração'] as $forbidden) {
                $this->assertFalse(
                    Domain::where('subject_id', $class->subject_id)->where('name', $forbidden)->exists(),
                    "«{$forbidden}» é um contexto, não um domínio da disciplina.",
                );
            }
        });
    }

    #[Test]
    public function a_measure_that_does_not_belong_to_the_level_is_rejected(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'legal_framing' => 'manual',
            'support_measure_level' => 'additional',
            'support_measure_code' => 'pedagogical_differentiation', // universal
        ])->assertJsonValidationErrors('support_measure_code');
    }

    #[Test]
    public function changing_the_type_never_silently_overwrites_a_manual_framing(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::AttentionRegulation->value,
            'legal_framing' => 'manual',
            'support_measure_level' => 'selective',
            'support_measure_code' => 'anticipation_learning_reinforcement',
        ])->assertRedirect();

        $ulid = $this->inTenant($teacher, fn () => Intervention::firstOrFail()->ulid);

        $base = [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollments[0]],
            'domain_relation' => 'none',
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
        ];

        // The new type carries its own unambiguous framing, which disagrees
        // with what the teacher chose — the edit stops and asks.
        $this->actingAs($teacher)->putJson("/interventions/{$ulid}", $base + [
            'intervention_type' => InterventionType::SignificantCurricularAdaptation->value,
        ])->assertJsonValidationErrors('intervention_type');

        $this->inTenant($teacher, function (): void {
            $this->assertSame(SupportMeasureCode::AnticipationLearningReinforcement, Intervention::firstOrFail()->support_measure_code);
        });

        // Saying explicitly what should happen goes through.
        $this->actingAs($teacher)->putJson("/interventions/{$ulid}", $base + [
            'intervention_type' => InterventionType::SignificantCurricularAdaptation->value,
            'legal_framing' => 'auto',
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertSame(SupportMeasureCode::SignificantCurricularAdaptation, $intervention->support_measure_code);
            $this->assertSame(LegalMappingSource::SystemDirect, $intervention->legal_mapping_source);
        });
    }

    #[Test]
    public function a_manual_framing_survives_an_edit_that_says_nothing_about_it(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::AttentionRegulation->value,
            'legal_framing' => 'manual',
            'support_measure_level' => 'universal',
            'support_measure_code' => 'curricular_accommodation',
        ])->assertRedirect();

        $ulid = $this->inTenant($teacher, fn () => Intervention::firstOrFail()->ulid);

        // Same type, only the description changed: silence is not a request to
        // clear the framing.
        $this->actingAs($teacher)->putJson("/interventions/{$ulid}", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::AttentionRegulation->value,
            'domain_relation' => 'none',
            'description' => 'Estratégias combinadas com o aluno.',
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertSame(SupportMeasureCode::CurricularAccommodation, $intervention->support_measure_code);
            $this->assertSame(LegalMappingSource::Manual, $intervention->legal_mapping_source);
        });
    }

    #[Test]
    public function a_confirmed_suggestion_survives_an_edit_that_says_nothing_about_it(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::LearningReinforcement->value,
            'legal_framing' => 'auto',
            'confirm_suggested_framing' => true,
        ])->assertRedirect();

        $ulid = $this->inTenant($teacher, fn () => Intervention::firstOrFail()->ulid);

        // Editing only the description must not quietly undo the confirmation:
        // a confirmed suggestion is the teacher's decision just as much as a
        // hand-picked framing is.
        $this->actingAs($teacher)->putJson("/interventions/{$ulid}", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::LearningReinforcement->value,
            'domain_relation' => 'none',
            'description' => 'Sessões de reforço às terças.',
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertSame(LegalMappingSource::SystemSuggestedConfirmed, $intervention->legal_mapping_source);
            $this->assertSame(SupportMeasureCode::AnticipationLearningReinforcement, $intervention->support_measure_code);
        });
    }

    // ------------------------------------------------------- edit and destroy

    #[Test]
    public function editing_preserves_target_integrity(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'target_type' => 'group',
            'enrollment_ids' => [$enrollments[0], $enrollments[1]],
        ])->assertRedirect();

        $ulid = $this->inTenant($teacher, fn () => Intervention::firstOrFail()->ulid);

        // Narrowing a group down to a single student rewrites the pivot and
        // restores the legacy column — no stale participant is left behind.
        $this->actingAs($teacher)->putJson("/interventions/{$ulid}", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollments[2]],
            'intervention_type' => InterventionType::WritingOrganizationSupport->value,
            'domain_relation' => 'none',
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
        ])->assertRedirect();

        $this->inTenant($teacher, function () use ($enrollments): void {
            $intervention = Intervention::firstOrFail();

            $this->assertSame(InterventionTargetType::Student, $intervention->target_type);
            $this->assertSame([$enrollments[2]], $intervention->participants->pluck('id')->all());
            $this->assertSame($enrollments[2], $intervention->enrollment_id);
        });
    }

    #[Test]
    public function only_an_authorized_teacher_can_delete(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, ['enrollment_ids' => [$enrollments[0]]])->assertRedirect();
        $ulid = $this->inTenant($teacher, fn () => Intervention::firstOrFail()->ulid);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->delete("/interventions/{$ulid}")->assertNotFound();

        $this->actingAs($teacher)->delete("/interventions/{$ulid}")->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $this->assertSame(0, Intervention::count());
            $this->assertSame(1, Intervention::withTrashed()->count());
        });
    }

    // ------------------------------------------------- lifecycle and appraisal

    #[Test]
    public function the_lifecycle_and_effectiveness_reviews_still_work(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, ['enrollment_ids' => [$enrollments[0]]])->assertRedirect();
        $ulid = $this->inTenant($teacher, fn () => Intervention::firstOrFail()->ulid);

        $this->actingAs($teacher)->patch("/interventions/{$ulid}", ['status' => 'concluded'])->assertRedirect();

        $this->actingAs($teacher)->post("/interventions/{$ulid}/reviews", [
            'reviewed_on' => '2026-11-15',
            'effectiveness' => 'partially_effective',
            'notes' => 'Melhoria na organização, ainda com lapsos.',
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertSame(InterventionStatus::Concluded, $intervention->status);
            $this->assertNotNull($intervention->concluded_on);
            $this->assertSame('partially_effective', $intervention->reviews()->firstOrFail()->effectiveness?->value);
        });
    }

    // ----------------------------------------------------- calculation safety

    #[Test]
    public function an_intervention_never_touches_the_calculation(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $before = $this->inTenant($teacher, fn () => StudentItemScore::count());

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::PedagogicalDifferentiation->value,
            'legal_framing' => 'auto',
        ])->assertRedirect();

        $this->inTenant($teacher, function () use ($before): void {
            // No score is written, and the model carries nothing the engine
            // could read: no weight, no points, no FK into results (§14.3).
            $this->assertSame($before, StudentItemScore::count());

            $columns = array_keys(Intervention::firstOrFail()->getAttributes());
            foreach (['weight', 'points', 'points_earned', 'normalized_value', 'scale_level_id'] as $forbidden) {
                $this->assertNotContains($forbidden, $columns);
            }
        });
    }

    // ----------------------------------------------------------------- scopes

    #[Test]
    public function a_class_wide_intervention_reaches_every_student_of_the_class(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, ['target_type' => 'class', 'enrollment_ids' => []])->assertRedirect();
        $this->postIntervention($class->ulid, $teacher, [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::ExtraTime->value,
        ])->assertRedirect();

        $this->inTenant($teacher, function () use ($enrollments): void {
            // The named student sees both; another student still sees the
            // class-wide one, because it reached them too.
            $this->assertCount(2, Intervention::forEnrollment($enrollments[0])->get());
            $this->assertCount(1, Intervention::forEnrollment($enrollments[1])->get());
        });
    }

    #[Test]
    public function the_context_scope_resolves_types_through_the_catalogue(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::ExtraTime->value,
            'legal_framing' => 'auto',
        ])->assertRedirect();
        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::WritingOrganizationSupport->value,
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $this->assertCount(1, Intervention::byContext(InterventionContext::Evaluation)->get());
            $this->assertCount(1, Intervention::byContext(InterventionContext::Learning)->get());
            $this->assertCount(0, Intervention::byContext(InterventionContext::Behavior)->get());
        });
    }

    // ------------------------------------------------------- jurisdictions

    #[Test]
    public function an_organization_in_an_unsupported_jurisdiction_still_registers_interventions(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        // Explicitly somewhere LÁPIS has no law for. The pedagogical module
        // must keep working in full — refusing to record what a teacher did
        // because the app lacks that country's legislation would be absurd.
        $teacher->personalOrganization()->update(['jurisdiction' => 'ES']);

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::PedagogicalDifferentiation->value,
            'legal_framing' => 'auto',
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            // Recorded in full, pedagogically…
            $this->assertSame(InterventionType::PedagogicalDifferentiation, $intervention->intervention_type);
            $this->assertSame(InterventionContext::Learning, $intervention->context());
            $this->assertSame(InterventionType::PedagogicalDifferentiation->label(), $intervention->title);
            // …and with no Portuguese measure attached to a Spanish school.
            $this->assertNull($intervention->support_measure_level);
            $this->assertNull($intervention->support_measure_code);
            $this->assertNull($intervention->legal_mapping_source);
        });
    }

    #[Test]
    public function an_unsupported_jurisdiction_is_offered_no_legal_taxonomy_at_all(): void
    {
        ['class' => $class, 'teacher' => $teacher] = $this->seedClass();
        $teacher->personalOrganization()->update(['jurisdiction' => 'ES']);

        $this->actingAs($teacher)
            ->get("/classes/{$class->ulid}/interventions")
            ->assertInertia(fn ($page) => $page
                ->component('interventions/Show')
                // Present but empty: the UI contract keeps its shape, so the
                // page renders without the properties simply going missing.
                ->where('legalFramework', null)
                ->where('supportMeasureLevels', [])
                ->where('evaluationAdaptations', [])
                // The pedagogical catalogue is untouched — every type is still
                // offered, just with nothing legal attached.
                ->has('types', count(InterventionType::cases()))
                ->where('types.0.legal_mapping', null));
    }

    #[Test]
    public function an_organization_without_a_stated_jurisdiction_keeps_the_portuguese_behaviour(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        // The state every existing organization is in after the migration.
        $this->assertNull($teacher->personalOrganization()->jurisdiction);

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::PedagogicalDifferentiation->value,
            'legal_framing' => 'auto',
        ])->assertRedirect();

        $this->inTenant($teacher, function (): void {
            $intervention = Intervention::firstOrFail();

            $this->assertSame(SupportMeasureLevel::Universal, $intervention->support_measure_level);
            $this->assertSame(SupportMeasureCode::PedagogicalDifferentiation, $intervention->support_measure_code);
            $this->assertSame(LegalMappingSource::SystemDirect, $intervention->legal_mapping_source);
        });
    }

    #[Test]
    public function another_organization_cannot_open_the_interventions(): void
    {
        ['class' => $class] = $this->seedClass();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get("/classes/{$class->ulid}/interventions")->assertNotFound();
    }

    #[Test]
    public function the_listing_can_be_filtered(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[0]],
            'intervention_type' => InterventionType::ExtraTime->value,
            'legal_framing' => 'auto',
        ])->assertRedirect();
        $this->postIntervention($class->ulid, $teacher, [
            'enrollment_ids' => [$enrollments[1]],
            'intervention_type' => InterventionType::WritingOrganizationSupport->value,
            'available_for_reports' => false,
        ])->assertRedirect();

        $this->actingAs($teacher)
            ->get("/classes/{$class->ulid}/interventions?context=evaluation")
            ->assertInertia(fn ($page) => $page->component('interventions/Show')->has('interventions', 1));

        // Both spellings a query string can carry. "false" matters especially:
        // a (bool) cast would read it as true and invert the filter.
        foreach (['0', 'false'] as $falsy) {
            $this->actingAs($teacher)
                ->get("/classes/{$class->ulid}/interventions?available_for_reports={$falsy}")
                ->assertInertia(fn ($page) => $page
                    ->has('interventions', 1)
                    ->where('interventions.0.available_for_reports', false));
        }

        foreach (['1', 'true'] as $truthy) {
            $this->actingAs($teacher)
                ->get("/classes/{$class->ulid}/interventions?available_for_reports={$truthy}")
                ->assertInertia(fn ($page) => $page
                    ->has('interventions', 1)
                    ->where('interventions.0.available_for_reports', true));
        }

        $this->actingAs($teacher)
            ->get("/classes/{$class->ulid}/interventions")
            ->assertInertia(fn ($page) => $page->has('interventions', 2));
    }

    // ------------------------------------------------------- §6 autoria/ligação

    #[Test]
    public function a_row_names_who_registered_it(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, ['enrollment_ids' => [$enrollments[0]]])->assertRedirect();

        $this->actingAs($teacher)
            ->get("/classes/{$class->ulid}/interventions")
            ->assertInertia(fn ($page) => $page->where('interventions.0.creator_name', $teacher->name));
    }

    #[Test]
    public function a_single_student_intervention_links_back_to_their_enrollment_and_a_group_does_not(): void
    {
        ['class' => $class, 'enrollments' => $enrollments, 'teacher' => $teacher] = $this->seedClass();

        $this->postIntervention($class->ulid, $teacher, ['enrollment_ids' => [$enrollments[0]]])->assertRedirect();
        $this->postIntervention($class->ulid, $teacher, [
            'target_type' => 'group',
            'enrollment_ids' => [$enrollments[1], $enrollments[2]],
        ])->assertRedirect();

        $expectedUlid = $this->inTenant($teacher, fn () => Enrollment::findOrFail($enrollments[0])->ulid);

        $this->actingAs($teacher)
            ->get("/classes/{$class->ulid}/interventions")
            ->assertInertia(fn ($page) => $page
                ->where('interventions.0.target_enrollment_ulid', null)
                ->where('interventions.1.target_enrollment_ulid', $expectedUlid));
    }
}
