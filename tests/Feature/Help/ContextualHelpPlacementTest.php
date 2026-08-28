<?php

namespace Tests\Feature\Help;

use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RosterFixture;
use Tests\TestCase;

/**
 * "Precisa de ajuda?" (A2, Onboarding & Help) is wired to exactly 3 pages —
 * see ContextualHelp.vue's own docblock for why not more. This asserts each
 * of the 3 controllers passes a `helpArticles` prop naming the right
 * article, and that an unrelated page carries no such prop at all.
 */
class ContextualHelpPlacementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->organization = $this->user->personalOrganization();
    }

    protected function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    #[Test]
    public function assessment_profiles_create_carries_the_assessment_profiles_help_article(): void
    {
        $this->actingAs($this->user)
            ->get('/assessment-profiles/create')
            ->assertInertia(fn ($page) => $page
                ->component('assessment-profiles/Create')
                ->where('helpArticles.0.id', 'assessment.profiles'));
    }

    #[Test]
    public function instruments_create_carries_the_instruments_create_help_article(): void
    {
        $class = $this->inTenant(function (): SchoolClass {
            $org = $this->organization;
            $year = AcademicYear::factory()->recycle($org)->create();
            $subject = Subject::factory()->recycle($org)->create();
            $class = SchoolClass::factory()->recycle($org)->create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
            ]);
            $class->teachers()->attach($this->user, ['role' => 'owner']);

            return $class;
        });

        $this->actingAs($this->user)
            ->get("/classes/{$class->ulid}/instruments/create")
            ->assertInertia(fn ($page) => $page
                ->component('instruments/Create')
                ->where('helpArticles.0.id', 'instruments.create'));
    }

    #[Test]
    public function roster_import_preview_carries_the_students_import_help_article(): void
    {
        $context = $this->inTenant(fn (): array => [
            'year' => AcademicYear::factory()->recycle($this->organization)->create()->id,
            'subject' => Subject::factory()->recycle($this->organization)->create()->id,
        ]);
        $this->actingAs($this->user)->post('/classes', [
            'label' => '7.º A',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);
        $class = SchoolClass::withoutGlobalScope('organization')->firstOrFail();

        $excel = UploadedFile::fake()->createWithContent(
            'roster.xlsx',
            file_get_contents((new RosterFixture)->build()),
        );

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/roster-imports", ['roster' => $excel])
            ->assertInertia(fn ($page) => $page
                ->component('roster-imports/Preview')
                ->where('helpArticles.0.id', 'students.import'));
    }

    #[Test]
    public function an_unrelated_page_carries_no_help_articles_prop_at_all(): void
    {
        $this->actingAs($this->user)
            ->get('/classes')
            ->assertInertia(fn ($page) => $page
                ->component('classes/Index')
                ->missing('helpArticles'));
    }
}
