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
 * WHETHER A DOCUMENT CARRIES THE SCHOOL'S LOGO — decided, revisable, frozen
 * (§50, §39).
 *
 * THE DECISION IS FALSE BY OMISSION, exactly like `name_students`. A school
 * uploads a logo so its own screens can use it; that is not a decision that
 * every report leaving the building is a branded institutional document.
 *
 * IT IS REVISABLE WHILE THE REPORT IS A DRAFT and fixed the moment it is
 * signed. There is no second rule enforcing the second half: `options` is not
 * in `EDITABLE_AFTER_FINALIZING` and ReportPolicy::update already refuses a
 * finalized report, so finalizing closes it by construction.
 *
 * WHAT IS DELIBERATELY NOT HERE is any statement about reports finalized before
 * the option existed. Changing how an already-signed document looks is out of
 * scope by product decision; what this guards is that everything from now on
 * comes out right.
 */
class ReportLogoOptionTest extends TestCase
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

        // Faked last, after every seeder that also writes to `local`.
        Storage::fake(SchoolLogoService::DISK);
    }

    // ------------------------------------------------------------ o defeito

    #[Test]
    public function a_new_report_is_born_without_a_logo(): void
    {
        $this->identity();
        $this->uploadLogo();

        $report = $this->draft();

        $this->assertFalse($report->showsLogo());
        $this->assertNull($this->structure($report)['identity']['logo']);
    }

    // ------------------------------------------------- ligar e voltar a desligar

    #[Test]
    public function a_draft_can_turn_the_logo_on(): void
    {
        $this->identity();
        $this->uploadLogo();

        $report = $this->draft();

        $this->actingAs($this->teacher)
            ->put("/reports/{$report->ulid}", ['show_logo' => true])
            ->assertRedirect();

        $this->assertTrue($this->reload($report)->showsLogo());
    }

    #[Test]
    public function a_draft_can_turn_it_off_again(): void
    {
        $this->identity();
        $this->uploadLogo();

        $report = $this->draft(['show_logo' => true]);

        $this->actingAs($this->teacher)
            ->put("/reports/{$report->ulid}", ['show_logo' => false])
            ->assertRedirect();

        $this->assertFalse($this->reload($report)->showsLogo());
    }

    #[Test]
    public function saving_the_logo_choice_does_not_wipe_the_other_options(): void
    {
        $this->identity();
        $this->uploadLogo();

        $report = $this->draft(['name_students' => true]);

        $this->actingAs($this->teacher)
            ->put("/reports/{$report->ulid}", ['show_logo' => true])
            ->assertRedirect();

        $again = $this->reload($report);

        $this->assertTrue($again->showsLogo());
        $this->assertTrue($again->namesStudents());
    }

    #[Test]
    public function both_options_sent_together_both_survive(): void
    {
        $this->identity();
        $this->uploadLogo();

        $report = $this->draft();

        $this->actingAs($this->teacher)
            ->put("/reports/{$report->ulid}", ['show_logo' => true, 'name_students' => true])
            ->assertRedirect();

        $again = $this->reload($report);

        $this->assertTrue($again->showsLogo());
        $this->assertTrue($again->namesStudents());
    }

    // ------------------------------------------------------------- o ecrã

    #[Test]
    public function the_screen_is_told_the_choice_and_whether_there_is_one_to_make(): void
    {
        $this->identity();

        $report = $this->draft();

        // No logo configured: the page says so rather than offering a checkbox
        // that could not do anything.
        $this->actingAs($this->teacher)
            ->get("/reports/{$report->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('logo.shown', false)
                ->where('logo.available', false));

        $this->uploadLogo();

        $this->actingAs($this->teacher)
            ->get("/reports/{$report->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('logo.shown', false)
                ->where('logo.available', true));
    }

    // ------------------------------------------------ sem logótipo configurado

    #[Test]
    public function without_a_configured_logo_nothing_reserves_space_for_one(): void
    {
        $this->identity();

        // The option on, and no file behind it: the document must still not
        // leave a gap where an image would have been.
        $structure = $this->structure($this->draft(['show_logo' => true]));

        $this->assertNull($structure['identity']['logo']);

        $html = $this->html($structure);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('data:image/', $html);
        $this->assertSame([], $this->mediaEntries($this->docx($structure)));
    }

    // ---------------------------------------- com logótipo, nos três formatos

    #[Test]
    public function with_the_choice_on_all_three_renderings_carry_it(): void
    {
        $this->identity();
        $this->uploadLogo();

        $structure = $this->structure($this->draft(['show_logo' => true]));

        $this->assertNotNull($structure['identity']['logo']);

        // HTML.
        $this->assertStringContainsString('data:image/png;base64,', $this->html($structure));

        // Word. Asserted on the package: PhpWord writes VML («<w:pict>»), never
        // DrawingML, so looking for «<w:drawing>» would pass either way.
        $this->assertNotEmpty($this->mediaEntries($this->docx($structure)));

        // PDF — it renders, and the image travelled as bytes rather than as a
        // URL the renderer would have had to follow.
        $pdf = $this->asTenant(fn (): string => app(PdfRenderer::class)->render($structure));

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('/Image', $pdf);
    }

    // ------------------------------------------------------- a finalização

    #[Test]
    public function finalizing_freezes_the_choice(): void
    {
        $this->identity();
        $this->uploadLogo();

        $report = $this->asTenant(
            fn () => app(FinalizeReport::class)->finalize($this->draft(['show_logo' => true]), $this->teacher),
        );

        $this->assertTrue($report->showsLogo());
        $this->assertIsString(data_get($report->document, 'identity.logo_path'));
        $this->assertNotNull($this->structure($report)['identity']['logo']);
    }

    #[Test]
    public function finalizing_without_the_choice_freezes_no_logo_at_all(): void
    {
        $this->identity();
        $this->uploadLogo();

        $report = $this->asTenant(fn () => app(FinalizeReport::class)->finalize($this->draft(), $this->teacher));

        $this->assertNull(data_get($report->document, 'identity.logo_path'));

        // And nothing was duplicated for a document that will never print it.
        $this->assertSame([], Storage::disk(SchoolLogoService::DISK)->files(FinalizeReport::LOGO_DIRECTORY));
    }

    #[Test]
    public function a_finalized_report_cannot_change_its_mind(): void
    {
        $this->identity();
        $this->uploadLogo();

        $report = $this->asTenant(fn () => app(FinalizeReport::class)->finalize($this->draft(), $this->teacher));

        $this->actingAs($this->teacher)
            ->put("/reports/{$report->ulid}", ['show_logo' => true])
            ->assertForbidden();

        $this->assertFalse($this->reload($report)->showsLogo());
    }

    #[Test]
    public function changing_the_school_afterwards_does_not_change_a_signed_document(): void
    {
        $this->identity();
        $this->uploadLogo();

        $report = $this->asTenant(
            fn () => app(FinalizeReport::class)->finalize($this->draft(['show_logo' => true]), $this->teacher),
        );

        $frozen = $this->structure($report)['identity'];

        // The school renames itself, moves, and replaces its logo file.
        $this->asTenant(function (): void {
            OrganizationIdentity::query()
                ->where('organization_id', $this->organization->id)
                ->firstOrFail()
                ->forceFill([
                    'official_name' => 'Outro Agrupamento',
                    'locality' => 'Porto',
                ])
                ->save();
        });

        $this->uploadLogo();

        $again = $this->structure($this->reload($report))['identity'];

        $this->assertSame($frozen['name'], $again['name']);
        $this->assertSame($frozen['header_lines'], $again['header_lines']);
        // The bytes too: the copy in `report-logos/` belongs to this document.
        $this->assertSame($frozen['logo']['data'], $again['logo']['data']);
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

    private function identity(): void
    {
        $this->asTenant(function (): void {
            $identity = new OrganizationIdentity;
            $identity->forceFill([
                'organization_id' => $this->organization->id,
                'official_name' => 'Agrupamento de Escolas de Exemplo',
                'address' => 'Rua das Escolas, 12,',
                'postal_code' => '1000-001',
                'locality' => 'Lisboa',
            ])->save();
        });
    }

    private function uploadLogo(): void
    {
        $path = 'school-logos/'.Str::uuid()->toString().'.png';

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

    private function reload(Report $report): Report
    {
        return $this->asTenant(fn (): Report => Report::where('ulid', $report->ulid)->firstOrFail());
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
    private function docx(array $structure): string
    {
        return $this->asTenant(fn (): string => app(DocxRenderer::class)->render($structure));
    }

    /**
     * The image parts a .docx package carries. Empty means no image anywhere.
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
}
