<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * instruments.create-picker — the front door into instrument creation from
 * Elementos de Avaliação's own area, additive to (never a replacement for)
 * the existing classes/{class}/instruments/create route it hands off to
 * unchanged. The picker itself creates nothing and carries no class id in
 * its own request; every assertion here about "who may create what" is
 * really an assertion about that pre-existing, untouched route and its
 * unchanged SchoolClassPolicy::update() authorization.
 */
class InstrumentCreationPickerTest extends TestCase
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

    protected function makeClass(?User $teacher = null, ?string $label = null): SchoolClass
    {
        $org = $this->organization;
        $year = AcademicYear::factory()->recycle($org)->create();
        $subject = Subject::factory()->recycle($org)->create();
        $class = SchoolClass::factory()->recycle($org)->create([
            'academic_year_id' => $year->id,
            'subject_id' => $subject->id,
            ...($label !== null ? ['label' => $label] : []),
        ]);
        $class->teachers()->attach($teacher ?? $this->teacher, ['role' => 'owner']);

        return $class;
    }

    #[Test]
    public function the_picker_lists_only_classes_the_teacher_themself_teaches(): void
    {
        $ownClass = $this->inTenant(fn (): SchoolClass => $this->makeClass(label: 'Minha turma'));

        // Same organization, but a colleague's class — never this teacher's.
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $this->inTenant(fn (): SchoolClass => $this->makeClass($colleague, 'Turma do colega'));

        // A different organization entirely.
        $outsider = User::factory()->create();
        app(CurrentOrganization::class)->runFor($outsider->personalOrganization(), function () use ($outsider): void {
            $year = AcademicYear::factory()->recycle($outsider->personalOrganization())->create();
            $subject = Subject::factory()->recycle($outsider->personalOrganization())->create();
            $class = SchoolClass::factory()->recycle($outsider->personalOrganization())->create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
                'label' => 'Turma de outra organização',
            ]);
            $class->teachers()->attach($outsider, ['role' => 'owner']);
        });

        $this->actingAs($this->teacher)
            ->get('/instruments/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('instruments/Create')
                ->where('schoolClass', null)
                ->has('classes', 1)
                ->where('classes.0.ulid', $ownClass->ulid)
                ->where('classes.0.label', 'Minha turma')
                // Meaningless without a chosen class — never sent here.
                ->missing('periods')
                ->missing('types')
                ->missing('domains')
                ->missing('importableInstruments'));
    }

    #[Test]
    public function a_teacher_with_no_classes_sees_an_empty_list_not_an_error(): void
    {
        $this->actingAs($this->teacher)
            ->get('/instruments/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('instruments/Create')
                ->where('schoolClass', null)
                ->where('classes', []));
    }

    #[Test]
    public function choosing_a_class_hands_off_to_the_real_unchanged_creation_route(): void
    {
        $class = $this->inTenant(fn (): SchoolClass => $this->makeClass(label: 'Minha turma'));

        // What the picker's "Continuar" button navigates to — the exact
        // route classes/{class}/instruments/create already served before
        // this feature existed, unmodified.
        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/instruments/create")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('instruments/Create')
                ->where('schoolClass.ulid', $class->ulid)
                ->where('schoolClass.label', 'Minha turma')
                ->where('defaultCreationMode', 'quick')
                ->has('periods')
                ->has('types')
                ->has('domains')
                ->missing('classes'));
    }

    #[Test]
    public function a_teacher_cannot_reach_another_organizations_class_via_the_real_create_route(): void
    {
        $outsider = User::factory()->create();
        $foreignClass = app(CurrentOrganization::class)->runFor($outsider->personalOrganization(), function () use ($outsider): SchoolClass {
            $year = AcademicYear::factory()->recycle($outsider->personalOrganization())->create();
            $subject = Subject::factory()->recycle($outsider->personalOrganization())->create();
            $class = SchoolClass::factory()->recycle($outsider->personalOrganization())->create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
            ]);
            $class->teachers()->attach($outsider, ['role' => 'owner']);

            return $class;
        });

        // Tenancy isolation (SchoolClass's own organization global scope), not
        // a check this feature adds — the ulid simply never resolves outside
        // its own organization, the same as every other classes/{class} route.
        $this->actingAs($this->teacher)
            ->get("/classes/{$foreignClass->ulid}/instruments/create")
            ->assertNotFound();
    }
}
