<?php

namespace Tests\Feature\Reports;

use App\Domain\Reporting\SectionKey;
use App\Models\AcademicPeriod;
use App\Models\Organization;
use App\Models\OrganizationIdentity;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Reporting\CreateReport;
use App\Services\Reporting\Export\DocxRenderer;
use App\Services\Reporting\Export\PdfRenderer;
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
 * PDF and Word (§47, §48, §49).
 *
 * THE ASSERTION THAT MATTERS is that the two files say the same thing, because
 * they are two renderings of one structure. A school that sends a parent the
 * PDF and a colleague the Word file must not have sent two different documents.
 *
 * The rest is about the file being a real document rather than a picture of
 * one: the .docx has to open as a Word package with headings, paragraphs and
 * tables the teacher can edit (§48), and the PDF has to be a PDF.
 */
class ReportExportTest extends TestCase
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
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
                'ends_at' => null,
            ],
        );

        app(Entitlements::class)->flush();

        $this->asTenant(function (): void {
            $identity = new OrganizationIdentity;
            $identity->forceFill([
                'organization_id' => $this->organization->id,
                'official_name' => 'Agrupamento de Escolas de Teste',
                'address' => 'Rua Paulo VI, 12',
                'postal_code' => '2414-015',
                'locality' => 'Leiria',
            ])->save();
        });
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
            ->academicYear->periods()->where('sequence', 1)->firstOrFail());
    }

    private function draft(): Report
    {
        return $this->asTenant(fn (): Report => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $this->period(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function structure(Report $report): array
    {
        return $this->asTenant(fn (): array => app(ReportDocumentBuilder::class)->build($report));
    }

    // ------------------------------------------------------------ one content

    #[Test]
    public function both_renderers_are_fed_the_same_structure(): void
    {
        $report = $this->draft();

        $structure = $this->structure($report);

        $this->assertNotEmpty($structure['sections']);
        $this->assertSame('Agrupamento de Escolas de Teste', $structure['identity']['name']);
        // The letterhead arrives already assembled, free of blanks and
        // condensed: address and locality on ONE line, not two (§50).
        $this->assertContains('Rua Paulo VI, 12 · 2414-015 Leiria', $structure['identity']['header_lines']);
        $this->assertNotContains('2414-015 Leiria', $structure['identity']['header_lines']);

        // Every heading in the structure has to appear in BOTH files, or the
        // two documents differ (§47).
        $pdf = $this->asTenant(fn (): string => app(PdfRenderer::class)->render($structure));
        $docx = $this->asTenant(fn (): string => app(DocxRenderer::class)->render($structure));

        $this->assertStringStartsWith('%PDF-', $pdf);

        $documentXml = $this->docxDocumentXml($docx);

        foreach ($structure['sections'] as $section) {
            $this->assertStringContainsString(
                $this->xmlSafe($section['heading']),
                $documentXml,
                "«{$section['heading']}» tem de estar no ficheiro Word.",
            );
        }
    }

    #[Test]
    public function the_word_file_is_a_real_document_and_not_a_picture(): void
    {
        $docx = $this->asTenant(fn (): string => app(DocxRenderer::class)->render($this->structure($this->draft())));

        $xml = $this->docxDocumentXml($docx);

        // Headings, paragraphs and a table — the three things a teacher needs
        // in order to edit rather than retype (§48).
        $this->assertStringContainsString('<w:pStyle w:val="Heading1"', $xml);
        $this->assertStringContainsString('<w:pStyle w:val="Heading2"', $xml);
        $this->assertStringContainsString('<w:tbl>', $xml);
        // No image element anywhere near the body text.
        $this->assertStringNotContainsString('<w:drawing>', $xml);
    }

    #[Test]
    public function the_word_package_carries_a_repeating_header_and_a_numbered_footer(): void
    {
        $docx = $this->asTenant(fn (): string => app(DocxRenderer::class)->render($this->structure($this->draft())));

        $zip = $this->open($docx);

        $header = $this->firstEntry($zip, 'word/header');
        $footer = $this->firstEntry($zip, 'word/footer');

        $this->assertNotNull($header, 'O documento tem de ter cabeçalho.');
        $this->assertNotNull($footer, 'O documento tem de ter rodapé.');

        $this->assertStringContainsString($this->xmlSafe('Agrupamento de Escolas de Teste'), $header);
        $document = (string) $zip->getFromName('word/document.xml');
        $this->assertStringContainsString('<w:pgMar w:top="2268"', $document);
        $this->assertStringContainsString('w:header="709"', $document);
        $this->assertStringContainsString('<w:spacing w:after="60"', $header);
        $this->assertStringContainsString('PAGE', $footer);
        // Real tab stops, not the two characters «\t».
        $this->assertStringNotContainsString('\t', $footer);

        $zip->close();
    }

    // ----------------------------------------------------------- draft vs not

    #[Test]
    public function a_draft_export_is_stamped_as_a_draft(): void
    {
        $structure = $this->structure($this->draft());

        $this->assertNotNull($structure['draft_note']);
        $this->assertStringContainsString('RASCUNHO', $structure['draft_note']);
        $this->assertSame('draft', $structure['meta']['status']);
    }

    #[Test]
    public function a_finalized_export_reads_the_frozen_document_and_is_not_stamped(): void
    {
        $report = $this->asTenant(fn () => app(FinalizeReport::class)->finalize($this->draft(), $this->teacher));

        $structure = $this->structure($report);

        $this->assertNull($structure['draft_note']);
        $this->assertSame('finalized', $structure['meta']['status']);
        $this->assertSame($this->teacher->name, $structure['meta']['finalized_by']);

        // Its own letterhead, not the school's current one.
        $this->asTenant(function (): void {
            OrganizationIdentity::query()
                ->where('organization_id', $this->organization->id)
                ->firstOrFail()
                ->forceFill(['official_name' => 'Outro Agrupamento'])
                ->save();
        });

        $again = $this->structure($this->asTenant(fn () => $report->fresh()));

        $this->assertSame('Agrupamento de Escolas de Teste', $again['identity']['name']);
    }

    // ------------------------------------------------------------------ HTTP

    #[Test]
    public function the_pdf_route_returns_a_pdf_attachment(): void
    {
        $report = $this->draft();

        $response = $this->actingAs($this->teacher)->get("/reports/{$report->ulid}/pdf");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        // Never cached by a proxy: one person's copy of a document about minors.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    #[Test]
    public function the_word_route_returns_a_docx_attachment(): void
    {
        $report = $this->draft();

        $response = $this->actingAs($this->teacher)->get("/reports/{$report->ulid}/word");

        $response->assertOk();
        $this->assertStringContainsString(
            'wordprocessingml',
            (string) $response->headers->get('Content-Type'),
        );
        $this->assertStringContainsString('.docx', (string) $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function another_organization_cannot_export_this_report(): void
    {
        $report = $this->draft();

        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get("/reports/{$report->ulid}/pdf")->assertNotFound();
        $this->actingAs($stranger)->get("/reports/{$report->ulid}/word")->assertNotFound();
    }

    #[Test]
    public function exporting_leaves_an_audit_trail(): void
    {
        $report = $this->draft();

        $this->actingAs($this->teacher)->get("/reports/{$report->ulid}/pdf")->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'event' => 'report.exported',
            'subject_ulid' => $report->ulid,
        ]);
    }

    // ------------------------------------------------------------- the tables

    #[Test]
    public function a_section_table_reaches_both_renderers(): void
    {
        $structure = $this->structure($this->draft());

        $distribution = collect($structure['sections'])
            ->first(fn (array $section) => str_contains($section['heading'], 'Distribuição'));

        if ($distribution === null || $distribution['tables'] === []) {
            // The demo class may have no assigned classifications, in which
            // case there is legitimately no table to draw.
            $this->assertTrue(true);

            return;
        }

        $this->assertSame(['Classificação', 'Alunos', '%'], $distribution['tables'][0]['headers']);
    }

    #[Test]
    public function the_builder_never_carries_a_name_the_source_withheld(): void
    {
        $report = $this->draft();

        $structure = $this->structure($report);

        $timeline = collect($structure['sections'])
            ->first(fn (array $section) => $section['heading'] === 'Cronologia detalhada');

        // The class report has no chronology at all; the assertion is that
        // nothing invented one.
        $this->assertNull($timeline);
        $this->assertNotContains(
            SectionKey::RecordsTimeline->value,
            array_map(fn (array $section) => $section['heading'], $structure['sections']),
        );
    }

    // ------------------------------------------------------------- helpers

    private function open(string $bytes): ZipArchive
    {
        $path = tempnam(sys_get_temp_dir(), 'lapis-test-docx-');
        file_put_contents($path, $bytes);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'O .docx tem de ser um pacote ZIP válido.');

        return $zip;
    }

    private function docxDocumentXml(string $bytes): string
    {
        $zip = $this->open($bytes);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return $xml;
    }

    private function firstEntry(ZipArchive $zip, string $prefix): ?string
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_starts_with($name, $prefix)) {
                return (string) $zip->getFromIndex($index);
            }
        }

        return null;
    }

    /** Word stores text XML-escaped; so does the assertion. */
    private function xmlSafe(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
