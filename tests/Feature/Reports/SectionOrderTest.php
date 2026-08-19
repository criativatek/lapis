<?php

namespace Tests\Feature\Reports;

use App\Domain\Reporting\SectionKey;
use App\Models\AcademicPeriod;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportSection;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Reporting\ComposeReport;
use App\Services\Reporting\CreateReport;
use App\Services\Reporting\Export\DocxRenderer;
use App\Services\Reporting\Export\ReportDocumentBuilder;
use App\Services\Reporting\FinalizeReport;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Reordering the sections of a draft (§9–§12, §31–§36).
 *
 * THE ONE THING THAT MUST NOT HAPPEN is losing text. A teacher who spent twenty
 * minutes rewriting «Síntese final» and then drags it above «Evolução» must
 * find their paragraph exactly where they left it. Position is the only column
 * that moves; everything else — the body, the automatic text underneath it, the
 * edited flag, the provenance, the figures — is untouched.
 *
 * And the order has to SURVIVE: regenerating, restoring, excluding and
 * including all leave it alone, and both exports and the preview follow it
 * rather than the catalogue's.
 */
class SectionOrderTest extends TestCase
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
                'plan_id' => Plan::where('key', 'pro')->firstOrFail()->id,
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

    private function period(): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->orderBy('sequence')->firstOrFail());
    }

    private function report(): Report
    {
        return $this->asTenant(fn (): Report => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $this->period(),
        ));
    }

    /**
     * @return list<string>
     */
    private function orderOf(Report $report): array
    {
        return $this->asTenant(fn (): array => $report->fresh()->sections()
            ->orderBy('position')->pluck('key')->all());
    }

    /**
     * The current order with one section pulled to the front — the shape the
     * editor posts.
     *
     * @return list<string>
     */
    private function ulidsWithFirst(Report $report, SectionKey $key): array
    {
        $sections = $this->asTenant(fn () => $report->fresh()->sections()->orderBy('position')->get());

        $moved = $sections->firstWhere('key', $key->value);
        $rest = $sections->reject(fn (ReportSection $section) => $section->key === $key->value);

        return array_values([$moved->ulid, ...$rest->pluck('ulid')->all()]);
    }

    // ----------------------------------------------------------- §33 the core

    #[Test]
    public function moving_a_section_changes_its_position_and_nothing_else(): void
    {
        $report = $this->report();

        $before = $this->asTenant(fn () => $report->sections()
            ->where('key', SectionKey::FinalSynthesis->value)->firstOrFail());

        // The teacher rewrites it.
        $this->asTenant(fn () => $before->update([
            'body' => 'A minha própria síntese, escrita à mão.',
            'edited' => true,
        ]));

        $original = $this->asTenant(fn () => $before->fresh());

        $this->actingAs($this->teacher)
            ->put("/reports/{$report->ulid}/seccoes/ordem", [
                'order' => $this->ulidsWithFirst($report, SectionKey::FinalSynthesis),
            ])
            ->assertRedirect();

        $moved = $this->asTenant(fn () => $before->fresh());

        // It moved.
        $this->assertSame(SectionKey::FinalSynthesis->value, $this->orderOf($report)[0]);
        $this->assertNotSame($original->position, $moved->position);

        // And nothing else did.
        $this->assertSame('A minha própria síntese, escrita à mão.', $moved->body);
        $this->assertSame($original->generated_body, $moved->generated_body);
        $this->assertTrue($moved->edited);
        $this->assertSame($original->sources, $moved->sources);
        $this->assertSame($original->data, $moved->data);
        $this->assertSame($original->included, $moved->included);
        $this->assertSame($original->heading, $moved->heading);
    }

    // ------------------------------------------------------------ §12 frozen

    #[Test]
    public function a_finalized_report_cannot_be_reordered(): void
    {
        $report = $this->report();
        $order = $this->ulidsWithFirst($report, SectionKey::FinalSynthesis);

        $this->asTenant(fn () => app(FinalizeReport::class)->finalize($report, $this->teacher));

        $this->actingAs($this->teacher)
            ->put("/reports/{$report->ulid}/seccoes/ordem", ['order' => $order])
            ->assertForbidden();
    }

    // -------------------------------------------------------- §34, §35, §36

    #[Test]
    public function regenerating_does_not_put_the_default_order_back(): void
    {
        $report = $this->report();

        $this->actingAs($this->teacher)->put("/reports/{$report->ulid}/seccoes/ordem", [
            'order' => $this->ulidsWithFirst($report, SectionKey::FinalSynthesis),
        ]);

        $reordered = $this->orderOf($report);

        $this->asTenant(fn () => app(ComposeReport::class)->generate($report->fresh()));

        $this->assertSame($reordered, $this->orderOf($report));
        $this->assertSame(SectionKey::FinalSynthesis->value, $this->orderOf($report)[0]);
    }

    #[Test]
    public function restoring_the_automatic_text_does_not_move_anything(): void
    {
        $report = $this->report();

        $this->actingAs($this->teacher)->put("/reports/{$report->ulid}/seccoes/ordem", [
            'order' => $this->ulidsWithFirst($report, SectionKey::FinalSynthesis),
        ]);

        $reordered = $this->orderOf($report);

        $section = $this->asTenant(fn () => $report->fresh()->sections()
            ->where('key', SectionKey::OverallAssessment->value)->firstOrFail());

        $this->asTenant(function () use ($section): void {
            $section->update(['body' => 'Outra coisa.', 'edited' => true]);
            app(ComposeReport::class)->restore($section);
        });

        $this->assertSame($reordered, $this->orderOf($report));
    }

    #[Test]
    public function excluding_and_reincluding_a_section_leaves_the_order_alone(): void
    {
        $report = $this->report();

        $this->actingAs($this->teacher)->put("/reports/{$report->ulid}/seccoes/ordem", [
            'order' => $this->ulidsWithFirst($report, SectionKey::FinalSynthesis),
        ]);

        $reordered = $this->orderOf($report);

        $section = $this->asTenant(fn () => $report->fresh()->sections()
            ->where('key', SectionKey::DomainResults->value)->firstOrFail());

        $this->actingAs($this->teacher)
            ->put("/reports/{$report->ulid}/seccoes/{$section->ulid}", ['included' => false]);

        $this->assertSame($reordered, $this->orderOf($report));

        // And it comes back where it was (§36).
        $this->actingAs($this->teacher)
            ->put("/reports/{$report->ulid}/seccoes/{$section->ulid}", ['included' => true]);

        $this->assertSame($reordered, $this->orderOf($report));
        $this->assertTrue($this->asTenant(fn () => $section->fresh()->included));
    }

    // ------------------------------------------------------- §31, §32 output

    #[Test]
    public function the_preview_payload_follows_the_chosen_order(): void
    {
        $report = $this->report();

        $this->actingAs($this->teacher)->put("/reports/{$report->ulid}/seccoes/ordem", [
            'order' => $this->ulidsWithFirst($report, SectionKey::FinalSynthesis),
        ]);

        $this->actingAs($this->teacher)
            ->get("/reports/{$report->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections.0.key', SectionKey::FinalSynthesis->value));
    }

    #[Test]
    public function both_exports_follow_the_chosen_order(): void
    {
        $report = $this->report();

        $this->actingAs($this->teacher)->put("/reports/{$report->ulid}/seccoes/ordem", [
            'order' => $this->ulidsWithFirst($report, SectionKey::FinalSynthesis),
        ]);

        $structure = $this->asTenant(fn (): array => app(ReportDocumentBuilder::class)->build($report->fresh()));

        $headings = array_map(fn (array $section) => $section['heading'], $structure['sections']);

        $this->assertSame('Síntese final e perspetivas', $headings[0]);

        // The Word file is built from that same structure, so its first heading
        // has to be the same one (§31).
        $docx = $this->asTenant(fn (): string => app(DocxRenderer::class)->render($structure));

        $path = tempnam(sys_get_temp_dir(), 'lapis-order-');
        file_put_contents($path, $docx);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        $first = mb_strpos($xml, htmlspecialchars('Síntese final e perspetivas', ENT_QUOTES, 'UTF-8'));
        $second = mb_strpos($xml, htmlspecialchars('Identificação e caracterização da turma', ENT_QUOTES, 'UTF-8'));

        $this->assertNotFalse($first);
        $this->assertNotFalse($second);
        $this->assertLessThan($second, $first, 'A ordem escolhida tem de chegar ao documento.');
    }

    #[Test]
    public function a_finalized_document_freezes_the_order_it_was_signed_with(): void
    {
        $report = $this->report();

        $this->actingAs($this->teacher)->put("/reports/{$report->ulid}/seccoes/ordem", [
            'order' => $this->ulidsWithFirst($report, SectionKey::FinalSynthesis),
        ]);

        $finalized = $this->asTenant(fn () => app(FinalizeReport::class)->finalize($report->fresh(), $this->teacher));

        $keys = array_map(fn (array $section) => $section['key'], $finalized->document['sections']);

        $this->assertSame(SectionKey::FinalSynthesis->value, $keys[0]);
    }

    // ------------------------------------------------------------- integrity

    #[Test]
    public function a_section_ulid_from_another_report_is_ignored_rather_than_moved(): void
    {
        $mine = $this->report();
        $other = $this->report();

        $foreign = $this->asTenant(fn () => $other->sections()->firstOrFail());
        $before = $this->orderOf($mine);

        $this->actingAs($this->teacher)
            ->put("/reports/{$mine->ulid}/seccoes/ordem", ['order' => [$foreign->ulid]])
            ->assertRedirect();

        // The other report is untouched, and so is this one's order.
        $this->assertSame($before, $this->orderOf($mine));
        $this->assertSame($before, $this->orderOf($other));
    }

    #[Test]
    public function a_partial_order_leaves_the_sections_it_did_not_mention_behind_the_ones_it_did(): void
    {
        $report = $this->report();

        $sections = $this->asTenant(fn () => $report->sections()->orderBy('position')->get());
        $last = $sections->last();

        $this->actingAs($this->teacher)
            ->put("/reports/{$report->ulid}/seccoes/ordem", ['order' => [$last->ulid]])
            ->assertRedirect();

        $order = $this->orderOf($report);

        $this->assertSame($last->key, $order[0]);
        // Everything else is still there, in its own order.
        $this->assertCount($sections->count(), $order);
    }
}
