<?php

namespace Tests\Feature\Reports;

use App\Models\AcademicPeriod;
use App\Models\Intervention;
use App\Models\InterventionDescriptionSource;
use App\Models\InterventionDomainRelation;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\InterventionType;
use App\Models\Organization;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * WHAT THE READER ACTUALLY RECEIVES.
 *
 * Every other file in this module reads `section->body`, which is the text the
 * composers wrote. It is not the document: the builder adds a title, a subtitle,
 * a line of metadata and a closing sentence after that, and each renderer adds a
 * letterhead, a footer and a signature block of its own. Words that live only in
 * those layers were never being looked at by anything — which is exactly how
 * «O(A) professor(a)» survived every pass over the prose (§8).
 *
 * SO THIS FILE READS THE FINISHED ARTEFACTS. The composed HTML, the .docx, and
 * the PDF where the text can be extracted reliably.
 *
 * THE SENTINEL IS A NET, NOT A SPECIFICATION. A list of forbidden strings
 * catches a leak nobody predicted; it cannot say what the document should read
 * like, and it must never be broad enough to block legitimate Portuguese. Every
 * behaviour it guards also has a test of its own that states the rule
 * positively.
 */
class ReportDocumentProseTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    /**
     * Words this application generated for itself, that no reader should ever
     * meet in a document.
     *
     * DELIBERATELY NARROW. Each entry is a string this codebase is known to
     * have produced, not a word that merely looks technical: «legado» on its own
     * would be a fair thing for a teacher to write, and «nova» is an ordinary
     * adjective. What is listed is the exact placeholder, the exact caption, the
     * exact broken pagination.
     *
     * @var list<string>
     */
    private const FORBIDDEN = [
        // The import placeholder, in both spellings it exists in.
        'Legado sem dominio',
        'Legado sem domínio',
        // The parenthetical gender that made the closing read like a form.
        'O(A) professor(a)',
        'O(a) professor(a)',
        // Pagination that failed to count.
        'Página /',
        '/ 0',
        // The internal vocabulary of §7: what the system does, not what the
        // teacher did.
        'decisão do professor',
        'proposta do sistema',
        'decisão do sistema',
        // Placeholders and raw values that only ever appear by accident.
        'uncategorised',
        'null',
        'N/A',
        'fallback',
    ];

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
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
                'ends_at' => null,
            ],
        );

        app(Entitlements::class)->flush();
    }

    // ------------------------------------------------- §5 encerramento neutro

    #[Test]
    public function the_signature_caption_names_a_role_and_not_a_gender(): void
    {
        $structure = $this->structure($this->draft());

        $this->assertStringContainsString('Docente responsável', $this->html($structure));
        $this->assertStringNotContainsString('O(A) professor(a)', $this->html($structure));
    }

    #[Test]
    public function the_neutral_caption_reaches_the_docx(): void
    {
        $text = $this->docxText($this->docx($this->structure($this->draft())));

        $this->assertStringContainsString('Docente responsável', $text);
        $this->assertStringNotContainsString('O(A) professor(a)', $text);
    }

    #[Test]
    public function the_neutral_caption_reaches_the_pdf(): void
    {
        $text = $this->pdfText($this->pdf($this->structure($this->draft())));

        // Not /u and not one string: CPDF draws each run separately, so the
        // caption is asserted by the word that identifies it.
        $this->assertStringContainsString('Docente', $text);
        $this->assertStringNotContainsString('professor(a)', $text);
    }

    #[Test]
    public function a_finalized_report_states_who_signed_it_and_when(): void
    {
        $report = $this->asTenant(
            fn (): Report => app(FinalizeReport::class)->finalize($this->draft(), $this->teacher),
        );

        $html = $this->html($this->structure($report));

        $this->assertStringContainsString('Relatório finalizado', $html);
        $this->assertStringContainsString('30/06/2027', $html);
        // The factual sentence and the signature caption are different things
        // and stay apart.
        $this->assertStringContainsString('Docente responsável', $html);
    }

    #[Test]
    public function a_report_with_no_resolved_author_invents_none(): void
    {
        $report = $this->draft();

        // What an imported report looks like: the author could not be resolved
        // into a user of this installation (§8 da reconciliação documental).
        $this->asTenant(fn () => DB::table('reports')
            ->where('id', $report->getKey())
            ->update(['created_by' => null]));

        $html = $this->html($this->structure($this->reload($report)));

        $this->assertStringNotContainsString('por  ', $html);
        $this->assertStringContainsString('Docente responsável', $html);
    }

    // -------------------------------------------------------- §8 a sentinela

    #[Test]
    public function no_internal_word_reaches_the_composed_html(): void
    {
        $html = $this->html($this->structure($this->reportWithEverything()));

        foreach (self::FORBIDDEN as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $html,
                "«{$forbidden}» chegou ao HTML final do documento.",
            );
        }
    }

    #[Test]
    public function no_internal_word_reaches_the_docx(): void
    {
        $text = $this->docxText($this->docx($this->structure($this->reportWithEverything())));

        foreach (self::FORBIDDEN as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $text,
                "«{$forbidden}» chegou ao .docx.",
            );
        }
    }

    #[Test]
    public function the_pdf_pagination_is_never_empty_or_zero(): void
    {
        $text = $this->pdfText($this->pdf($this->structure($this->reportWithEverything())));

        preg_match_all('/P.gina (\d+)(?: \/ (\d+))?\n/', $text, $labels, PREG_SET_ORDER);

        $this->assertNotEmpty($labels, 'Cada página tem de levar o seu número.');

        foreach ($labels as $index => $label) {
            $this->assertSame((string) ($index + 1), $label[1]);
            $this->assertSame((string) count($labels), $label[2] ?? null);
        }

        $this->assertStringNotContainsString('/ 0', $text);
    }

    // ------------------------------------------------------------ §10 listas

    #[Test]
    public function highlighted_measures_are_a_real_list_in_the_html(): void
    {
        $html = $this->html($this->structure($this->withHighlighted(3)));

        $this->assertStringContainsString('<ul', $html);
        $this->assertSame(3, substr_count($html, '<li>'));
        // The lead-in stays a sentence and does not swallow the items.
        $this->assertStringContainsString('Destacam-se as seguintes medidas:', $html);
        // And the plain-text dash never reaches the document.
        $this->assertStringNotContainsString('<p>— ', $html);
    }

    #[Test]
    public function highlighted_measures_are_a_real_list_in_the_docx(): void
    {
        $docx = $this->docx($this->structure($this->withHighlighted(3)));

        // `numPr` is the numbering/bullet property Word writes for a list item;
        // a paragraph with a dash typed into it has none.
        $this->assertStringContainsString('numPr', $this->docxXml($docx));
    }

    #[Test]
    public function a_single_measure_is_still_a_list_and_not_a_sentence_with_a_dash(): void
    {
        $html = $this->html($this->structure($this->withHighlighted(1)));

        $this->assertSame(1, substr_count($html, '<li>'));
        // The marker never survives into an item or a paragraph. Asserted on
        // those two shapes rather than on the character: the draft stamp
        // («RASCUNHO — documento de trabalho») uses a dash legitimately.
        $this->assertStringNotContainsString('<li>— ', $html);
        $this->assertStringNotContainsString('<p>— ', $html);
    }

    #[Test]
    public function two_measures_produce_two_items(): void
    {
        $html = $this->html($this->structure($this->withHighlighted(2)));

        $this->assertSame(2, substr_count($html, '<li>'));
    }

    #[Test]
    public function no_measures_produce_no_list_at_all(): void
    {
        $html = $this->html($this->structure($this->draft()));

        $this->assertStringNotContainsString('Destacam-se as seguintes medidas', $html);
        $this->assertStringNotContainsString('<ul', $html);
    }

    // ------------------------------------------------------------- fixtures

    /**
     * A report with `$count` interventions the teacher asked to show.
     */
    private function withHighlighted(int $count): Report
    {
        $this->asTenant(function () use ($count): void {
            $class = $this->schoolClass();
            $period = $this->period();

            $titles = [
                'Apoio à planificação textual',
                'Reforço da leitura em voz alta',
                'Tutoria entre pares na gramática',
            ];

            for ($index = 0; $index < $count; $index++) {
                Intervention::create([
                    'class_id' => $class->id,
                    'enrollment_id' => null,
                    'academic_period_id' => $period->id,
                    'target_type' => InterventionTargetType::SchoolClass,
                    'intervention_type' => InterventionType::LearningReinforcement,
                    'domain_relation' => InterventionDomainRelation::None,
                    'title' => $titles[$index],
                    'description_source' => InterventionDescriptionSource::Manual,
                    'status' => InterventionStatus::InProgress,
                    'started_on' => $period->starts_on,
                    'include_in_report' => true,
                    'available_for_reports' => true,
                    'created_by' => $this->teacher->id,
                ]);
            }
        });

        return $this->draft();
    }

    /**
     * A report whose sections have as much to say as this fixture allows —
     * including a legacy intervention that has since been typed, which is the
     * row the sentinel most needs to be pointed at.
     */
    private function reportWithEverything(): Report
    {
        $this->asTenant(function (): void {
            $class = $this->schoolClass();
            $period = $this->period();

            $legacy = Intervention::create([
                'class_id' => $class->id,
                'enrollment_id' => null,
                'academic_period_id' => $period->id,
                'target_type' => InterventionTargetType::SchoolClass,
                'intervention_type' => null,
                'domain_relation' => InterventionDomainRelation::None,
                'title' => 'Legado sem dominio',
                'description_source' => InterventionDescriptionSource::Manual,
                'status' => InterventionStatus::InProgress,
                'started_on' => $period->starts_on,
                'include_in_report' => false,
                'available_for_reports' => true,
                'created_by' => $this->teacher->id,
            ]);

            $legacy->forceFill([
                'intervention_type' => InterventionType::LearningReinforcement,
                'include_in_report' => true,
            ])->save();
        });

        return $this->draft();
    }

    private function draft(): Report
    {
        return $this->asTenant(fn (): Report => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $this->period(),
        ));
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function period(int $sequence = 1): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->where('sequence', $sequence)->firstOrFail());
    }

    private function reload(Report $report): Report
    {
        return $this->asTenant(fn (): Report => Report::where('ulid', $report->ulid)->firstOrFail());
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
     * @param  array<string, mixed>  $structure
     */
    private function pdf(array $structure): string
    {
        return $this->asTenant(fn (): string => app(PdfRenderer::class)->render($structure));
    }

    /**
     * @param  array<string, mixed>  $structure
     */
    private function docx(array $structure): string
    {
        return $this->asTenant(fn (): string => app(DocxRenderer::class)->render($structure));
    }

    /** The raw XML of a .docx package, for asserting on structure. */
    private function docxXml(string $docx): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lapis-prose-xml-');
        file_put_contents($path, $docx);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $xml = '';

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_starts_with($name, 'word/') && str_ends_with($name, '.xml')) {
                $xml .= (string) $zip->getFromIndex($index);
            }
        }

        $zip->close();
        @unlink($path);

        return $xml;
    }

    /** Every word a .docx package carries, headers and footers included. */
    private function docxText(string $docx): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lapis-prose-docx-');
        file_put_contents($path, $docx);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $text = '';

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_starts_with($name, 'word/') && str_ends_with($name, '.xml')) {
                // Tags stripped with a space between them, so two adjacent runs
                // do not weld into a word that is in neither.
                $text .= ' '.strip_tags(str_replace('><', '> <', (string) $zip->getFromIndex($index)));
            }
        }

        $zip->close();
        @unlink($path);

        return $text;
    }

    /**
     * The drawn text of a PDF, one operand per line.
     *
     * Not /u anywhere downstream: CPDF writes accented glyphs in its own
     * single-byte encoding, so the extracted string is not valid UTF-8 and preg
     * would refuse the subject and quietly match nothing at all.
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
                $text .= str_replace("\0", '', $operand)."\n";
            }
        }

        return $text;
    }
}
