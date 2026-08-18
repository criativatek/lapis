<?php

namespace Tests\Feature\Reports;

use App\Domain\Reporting\SectionKey;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The module through HTTP: routes, gates, and the cross-tenant refusals.
 *
 * THE TENANT ASSERTIONS ARE THE POINT OF THIS FILE (§63). A report holds a
 * summary of a class of minors' grades. One organization reaching another's —
 * by guessing a ulid, by posting a foreign class id, by asking for a section of
 * somebody else's draft — is a personal-data breach, not a bug report.
 */
class ReportHttpTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->organization = $this->teacher->personalOrganization();

        $this->travelTo(Carbon::parse('2027-06-30 12:00:00'));

        $this->givePlan('base');
    }

    private function givePlan(string $key): void
    {
        $this->seed(EntitlementsSeeder::class);

        $plan = Plan::where('key', $key)->firstOrFail();

        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->subDay(),
                'ends_at' => null,
            ],
        );

        app(Entitlements::class)->flush();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    // ---------------------------------------------------------------- flow

    #[Test]
    public function the_hub_lists_reports(): void
    {
        $this->actingAs($this->teacher)
            ->get('/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('reports/Index')->has('availableTypes'));
    }

    #[Test]
    public function the_creation_screen_shows_the_whole_catalogue_including_what_the_plan_lacks(): void
    {
        $this->actingAs($this->teacher)
            ->get('/reports/novo')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports/Create')
                ->where('catalogue', fn ($catalogue) => collect($catalogue)
                    ->contains(fn ($row) => $row['key'] === SectionKey::Difficulties->value && $row['available'] === false)));
    }

    #[Test]
    public function creating_a_report_redirects_to_it_and_it_has_generated_text(): void
    {
        $class = $this->schoolClass();
        $period = $this->asTenant(fn () => $class->academicYear->periods()->where('sequence', 1)->firstOrFail());

        $response = $this->actingAs($this->teacher)->post('/reports', [
            'type' => 'class',
            'class_id' => $class->id,
            'academic_period_id' => $period->id,
        ]);

        $report = $this->asTenant(fn () => Report::query()->latest('id')->firstOrFail());

        $response->assertRedirect("/reports/{$report->ulid}");

        $this->actingAs($this->teacher)
            ->get("/reports/{$report->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports/Show')
                ->where('sections', fn ($sections) => collect($sections)
                    ->contains(fn ($section) => $section['key'] === SectionKey::ClassIdentification->value
                        && $section['body'] !== null)));
    }

    #[Test]
    public function editing_a_section_marks_it_as_the_teachers(): void
    {
        $report = $this->createReport();
        $section = $this->asTenant(fn () => $report->sections()
            ->where('key', SectionKey::OverallAssessment->value)->firstOrFail());

        $this->actingAs($this->teacher)
            ->put("/reports/{$report->ulid}/seccoes/{$section->ulid}", ['body' => 'Texto meu.'])
            ->assertRedirect();

        $fresh = $this->asTenant(fn () => $section->fresh());

        $this->assertSame('Texto meu.', $fresh->body);
        $this->assertTrue($fresh->edited);
        // The automatic text is kept, so it can be restored.
        $this->assertNotNull($fresh->generated_body);
    }

    #[Test]
    public function a_section_of_another_report_cannot_be_edited_through_this_one(): void
    {
        $mine = $this->createReport();
        $other = $this->createReport();

        $section = $this->asTenant(fn () => $other->sections()->firstOrFail());

        $this->actingAs($this->teacher)
            ->put("/reports/{$mine->ulid}/seccoes/{$section->ulid}", ['body' => 'Injetado.'])
            ->assertNotFound();
    }

    // ------------------------------------------------------------- tenancy

    #[Test]
    public function another_organizations_report_is_not_found(): void
    {
        $report = $this->createReport();

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get("/reports/{$report->ulid}")
            ->assertNotFound();
    }

    #[Test]
    public function a_foreign_class_id_is_refused_by_validation_and_not_by_luck(): void
    {
        $stranger = User::factory()->create();
        $strangerOrganization = $stranger->personalOrganization();

        $foreignClass = app(CurrentOrganization::class)->runFor(
            $strangerOrganization,
            fn () => SchoolClass::factory()->recycle($strangerOrganization)->create(),
        );

        $this->actingAs($this->teacher)
            ->post('/reports', ['type' => 'class', 'class_id' => $foreignClass->id])
            ->assertSessionHasErrors('class_id');
    }

    #[Test]
    public function a_colleague_who_does_not_teach_the_class_may_not_open_the_report(): void
    {
        $report = $this->createReport();

        $outsider = User::factory()->create();
        $this->organization->members()->syncWithoutDetaching([$outsider->id => ['joined_at' => now()]]);

        // Reaching the tenant but not the class: the policy is what stops this.
        $this->actingAs($outsider)
            ->get("/reports/{$report->ulid}")
            ->assertForbidden();
    }

    #[Test]
    public function a_draft_can_be_deleted_and_a_finalized_report_cannot(): void
    {
        $draft = $this->createReport();

        $this->actingAs($this->teacher)
            ->delete("/reports/{$draft->ulid}")
            ->assertRedirect('/reports');

        $finalized = $this->asTenant(fn () => Report::factory()
            ->recycle($this->organization)
            ->finalized()
            ->create(['class_id' => $this->schoolClass()->id, 'created_by' => $this->teacher->id]));

        $this->actingAs($this->teacher)
            ->delete("/reports/{$finalized->ulid}")
            ->assertForbidden();
    }

    private function createReport(): Report
    {
        $class = $this->schoolClass();
        $period = $this->asTenant(fn () => $class->academicYear->periods()->where('sequence', 1)->firstOrFail());

        $this->actingAs($this->teacher)->post('/reports', [
            'type' => 'class',
            'class_id' => $class->id,
            'academic_period_id' => $period->id,
        ]);

        return $this->asTenant(fn () => Report::query()->latest('id')->firstOrFail());
    }
}
