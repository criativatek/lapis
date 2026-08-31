<?php

namespace Tests\Feature\Reports;

use App\Models\AcademicPeriod;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Reporting\CreateReport;
use App\Services\Reporting\Export\ReportDocumentBuilder;
use App\Services\Reporting\FinalizeReport;
use App\Services\Reporting\Narrative\Phrase;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE SCREEN IS A FOURTH RENDERING OF THE SAME DOCUMENT (§37).
 *
 * `reports/Show` promises, in its own template, to carry «the same closing the
 * .docx and the PDF carry». It kept that promise by retyping the closing and by
 * printing the section body through `nl2br` — so when the document stopped
 * saying «O(A) professor(a)» and started emitting real lists, the screen went on
 * saying and doing what it always had. Two renderings of one document that
 * disagree is a defect in the document.
 *
 * WHAT THIS GUARDS IS THE CONTRACT, NOT THE PIXELS. The .docx and the PDF have
 * their own tests, and the rendering itself is proved on the Vue side
 * (`resources/js/pages/reports/Show.test.ts`), which is where the markup lives.
 * What no test could see until now is the seam between them: whether the props
 * the controller sends are the same structure the exported file is built from.
 * A screen that renders perfectly from props the server never sends is still a
 * broken screen.
 */
class ReportPreviewParityTest extends TestCase
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

        $this->seed(EntitlementsSeeder::class);

        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', 'base')->firstOrFail()->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->subDay(),
                'ends_at' => null,
            ],
        );

        app(Entitlements::class)->flush();
    }

    // ------------------------------------------------------- o encerramento

    #[Test]
    public function the_screen_is_told_the_caption_rather_than_keeping_its_own(): void
    {
        $report = $this->draft();

        $this->actingAs($this->teacher)
            ->get("/reports/{$report->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports/Show')
                ->where('signatureCaption', ReportDocumentBuilder::SIGNATURE_CAPTION));
    }

    #[Test]
    public function the_caption_the_screen_receives_names_a_role(): void
    {
        $report = $this->draft();

        $this->actingAs($this->teacher)
            ->get("/reports/{$report->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('signatureCaption', 'Docente responsável'));
    }

    #[Test]
    public function a_finalized_report_is_told_the_same_caption(): void
    {
        $report = $this->finalized();

        $this->actingAs($this->teacher)
            ->get("/reports/{$report->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('signatureCaption', ReportDocumentBuilder::SIGNATURE_CAPTION));
    }

    // ------------------------------------------------------------- os blocos

    #[Test]
    public function a_draft_sends_the_screen_the_blocks_the_document_is_built_from(): void
    {
        $this->assertBlocksMatchTheDocument($this->draft());
    }

    #[Test]
    public function a_finalized_report_sends_the_screen_the_same_blocks(): void
    {
        $this->assertBlocksMatchTheDocument($this->finalized());
    }

    #[Test]
    public function the_item_marker_never_travels_inside_a_block(): void
    {
        $report = $this->draft();

        $this->actingAs($this->teacher)
            ->get("/reports/{$report->ulid}")
            ->assertOk()
            ->assertInertia(function ($page) {
                foreach ($page->toArray()['props']['sections'] as $section) {
                    foreach ($section['blocks'] as $block) {
                        // A paragraph may legitimately contain a dash a teacher
                        // typed. An item may not: the marker is what said «this
                        // line is an item», and by this point it has been read.
                        foreach ($block['items'] ?? [] as $item) {
                            $this->assertStringNotContainsString(
                                Phrase::ITEM_MARKER,
                                $item,
                                'O marcador de item chegou ao ecrã como texto.',
                            );
                        }
                    }
                }
            });
    }

    #[Test]
    public function every_section_carries_blocks_and_the_body_it_is_still_edited_as(): void
    {
        $report = $this->draft();

        $this->actingAs($this->teacher)
            ->get("/reports/{$report->ulid}")
            ->assertOk()
            ->assertInertia(function ($page) {
                $sections = $page->toArray()['props']['sections'];

                $this->assertNotEmpty($sections);

                foreach ($sections as $section) {
                    // Both, and for the same reason: `blocks` is what the
                    // document is printed from, `body` is what the teacher opens
                    // in the textarea. Dropping either breaks one of the two.
                    $this->assertArrayHasKey('blocks', $section);
                    $this->assertArrayHasKey('body', $section);
                    $this->assertIsArray($section['blocks']);
                }
            });
    }

    // ------------------------------------------------------------- helpers

    /**
     * The blocks on the screen are the blocks in the file — compared against
     * the builder itself, so the two can only drift by changing the boundary
     * they both read.
     */
    private function assertBlocksMatchTheDocument(Report $report): void
    {
        $builder = app(ReportDocumentBuilder::class);

        $this->actingAs($this->teacher)
            ->get("/reports/{$report->ulid}")
            ->assertOk()
            ->assertInertia(function ($page) use ($builder) {
                $sections = $page->toArray()['props']['sections'];

                $this->assertNotEmpty($sections);

                foreach ($sections as $section) {
                    $this->assertSame(
                        $builder->blocks($section['body']),
                        $section['blocks'],
                        "A secção «{$section['heading']}» chega ao ecrã com uma estrutura diferente da do ficheiro.",
                    );
                }
            });
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function draft(array $options = []): Report
    {
        return $this->asTenant(function () use ($options): Report {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            return app(CreateReport::class)->forClass(
                class: $class,
                author: $this->teacher,
                period: AcademicPeriod::query()
                    ->where('academic_year_id', $class->academic_year_id)
                    ->where('sequence', 1)
                    ->firstOrFail(),
                options: $options,
            );
        });
    }

    private function finalized(): Report
    {
        return $this->asTenant(
            fn () => app(FinalizeReport::class)->finalize($this->draft(), $this->teacher),
        );
    }

    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }
}
