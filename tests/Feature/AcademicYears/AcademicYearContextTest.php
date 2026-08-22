<?php

namespace Tests\Feature\AcademicYears;

use App\Models\AcademicYear;
use App\Models\AcademicYearStatus;
use App\Models\Organization;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AcademicYearContextTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function selecting_an_academic_year_changes_the_shared_scope_on_following_pages(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        $this->createAcademicYear($organization, 2026, AcademicYearStatus::Active);
        $selectedYear = $this->createAcademicYear($organization, 2025);

        $this->actingAs($user)
            ->post(route('academic-years.select', $selectedYear))
            ->assertRedirect(route('dashboard'));

        foreach ([route('dashboard'), route('subjects.index')] as $pageUrl) {
            $this->get($pageUrl)->assertInertia(
                fn (Assert $page) => $page
                    ->where('scope.academicYear', $selectedYear->label)
                    ->has('selectableAcademicYears', 2)
                    ->where('selectableAcademicYears.0.is_current', false)
                    ->where('selectableAcademicYears.1.ulid', $selectedYear->ulid)
                    ->where('selectableAcademicYears.1.is_current', true),
            );
        }
    }

    #[Test]
    public function an_academic_year_from_another_organization_cannot_be_selected(): void
    {
        $owner = User::factory()->create();
        $foreignYear = $this->createAcademicYear($owner->personalOrganization(), 2026);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->post(route('academic-years.select', $foreignYear))
            ->assertNotFound();
    }

    #[Test]
    public function an_organization_without_academic_years_shares_an_empty_context(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('scope.academicYear', null)
                ->where('selectableAcademicYears', []),
        );
    }

    #[Test]
    public function scope_reports_that_no_subjects_exist(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page->where('scope.hasSubjects', false),
        );
    }

    #[Test]
    public function scope_reports_that_subjects_exist_in_the_current_organization(): void
    {
        $user = User::factory()->create();
        $this->forTenant(
            $user->personalOrganization(),
            fn () => Subject::factory()->recycle($user->personalOrganization())->create(),
        );

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page->where('scope.hasSubjects', true),
        );
    }

    #[Test]
    public function selectable_academic_years_contains_the_current_year_and_at_most_three_previous_years(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();

        foreach (range(2021, 2026) as $startYear) {
            $this->createAcademicYear(
                $organization,
                $startYear,
                $startYear === 2026 ? AcademicYearStatus::Active : AcademicYearStatus::Closed,
            );
        }

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->has('selectableAcademicYears', 4)
                ->where('selectableAcademicYears.0.label', '2026/2027')
                ->where('selectableAcademicYears.0.is_current', true)
                ->where('selectableAcademicYears.1.label', '2025/2026')
                ->where('selectableAcademicYears.2.label', '2024/2025')
                ->where('selectableAcademicYears.3.label', '2023/2024'),
        );
    }

    #[Test]
    public function selecting_an_older_year_does_not_hide_more_recent_years_from_the_list(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        $currentYear = $this->createAcademicYear($organization, 2025, AcademicYearStatus::Active);
        $olderYear = $this->createAcademicYear($organization, 2024);

        $this->actingAs($user)
            ->post(route('academic-years.select', $olderYear))
            ->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('scope.academicYear', $olderYear->label)
                ->has('selectableAcademicYears', 2)
                ->where('selectableAcademicYears.0.ulid', $currentYear->ulid)
                ->where('selectableAcademicYears.0.is_current', false)
                ->where('selectableAcademicYears.1.ulid', $olderYear->ulid)
                ->where('selectableAcademicYears.1.is_current', true),
        );
    }

    #[Test]
    public function a_newly_created_draft_year_is_selectable_even_before_it_becomes_the_resolved_current_year(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        $this->createAcademicYear($organization, 2025, AcademicYearStatus::Active);
        $draftYear = $this->createAcademicYear($organization, 2026, AcademicYearStatus::Draft);

        // AcademicYearRetentionClassifier still resolves the single Active year
        // as "current" — untouched by this fatia — but the newer draft year
        // must still show up so the teacher can switch into it to prepare it.
        $this->actingAs($user)->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page
                ->where('scope.academicYear', '2025/2026')
                ->has('selectableAcademicYears', 2)
                ->where('selectableAcademicYears.0.ulid', $draftYear->ulid)
                ->where('selectableAcademicYears.0.is_current', false),
        );

        $this->actingAs($user)
            ->post(route('academic-years.select', $draftYear))
            ->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertInertia(
            fn (Assert $page) => $page->where('scope.academicYear', $draftYear->label),
        );
    }

    private function createAcademicYear(
        Organization $organization,
        int $startYear,
        AcademicYearStatus $status = AcademicYearStatus::Closed,
    ): AcademicYear {
        return $this->forTenant(
            $organization,
            fn () => AcademicYear::factory()->recycle($organization)->create([
                'label' => sprintf('%d/%d', $startYear, $startYear + 1),
                'starts_on' => sprintf('%d-09-01', $startYear),
                'ends_on' => sprintf('%d-08-31', $startYear + 1),
                'status' => $status,
            ]),
        );
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
