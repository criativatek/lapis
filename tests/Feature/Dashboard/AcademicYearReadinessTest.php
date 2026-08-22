<?php

namespace Tests\Feature\Dashboard;

use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AcademicYearReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_organization_shows_the_academic_year_as_the_next_step_and_blocks_the_rest(): void
    {
        $teacher = User::factory()->create();

        $this->actingAs($teacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('readiness.is_ready', false)
                ->where('readiness.items.0.id', 'academic_year')
                ->where('readiness.items.0.is_next', true)
                ->where('readiness.items.0.cta.label', 'Criar ano letivo')
                ->where('readiness.items.0.cta.href', route('academic-years.create'))
                ->where('readiness.items.1.cta', null)
                ->where('readiness.items.2.cta', null)
                ->where('readiness.items.3.cta', null)
                ->where('readiness.items.4.cta', null),
        );
    }

    public function test_a_draft_academic_year_still_needs_activation_and_blocks_the_rest(): void
    {
        $teacher = User::factory()->create();
        $year = $this->forTenant($teacher->personalOrganization(), fn () => AcademicYear::factory()
            ->recycle($teacher->personalOrganization())
            ->create());

        $this->actingAs($teacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('readiness.items.0.completed', false)
                ->where('readiness.items.0.is_next', true)
                ->where('readiness.items.0.cta.label', 'Ativar ano letivo')
                ->where('readiness.items.0.cta.href', route('academic-years.edit', $year))
                ->where('readiness.items.1.cta', null)
                ->where('readiness.items.2.cta', null)
                ->where('readiness.items.3.cta', null)
                ->where('readiness.items.4.cta', null),
        );
    }

    public function test_an_active_year_without_subjects_makes_subjects_the_next_step(): void
    {
        $teacher = User::factory()->create();
        $this->forTenant($teacher->personalOrganization(), fn () => AcademicYear::factory()
            ->recycle($teacher->personalOrganization())
            ->active()
            ->create());

        $this->actingAs($teacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('readiness.items.0.completed', true)
                ->where('readiness.items.0.is_next', false)
                ->where('readiness.items.0.cta', null)
                ->where('readiness.items.1.completed', false)
                ->where('readiness.items.1.is_next', true)
                ->where('readiness.items.1.cta.href', route('subjects.index')),
        );
    }

    public function test_a_fully_prepared_year_hides_the_checklist_and_shows_the_normal_dashboard(): void
    {
        $teacher = User::factory()->create();
        $context = $this->createReadyStructure($teacher);

        $this->actingAs($teacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('readiness.is_ready', true)
                ->where('readiness.items.0.completed', true)
                ->where('readiness.items.1.completed', true)
                ->where('readiness.items.2.completed', true)
                ->where('readiness.items.3.completed', true)
                ->where('readiness.items.4.completed', true)
                ->where('totals.classes', 1)
                ->where('classes.0.ulid', $context['class']->ulid)
                ->where('classes.0.has_profile', true),
        );
    }

    public function test_a_profile_from_a_previous_year_does_not_count_for_the_current_year(): void
    {
        $teacher = User::factory()->create();

        $this->forTenant($teacher->personalOrganization(), function () use ($teacher): void {
            AcademicYear::factory()->recycle($teacher->personalOrganization())->active()->create([
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-08-31',
            ]);
            $previousYear = AcademicYear::factory()->recycle($teacher->personalOrganization())->closed()->create([
                'starts_on' => '2025-09-01',
                'ends_on' => '2026-08-31',
            ]);
            $subject = Subject::factory()->recycle($teacher->personalOrganization())->create();

            AssessmentProfile::factory()->recycle($teacher->personalOrganization())->create([
                'academic_year_id' => $previousYear->id,
                'subject_id' => $subject->id,
            ]);
        });

        $this->actingAs($teacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('readiness.items.1.completed', true)
                ->where('readiness.items.2.completed', false)
                ->where('readiness.items.2.is_next', true)
                ->where('readiness.items.2.cta.href', route('assessment-profiles.index')),
        );
    }

    public function test_another_teachers_class_does_not_count_for_class_readiness(): void
    {
        $teacher = User::factory()->create();
        $colleague = User::factory()->create();
        $organization = $teacher->personalOrganization();
        $colleague->organizations()->attach($organization, ['joined_at' => now()]);

        $this->forTenant($organization, function () use ($teacher, $colleague, $organization): void {
            $year = AcademicYear::factory()->recycle($organization)->active()->create();
            $subject = Subject::factory()->recycle($organization)->create();
            $profile = AssessmentProfile::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
            ]);
            $version = AssessmentProfileVersion::factory()->recycle($organization)->create([
                'assessment_profile_id' => $profile->id,
            ]);
            $class = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
                'assessment_profile_version_id' => $version->id,
            ]);
            $class->teachers()->attach($colleague, ['role' => 'owner']);

            $this->assertFalse($class->teachers()->whereKey($teacher->id)->exists());
        });

        $this->actingAs($teacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('readiness.items.3.completed', false)
                ->where('readiness.items.3.is_next', true)
                ->where('readiness.items.4.completed', false)
                ->where('readiness.items.4.cta', null)
                ->where('totals.classes', 0),
        );
    }

    public function test_readiness_counts_never_include_another_organizations_data(): void
    {
        $teacher = User::factory()->create();
        $otherTeacher = User::factory()->create();

        $this->createReadyStructure($otherTeacher);

        $this->actingAs($teacher)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('readiness.is_ready', false)
                ->where('readiness.items.0.completed', false)
                ->where('readiness.items.0.is_next', true)
                ->where('readiness.items.1.completed', false)
                ->where('readiness.items.2.completed', false)
                ->where('readiness.items.3.completed', false)
                ->where('readiness.items.4.completed', false)
                ->where('totals.classes', 0),
        );
    }

    /**
     * @return array{class: SchoolClass}
     */
    private function createReadyStructure(User $teacher): array
    {
        return $this->forTenant($teacher->personalOrganization(), function () use ($teacher): array {
            $organization = $teacher->personalOrganization();
            $year = AcademicYear::factory()->recycle($organization)->active()->create();
            $subject = Subject::factory()->recycle($organization)->create();
            $profile = AssessmentProfile::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
            ]);
            $version = AssessmentProfileVersion::factory()->recycle($organization)->create([
                'assessment_profile_id' => $profile->id,
            ]);
            $class = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
                'assessment_profile_version_id' => $version->id,
            ]);
            $class->teachers()->attach($teacher, ['role' => 'owner']);

            return ['class' => $class];
        });
    }

    /**
     * @template TValue
     *
     * @param  callable(): TValue  $callback
     * @return TValue
     */
    private function forTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }
}
