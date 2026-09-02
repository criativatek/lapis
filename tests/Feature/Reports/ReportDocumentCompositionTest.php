<?php

namespace Tests\Feature\Reports;

use App\Models\AcademicPeriod;
use App\Models\Organization;
use App\Models\OrganizationIdentity;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Documents\SchoolLogoService;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * HOW THE DOCUMENT IS COMPOSED — the letterhead, the title, the logo and the
 * page number, across all three renderings (§47, §49, §50).
 *
 * Separate from ReportExportTest, which asks whether the two files say the same
 * thing. This one asks whether what they say is laid out like a document a
 * school would put its name on: one title rather than two, an institutional
 * name that is the school's, a logo only where somebody asked for one, and a
 * page number that is not «1 / 0».
 */
class ReportDocumentCompositionTest extends TestCase
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

        // Faked LAST, not first: the disk is `local`, which the seeders also
        // write to, and clearing it out from under files another part of the
        // boot has just opened is how this suite ended up with a logo that
        // sometimes existed. Faked here, nothing is in it but what a test put
        // there.
        Storage::fake(SchoolLogoService::DISK);
    }

    // ------------------------------------------------------------- letterhead

    #[Test]
    public function the_institutional_name_is_the_one_the_school_configured(): void
    {
        $this->identity(['official_name' => 'Agrupamento de Escolas de Exemplo']);

        $structure = $this->structure($this->draft());

        $this->assertSame('Agrupamento de Escolas de Exemplo', $structure['identity']['name']);

        // In all three renderings, not only in the structure.
        $this->assertStringContainsString(
            'Agrupamento de Escolas de Exemplo',
            $this->html($structure),
        );
        $this->assertStringContainsString(
            'Agrupamento de Escolas de Exemplo',
            $this->pdfText($this->asTenant(fn (): string => app(PdfRenderer::class)->render($structure))),
        );

        $docx = $this->asTenant(fn (): string => app(DocxRenderer::class)->render($structure));

        $this->assertStringContainsString(
            'Agrupamento de Escolas de Exemplo',
            (string) $this->entry($docx, 'word/header'),
        );
        $this->assertStringContainsString(
            'Agrupamento de Escolas de Exemplo',
            (string) $this->entry($docx, 'word/footer'),
        );
    }

    #[Test]
    public function the_letterhead_is_three_lines_and_not_five(): void
    {
        $this->identity([
            'official_name' => 'Agrupamento de Escolas de Exemplo',
            'address' => 'Rua das Escolas, 12,',
            'postal_code' => '1000-001',
            'locality' => 'Lisboa',
            'phone' => '210 000 000',
            'email' => 'geral@aeexemplo.pt',
            'website' => 'https://aeexemplo.pt/',
        ]);

        $lines = $this->structure($this->draft())['identity']['header_lines'];

        $this->assertSame([
            // The trailing comma goes: the middot is doing its work.
            'Rua das Escolas, 12 · 1000-001 Lisboa',
            '210 000 000 · geral@aeexemplo.pt',
            // A PDF is not clickable paper, so the scheme carries nothing.
            'aeexemplo.pt',
        ], $lines);
    }

    // ------------------------------------------------------------------- logo

    #[Test]
    public function no_logo_is_printed_unless_the_report_asked_for_one(): void
    {
        $this->identity(['official_name' => 'Agrupamento de Escolas de Exemplo']);
        $this->uploadLogo();

        $structure = $this->structure($this->draft());

        // A school having uploaded a logo is not a decision about this document.
        $this->assertNull($structure['identity']['logo']);

        // And nothing reserves space for it: no image, and no empty cell where
        // the image would have been.
        $html = $this->html($structure);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('data:image/', $html);

        $docx = $this->asTenant(fn (): string => app(DocxRenderer::class)->render($structure));

        // ASSERTED ON THE PACKAGE, NOT ON A TAG. PhpWord writes an image as VML
        // — «<w:pict><v:imagedata>» — and never as «<w:drawing>», so looking for
        // the DrawingML tag was a check that passed whether or not a logo was
        // there. A .docx with no image has no `word/media/` part at all, which
        // is true regardless of which of the two formats the library picks.
        $this->assertSame([], $this->mediaEntries($docx));
        $this->assertStringNotContainsString('v:imagedata', (string) $this->entry($docx, 'word/header'));
    }

    #[Test]
    public function the_logo_is_printed_when_the_report_did_ask_for_one(): void
    {
        $this->identity(['official_name' => 'Agrupamento de Escolas de Exemplo']);
        $this->uploadLogo();

        $structure = $this->structure($this->draft(['show_logo' => true]));

        $this->assertNotNull($structure['identity']['logo']);
        $this->assertStringContainsString('data:image/png;base64,', $this->html($structure));

        // The mirror of the assertion above: with a logo, the package carries
        // the image part the letterhead points at.
        $docx = $this->asTenant(fn (): string => app(DocxRenderer::class)->render($structure));

        $this->assertNotEmpty($this->mediaEntries($docx));
        $this->assertStringContainsString('v:imagedata', (string) $this->entry($docx, 'word/header'));
    }

    #[Test]
    public function finalizing_a_report_without_a_logo_copies_no_logo_file(): void
    {
        $this->identity(['official_name' => 'Agrupamento de Escolas de Exemplo']);
        $this->uploadLogo();

        $report = $this->asTenant(fn () => app(FinalizeReport::class)->finalize($this->draft(), $this->teacher));

        $this->assertNull(data_get($report->document, 'identity.logo_path'));
        $this->assertFalse((bool) data_get($report->document, 'identity.has_logo'));

        // Nothing was duplicated for a document that will never print it (§65).
        $this->assertSame(
            [],
            Storage::disk(SchoolLogoService::DISK)->files(FinalizeReport::LOGO_DIRECTORY),
        );
    }

    #[Test]
    public function a_frozen_letterhead_never_carries_an_absolute_url(): void
    {
        $this->identity(['official_name' => 'Agrupamento de Escolas de Exemplo']);
        $this->uploadLogo();

        $report = $this->asTenant(
            fn () => app(FinalizeReport::class)->finalize($this->draft(['show_logo' => true]), $this->teacher),
        );

        $this->assertNotNull(
            data_get($report->document, 'identity.logo_path'),
            'O relatório pediu logótipo, portanto tem de o congelar.',
        );

        $frozen = (string) data_get($report->document, 'identity.logo_url');

        // An absolute address bakes today's APP_URL into a document meant to
        // outlive the installation, and the browser draws a broken image where
        // the letterhead should be.
        $this->assertStringStartsWith('/', $frozen);
        $this->assertStringNotContainsString('http', $frozen);
    }

    #[Test]
    public function the_frozen_logo_route_is_closed_for_a_report_without_a_logo(): void
    {
        $this->identity(['official_name' => 'Agrupamento de Escolas de Exemplo']);
        $this->uploadLogo();

        $report = $this->asTenant(fn () => app(FinalizeReport::class)->finalize($this->draft(), $this->teacher));

        $this->actingAs($this->teacher)->get("/reports/{$report->ulid}/logotipo")->assertNotFound();
    }

    // ------------------------------------------------------------------ title

    #[Test]
    public function the_document_never_prints_two_titles(): void
    {
        $this->identity(['official_name' => 'Agrupamento de Escolas de Exemplo']);

        $structure = $this->structure($this->draft());

        $this->assertSame('Relatório de turma', $structure['title']);
        $this->assertStringContainsString('7.º A', $structure['subtitle']);
        $this->assertStringContainsString('Português', $structure['subtitle']);
        $this->assertStringNotContainsString('Relatório de turma', $structure['subtitle']);

        // In the file too, not only in the structure it was built from.
        $docx = $this->asTenant(fn (): string => app(DocxRenderer::class)->render($structure));
        $body = $this->entry($docx, 'word/document');

        $this->assertSame(1, substr_count((string) $body, '>Relatório de turma<'));
    }

    #[Test]
    public function the_metadata_line_states_the_class_the_subject_and_the_period(): void
    {
        $this->identity(['official_name' => 'Agrupamento de Escolas de Exemplo']);

        $structure = $this->structure($this->draft());

        $this->assertSame('7.º A · Português · 1.º Semestre', $structure['subtitle']);
    }

    #[Test]
    public function a_finalized_document_takes_its_heading_from_its_own_snapshot(): void
    {
        $this->identity(['official_name' => 'Agrupamento de Escolas de Exemplo']);

        $report = $this->asTenant(fn () => app(FinalizeReport::class)->finalize($this->draft(), $this->teacher));

        // The class is renamed afterwards. The signed document does not change.
        $this->asTenant(function (): void {
            SchoolClass::where('label', '7.º A')->firstOrFail()->forceFill(['label' => '7.º Z'])->save();
        });

        $structure = $this->structure($this->asTenant(fn () => $report->fresh()));

        $this->assertStringContainsString('7.º A', $structure['subtitle']);
        $this->assertStringNotContainsString('7.º Z', $structure['subtitle']);
    }

    // -------------------------------------------------------------- pagination

    #[Test]
    public function a_page_total_is_never_printed_as_zero(): void
    {
        // The label itself, at every value the renderer could be handed. dompdf
        // has no `pages` counter, so the total arriving as zero is not a
        // hypothetical — it is what the template used to print.
        $this->assertSame('Página 1 / 3', PdfRenderer::pageLabel(1, 3));
        $this->assertSame('Página 3 / 3', PdfRenderer::pageLabel(3, 3));
        $this->assertSame('Página 1', PdfRenderer::pageLabel(1, 0));
        $this->assertSame('Página 2', PdfRenderer::pageLabel(2, 0));
        $this->assertSame('Página 4', PdfRenderer::pageLabel(4, 2));
    }

    #[Test]
    public function the_template_no_longer_asks_for_a_counter_dompdf_does_not_have(): void
    {
        $html = $this->html($this->structure($this->draft()));

        $this->assertStringNotContainsString('counter(pages)', $html);
        $this->assertStringNotContainsString('counter(page)', $html);
    }

    #[Test]
    public function every_page_of_the_pdf_carries_its_number_and_the_real_total(): void
    {
        $this->identity(['official_name' => 'Agrupamento de Escolas de Exemplo']);

        $pdf = $this->asTenant(
            fn (): string => app(PdfRenderer::class)->render($this->structure($this->draft())),
        );

        $text = $this->pdfText($pdf);

        // Not /u: CPDF writes the accented glyphs in its own single-byte
        // encoding, so the extracted text is not valid UTF-8 and preg would
        // refuse the subject and quietly match nothing at all.
        preg_match_all('/P.gina (\d+)(?: \/ (\d+))?
/', $text, $labels, PREG_SET_ORDER);

        $this->assertNotEmpty($labels, 'Cada página tem de levar o seu número.');

        $total = count($labels);

        foreach ($labels as $index => $label) {
            $this->assertSame((string) ($index + 1), $label[1]);
            $this->assertSame((string) $total, $label[2] ?? null, 'O total nunca pode faltar nem ser zero.');
        }

        $this->assertStringNotContainsString('/ 0', $text);
    }

    // ------------------------------------------------------------------ words

    #[Test]
    public function the_closing_sentence_has_no_space_before_its_full_stop(): void
    {
        $this->identity(['official_name' => 'Agrupamento de Escolas de Exemplo']);

        $report = $this->asTenant(fn () => app(FinalizeReport::class)->finalize($this->draft(), $this->teacher));

        $structure = $this->structure($this->asTenant(fn () => $report->fresh()));

        $this->assertStringContainsString('Relatório finalizado por', $structure['meta']['closing']);
        $this->assertStringEndsWith('.', $structure['meta']['closing']);
        $this->assertStringNotContainsString(' .', $structure['meta']['closing']);

        preg_match('/<div>(Relatório finalizado[^<]*)<\/div>/u', $this->html($structure), $printed);

        $this->assertNotEmpty($printed, 'O documento tem de fechar com a frase de finalização.');
        $this->assertStringNotContainsString(' .', $printed[1]);
    }

    // ------------------------------------------------------------- pedagogical

    #[Test]
    public function none_of_this_moved_a_single_figure(): void
    {
        $this->identity(['official_name' => 'Agrupamento de Escolas de Exemplo']);

        $structure = $this->structure($this->draft());

        $overall = collect($structure['sections'])
            ->first(fn (array $section) => str_contains($section['heading'], 'Síntese da avaliação'));

        $this->assertNotNull($overall, 'A síntese tem de continuar a existir.');

        // The layout work must not have touched what the sections computed: the
        // section still carries its own figures, from its own sources.
        $this->assertNotEmpty($overall['blocks']);

        $distribution = collect($structure['sections'])
            ->first(fn (array $section) => str_contains($section['heading'], 'Distribuição'));

        if ($distribution !== null && $distribution['tables'] !== []) {
            $this->assertSame(['Classificação', 'Alunos', '%'], $distribution['tables'][0]['headers']);
        }
    }

    // ---------------------------------------------------------------- helpers

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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function identity(array $attributes): void
    {
        $this->asTenant(function () use ($attributes): void {
            $identity = new OrganizationIdentity;
            $identity->forceFill([
                'organization_id' => $this->organization->id,
                ...$attributes,
            ])->save();
        });
    }

    private function uploadLogo(): void
    {
        $path = 'school-logos/'.Str::uuid()->toString().'.png';

        // A one-pixel PNG: the assertions are about whether bytes travel, never
        // about what the picture is.
        Storage::disk(SchoolLogoService::DISK)->put($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        ));

        $this->asTenant(function () use ($path): void {
            OrganizationIdentity::query()
                ->where('organization_id', $this->organization->id)
                ->firstOrFail()
                ->forceFill(['logo_path' => $path])
                ->save();
        });
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function draft(array $options = []): Report
    {
        return $this->asTenant(fn (): Report => app(CreateReport::class)->forClass(
            class: SchoolClass::where('label', '7.º A')->firstOrFail(),
            author: $this->teacher,
            period: AcademicPeriod::query()
                ->where('academic_year_id', SchoolClass::where('label', '7.º A')->firstOrFail()->academic_year_id)
                ->where('sequence', 1)
                ->firstOrFail(),
            options: $options,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function structure(Report $report): array
    {
        return $this->asTenant(fn (): array => app(ReportDocumentBuilder::class)->build($report));
    }

    /**
     * @param  array<string, mixed>  $structure
     */
    private function html(array $structure): string
    {
        return View::make('reports.document', ['document' => $structure])->render();
    }

    /**
     * The visible text of a dompdf PDF.
     *
     * CPDF writes each glyph as two bytes, so the readable characters arrive
     * separated by NULs inside the string operands. Dropping them is enough to
     * search for a phrase, which is all any assertion here needs.
     */
    private function pdfText(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $streams);

        $text = '';

        foreach ($streams[1] as $stream) {
            $raw = @gzuncompress($stream);

            if ($raw === false) {
                $raw = @gzinflate($stream);
            }

            if ($raw === false) {
                continue;
            }

            preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/', $raw, $operands);

            foreach ($operands[1] as $operand) {
                // One newline per operand: without a boundary «Página 1 / 2»
                // runs straight into the «8,8%» drawn next in the stream and
                // the total reads 28.
                $text .= str_replace("\0", '', $operand)."\n";
            }
        }

        return $text;
    }

    /**
     * The image parts a .docx package carries.
     *
     * Empty means the file contains no image anywhere — the only statement
     * about «there is no logo» that does not depend on which XML dialect
     * PhpWord happened to emit.
     *
     * @return list<string>
     */
    private function mediaEntries(string $docx): array
    {
        $path = tempnam(sys_get_temp_dir(), 'lapis-test-docx-');
        file_put_contents($path, $docx);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'O .docx tem de ser um pacote ZIP válido.');

        $media = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_starts_with($name, 'word/media/')) {
                $media[] = $name;
            }
        }

        $zip->close();
        @unlink($path);

        return $media;
    }

    private function entry(string $docx, string $prefix): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'lapis-test-docx-');
        file_put_contents($path, $docx);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'O .docx tem de ser um pacote ZIP válido.');

        $found = null;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            if (str_starts_with((string) $zip->getNameIndex($index), $prefix)) {
                $found = (string) $zip->getFromIndex($index);

                break;
            }
        }

        $zip->close();
        @unlink($path);

        return $found;
    }
}
