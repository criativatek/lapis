<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Nova turma» — what the form is given to choose from, and who may create what
 * is missing. A class needs an academic year and a subject; any member can
 * create a class, but only the organization's owner can create those two.
 */
class ClassCreateFormTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_new_owner_gets_empty_lists_and_may_create_both_prerequisites(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->get('/classes/create')->assertOk()->assertInertia(
            fn (Assert $page) => $page
                ->component('classes/Create')
                ->where('academicYears', [])
                ->where('subjects', [])
                ->where('canCreateAcademicYear', true)
                ->where('canCreateSubject', true),
        );
    }

    #[Test]
    public function a_member_who_is_not_the_owner_may_not_create_the_prerequisites(): void
    {
        $owner = User::factory()->create();
        $organization = $owner->personalOrganization();
        $colleague = User::factory()->create();
        $organization->members()->attach($colleague, ['joined_at' => now()]);

        $this->withSession(['organization_id' => $organization->id])
            ->actingAs($colleague)
            ->get('/classes/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canCreateAcademicYear', false)
                ->where('canCreateSubject', false));
    }

    #[Test]
    public function the_lists_are_filled_once_the_year_and_the_subject_exist(): void
    {
        $owner = User::factory()->create();
        $organization = $owner->personalOrganization();

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            AcademicYear::factory()->recycle($organization)->create(['label' => '2026/2027']);
            Subject::factory()->recycle($organization)->create(['name' => 'Matemática']);
        });

        $this->actingAs($owner)->get('/classes/create')->assertInertia(
            fn (Assert $page) => $page
                ->has('academicYears', 1)
                ->where('academicYears.0.label', '2026/2027')
                ->has('subjects', 1)
                ->where('subjects.0.label', 'Matemática'),
        );
    }
}
