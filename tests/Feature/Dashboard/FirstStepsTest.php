<?php

namespace Tests\Feature\Dashboard;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Primeiros passos" (A1a, Onboarding & Help) — DashboardController::firstSteps().
 *
 * A deliberately separate concept from readiness() (see AcademicYearReadinessTest):
 * whether the teacher has STARTED USING the app, not whether the account is
 * configured. Every item is asserted through the real HTTP flow that produces
 * it — creating a class, enrolling a student, creating an instrument, saving a
 * score — never by inserting the row directly, since a real flow exists for
 * every one of them.
 */
class FirstStepsTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
    }

    protected function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    #[Test]
    public function a_brand_new_account_starts_with_all_four_steps_incomplete(): void
    {
        $this->actingAs($this->teacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('firstSteps.dismissed', false)
                ->where('firstSteps.all_done', false)
                ->where('firstSteps.items.0.id', 'class')
                ->where('firstSteps.items.0.completed', false)
                ->where('firstSteps.items.0.cta.label', 'Criar turma')
                ->where('firstSteps.items.0.cta.href', route('classes.create'))
                ->where('firstSteps.items.1.id', 'student')
                ->where('firstSteps.items.1.completed', false)
                ->where('firstSteps.items.1.cta', null)
                ->where('firstSteps.items.2.id', 'instrument')
                ->where('firstSteps.items.2.completed', false)
                ->where('firstSteps.items.2.cta.href', route('instruments.create-picker'))
                ->where('firstSteps.items.3.id', 'result')
                ->where('firstSteps.items.3.completed', false)
                ->where('firstSteps.items.3.cta', null),
        );
    }

    #[Test]
    public function each_step_flips_the_instant_the_real_action_happens(): void
    {
        // -------------------------------------------------- setup scaffolding
        // Reference data the four checked facts sit on top of — created
        // directly, exactly as AcademicYearReadinessTest's own
        // createReadyStructure() does, because none of THIS is one of the
        // four things under test.
        $scenario = $this->inTenant(function (): array {
            // Every factory below pinned to the SAME single $year — the
            // assessment profile's own factory would otherwise mint a second,
            // unrelated academic year, and currentYearFor() might then resolve
            // to THAT one instead of $year, silently emptying
            // $currentAcademicYearClasses and sinking every assertion below on
            // a fixture artifact rather than the code under test.
            $year = AcademicYear::factory()->recycle($this->organization)->create();
            $period = AcademicPeriod::factory()->recycle($this->organization)->for($year)->create();
            $subject = Subject::factory()->recycle($this->organization)->create();
            $domain = Domain::factory()->recycle($this->organization)->create(['subject_id' => $subject->id]);
            $profile = AssessmentProfile::factory()->recycle($this->organization)->create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
            ]);
            $version = AssessmentProfileVersion::factory()->recycle($this->organization)->create([
                'assessment_profile_id' => $profile->id,
            ]);
            $version->domains()->create(['domain_id' => $domain->id, 'weight_percent' => 100, 'sequence' => 1]);
            $type = InstrumentType::where('code', 'TEST')->firstOrFail();

            return compact('year', 'period', 'subject', 'domain', 'version', 'type');
        });

        // ------------------------------------------------------- step 0: none done
        $this->actingAs($this->teacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('firstSteps.items.0.completed', false)
                ->where('firstSteps.items.1.completed', false)
                ->where('firstSteps.items.2.completed', false)
                ->where('firstSteps.items.3.completed', false),
        );

        // -------------------------------------------------- 1. turma criada
        $this->actingAs($this->teacher)->post('/classes', [
            'label' => '7.º A',
            'academic_year_id' => $scenario['year']->id,
            'subject_id' => $scenario['subject']->id,
            'assessment_profile_version_id' => $scenario['version']->id,
        ])->assertSessionHasNoErrors();

        $class = $this->inTenant(fn (): SchoolClass => SchoolClass::sole());

        $this->actingAs($this->teacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('firstSteps.items.0.completed', true)
                ->where('firstSteps.items.0.cta', null)
                ->where('firstSteps.items.1.completed', false)
                // Now actionable: a class exists to enrol into.
                ->where('firstSteps.items.1.cta.label', 'Inscrever aluno')
                ->where('firstSteps.items.1.cta.href', route('classes.show', $class))
                ->where('firstSteps.items.2.completed', false)
                ->where('firstSteps.items.3.completed', false),
        );

        // -------------------------------------------------- 2. aluno inscrito
        $this->actingAs($this->teacher)->post("/classes/{$class->ulid}/students", [
            'name' => 'Ana Ativa',
            'enrolled_on' => '2026-09-14',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->teacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('firstSteps.items.1.completed', true)
                ->where('firstSteps.items.1.cta', null)
                ->where('firstSteps.items.2.completed', false)
                ->where('firstSteps.items.3.completed', false),
        );

        // -------------------------------------------------- 3. instrumento criado
        $this->actingAs($this->teacher)->post("/classes/{$class->ulid}/instruments", [
            'quick' => true,
            'title' => 'Ficha rápida',
            'academic_period_id' => $scenario['period']->id,
            'instrument_type_id' => $scenario['type']->id,
            'applied_on' => '2026-10-15',
            'submission_intent' => 'prepare',
            'purpose' => 'summative',
            'counts_toward_classification' => true,
            'total_points' => 100,
            'allow_bonus' => false,
            'groups' => [['label' => null]],
            'items' => [[
                'code' => 'Q1',
                'label' => null,
                'points_possible' => 100,
                'is_bonus' => false,
                'domains' => [[
                    'domain_id' => $scenario['domain']->id,
                    'allocation_percent' => 100,
                ]],
            ]],
        ])->assertSessionHasNoErrors();

        $instrument = $this->inTenant(fn (): Instrument => Instrument::where('class_id', $class->id)->sole());

        $this->actingAs($this->teacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('firstSteps.items.2.completed', true)
                ->where('firstSteps.items.2.cta', null)
                ->where('firstSteps.items.3.completed', false)
                // Now actionable: an instrument exists to score.
                ->where('firstSteps.items.3.cta.label', 'Registar resultado')
                ->where('firstSteps.items.3.cta.href', route('instruments.show', $instrument))
                ->where('firstSteps.all_done', false),
        );

        // -------------------------------------------------- 4. resultado registado
        $item = $this->inTenant(fn () => $instrument->items()->sole());
        $enrollment = $this->inTenant(fn (): Enrollment => Enrollment::where('class_id', $class->id)->sole());

        $this->actingAs($this->teacher)->post("/instruments/{$instrument->ulid}/scores", [
            'cells' => [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'lock_version' => 0,
                'points_earned' => 8.5,
            ]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->teacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('firstSteps.items.3.completed', true)
                ->where('firstSteps.items.3.cta', null)
                ->where('firstSteps.all_done', true)
                ->where('firstSteps.dismissed', false),
        );
    }

    #[Test]
    public function dismissing_persists_survives_a_fresh_load_and_never_touches_progress(): void
    {
        $this->inTenant(function (): void {
            $class = SchoolClass::factory()->recycle($this->organization)->create();
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);
        });

        $this->actingAs($this->teacher)->post('/dashboard/onboarding-dismissal')->assertRedirect();

        $this->assertNotNull($this->teacher->fresh()->onboarding_dismissed_at);

        // Survives a fresh page load — a second, independent request.
        $this->actingAs($this->teacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('firstSteps.dismissed', true)
                // Dismissing hid the card; it did not touch progress. The class
                // created above is still there and still counted.
                ->where('firstSteps.items.0.completed', true)
                ->where('firstSteps.items.1.completed', false),
        );

        $this->inTenant(function (): void {
            $this->assertSame(1, SchoolClass::count(), 'dismissing must not delete or alter progress data');
        });
    }

    #[Test]
    public function restoring_a_dismissed_card_brings_it_back(): void
    {
        $this->actingAs($this->teacher)->post('/dashboard/onboarding-dismissal')->assertRedirect();
        $this->assertNotNull($this->teacher->fresh()->onboarding_dismissed_at);

        $this->actingAs($this->teacher)->delete('/dashboard/onboarding-dismissal')->assertRedirect();
        $this->assertNull($this->teacher->fresh()->onboarding_dismissed_at);

        $this->actingAs($this->teacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page->where('firstSteps.dismissed', false),
        );
    }

    #[Test]
    public function a_fully_adopted_account_has_nothing_to_dismiss(): void
    {
        // The "no version-gate needed" reasoning: an old, fully-adopted account
        // naturally has nothing outstanding, the same way readiness() already
        // shows nothing once an account is fully configured — no separate
        // "created after X" flag is needed for either checklist.
        $this->inTenant(function (): void {
            // One academic year only, and every factory below pinned to it —
            // otherwise AcademicPeriod's own factory chain would silently mint
            // a second, unrelated year, and currentYearFor() picking THAT one
            // as "current" would make the class (correctly) invisible to
            // item 0, sinking this test on a fixture artifact, not the code.
            $year = AcademicYear::factory()->recycle($this->organization)->create();
            $class = SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $year->id]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);
            $enrollment = Enrollment::factory()->recycle($this->organization)->create(['class_id' => $class->id]);
            $period = AcademicPeriod::factory()->recycle($this->organization)->for($year)->create();
            $instrument = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
            ]);
            $item = InstrumentItem::factory()->recycle($this->organization)->create(['instrument_id' => $instrument->id]);
            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id,
                'instrument_item_id' => $item->id,
                'enrollment_id' => $enrollment->id,
                'result_state' => 'assessed',
                'points_earned' => 8,
            ]);
        });

        $this->actingAs($this->teacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('firstSteps.all_done', true)
                ->where('firstSteps.dismissed', false)
                ->where('firstSteps.items.0.completed', true)
                ->where('firstSteps.items.1.completed', true)
                ->where('firstSteps.items.2.completed', true)
                ->where('firstSteps.items.3.completed', true),
        );
    }

    #[Test]
    public function progress_and_dismissal_never_cross_organizations(): void
    {
        $otherTeacher = User::factory()->create();
        $otherOrganization = $otherTeacher->personalOrganization();

        app(CurrentOrganization::class)->runFor($otherOrganization, function () use ($otherTeacher, $otherOrganization): void {
            $year = AcademicYear::factory()->recycle($otherOrganization)->create();
            $class = SchoolClass::factory()->recycle($otherOrganization)->create(['academic_year_id' => $year->id]);
            $class->teachers()->attach($otherTeacher, ['role' => 'owner']);
            $enrollment = Enrollment::factory()->recycle($otherOrganization)->create(['class_id' => $class->id]);
            $period = AcademicPeriod::factory()->recycle($otherOrganization)->for($year)->create();
            $instrument = Instrument::factory()->recycle($otherOrganization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
            ]);
            $item = InstrumentItem::factory()->recycle($otherOrganization)->create(['instrument_id' => $instrument->id]);
            StudentItemScore::factory()->recycle($otherOrganization)->create([
                'instrument_id' => $instrument->id,
                'instrument_item_id' => $item->id,
                'enrollment_id' => $enrollment->id,
                'result_state' => 'assessed',
                'points_earned' => 8,
            ]);
        });
        $this->actingAs($otherTeacher)->post('/dashboard/onboarding-dismissal')->assertRedirect();

        // A different teacher, a different (personal) organization: none of the
        // other account's progress or dismissal shows up here.
        $this->actingAs($this->teacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('firstSteps.dismissed', false)
                ->where('firstSteps.all_done', false)
                ->where('firstSteps.items.0.completed', false)
                ->where('firstSteps.items.1.completed', false)
                ->where('firstSteps.items.2.completed', false)
                ->where('firstSteps.items.3.completed', false),
        );

        // And the other way round: my own class does not leak into theirs.
        $this->inTenant(function (): void {
            $class = SchoolClass::factory()->recycle($this->organization)->create();
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);
        });

        $this->actingAs($otherTeacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page->where('firstSteps.all_done', true),
        );
    }
}
