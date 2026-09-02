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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * A DOCUMENT SIGNED BEFORE `show_logo` EXISTED GOES ON LOOKING THE SAME (§39, §50).
 *
 * The option is false by default, which is right for every report made from now
 * on and wrong for every report already signed: reading absence as «no» would
 * take the logo off documents a school has already sent to families. A signed
 * document is not rewritten — and it is not re-read differently either.
 *
 * THE COMPATIBILITY IS A READING RULE AND NOTHING ELSE. There is no migration,
 * no backfill, no option written into an old row. The answer is recomputed on
 * every read from bytes the document froze at signature — `identity.logo_path`
 * — and from nothing else. `ReportLogoOptionTest` covers the option itself;
 * this file covers only what happens where the option was never asked.
 *
 * THE LIVE IDENTITY IS NEVER CONSULTED for a finalized report. A school that
 * uploads a logo today cannot make a document signed last February start
 * carrying one, and one that removes it cannot take it away.
 */
class ReportLegacyLogoCompatibilityTest extends TestCase
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

        Storage::fake(SchoolLogoService::DISK);
    }

    // ------------------------------------------------ A · legacy com logótipo

    #[Test]
    public function a_document_signed_before_the_option_keeps_the_logo_it_printed(): void
    {
        $report = $this->legacyFinalized(withLogo: true);

        $this->assertTrue($report->showsLogo(), 'A ausência histórica da opção não pode ler-se como «não».');
        $this->assertNotNull($this->structure($report)['identity']['logo']);
    }

    #[Test]
    public function the_legacy_logo_travels_in_all_three_renderings(): void
    {
        $report = $this->legacyFinalized(withLogo: true);
        $structure = $this->structure($report);

        $this->assertStringContainsString('<img', $this->html($structure));
        $this->assertStringContainsString('/Image', $this->pdf($structure));
        $this->assertNotSame([], $this->mediaEntries($this->docx($structure)));
    }

    // ------------------------------------------------ B · legacy sem logótipo

    #[Test]
    public function a_document_signed_without_a_logo_does_not_start_carrying_one(): void
    {
        $report = $this->legacyFinalized(withLogo: false);

        $this->assertFalse($report->showsLogo());
        $this->assertNull($this->structure($report)['identity']['logo']);
    }

    #[Test]
    public function the_absent_legacy_logo_is_absent_from_all_three_renderings(): void
    {
        $report = $this->legacyFinalized(withLogo: false);
        $structure = $this->structure($report);

        $this->assertStringNotContainsString('<img', $this->html($structure));
        $this->assertStringNotContainsString('/Image', $this->pdf($structure));
        $this->assertSame([], $this->mediaEntries($this->docx($structure)));
    }

    // --------------------------------------------- C · escolha explícita: não

    #[Test]
    public function an_explicit_no_wins_over_a_frozen_logo(): void
    {
        // Signed WITH the logo, so the path is frozen — and then the option
        // says no. Somebody answered; the compatibility rule is for documents
        // where nobody was ever asked.
        $report = $this->finalizedWithFrozenLogo(['show_logo' => true]);
        $this->overwriteOptions($report, ['show_logo' => false]);

        $report = $this->reload($report);

        $this->assertTrue($report->frozeALogo(), 'A fixture tem de ter mesmo um logótipo congelado.');
        $this->assertFalse($report->showsLogo());
        $this->assertNull($this->structure($report)['identity']['logo']);
    }

    // --------------------------------------------- D · escolha explícita: sim

    #[Test]
    public function an_explicit_yes_carries_the_frozen_logo(): void
    {
        $report = $this->finalizedWithFrozenLogo(['show_logo' => true]);
        $structure = $this->structure($report);

        $this->assertTrue($report->showsLogo());
        $this->assertNotNull($structure['identity']['logo']);
        $this->assertStringContainsString('<img', $this->html($structure));
        $this->assertStringContainsString('/Image', $this->pdf($structure));
        $this->assertNotSame([], $this->mediaEntries($this->docx($structure)));
    }

    // --------------------------------------------------------- E · rascunhos

    #[Test]
    public function a_draft_never_reaches_the_legacy_rule(): void
    {
        $this->identity();
        $this->uploadLogo();

        // No option, a logo available, and nothing frozen: a draft has no
        // signed letterhead to be compatible with, so the default answers.
        $draft = $this->draft();

        $this->assertFalse($draft->showsLogo());
        $this->assertFalse($draft->frozeALogo());
        $this->assertNull($this->structure($draft)['identity']['logo']);
    }

    // ------------------------------------------- F · a escola muda depois

    #[Test]
    public function removing_the_schools_logo_afterwards_does_not_change_a_legacy_document(): void
    {
        $report = $this->legacyFinalized(withLogo: true);

        $before = $this->structure($report)['identity']['logo'];

        $this->asTenant(function (): void {
            OrganizationIdentity::query()
                ->where('organization_id', $this->organization->id)
                ->firstOrFail()
                ->forceFill(['logo_path' => null])
                ->save();
        });

        $report = $this->reload($report);

        $this->assertTrue($report->showsLogo(), 'A decisão vem do documento, não da escola de hoje.');
        $this->assertEquals($before, $this->structure($report)['identity']['logo']);
    }

    #[Test]
    public function uploading_a_logo_afterwards_does_not_give_one_to_a_legacy_document_that_had_none(): void
    {
        $report = $this->legacyFinalized(withLogo: false);

        $this->identity();
        $this->uploadLogo();

        $report = $this->reload($report);

        $this->assertFalse($report->showsLogo());
        $this->assertNull($this->structure($report)['identity']['logo']);
    }

    // ------------------------------------------------------ imutabilidade

    #[Test]
    public function reading_a_legacy_document_writes_absolutely_nothing(): void
    {
        $report = $this->legacyFinalized(withLogo: true);

        $before = $this->rawRow($report);

        $writes = [];

        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(update|insert|delete)\s/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        // Every rendering, twice, so a lazily-written «fix» would have to show.
        foreach ([1, 2] as $ignored) {
            $structure = $this->structure($report);
            $this->html($structure);
            $this->pdf($structure);
            $this->docx($structure);
            $this->reload($report)->showsLogo();
        }

        $this->assertSame([], $writes, 'A compatibilidade é só de leitura: não pode escrever nada.');

        $after = $this->rawRow($report);

        $this->assertSame($before->document, $after->document, 'O documento assinado não pode mudar.');
        $this->assertSame($before->document_hash, $after->document_hash, 'O hash não pode mudar.');
        $this->assertSame($before->options, $after->options, 'Nenhuma opção pode ser preenchida à posteriori.');
    }

    #[Test]
    public function the_legacy_row_still_has_no_show_logo_key_after_being_read(): void
    {
        $report = $this->legacyFinalized(withLogo: true);

        $this->structure($report);

        $options = json_decode((string) $this->rawRow($report)->options, true);

        $this->assertIsArray($options);
        $this->assertArrayNotHasKey('show_logo', $options, 'Nada foi escrito na linha antiga.');
    }

    // ------------------------------------------------------------- fixtures

    /**
     * A report as it would exist if it had been signed before `show_logo` was
     * introduced: finalized, and with no such key in `options` at all.
     *
     * Built by finalizing normally and then removing the key through the query
     * builder — the model's own guard refuses to write to a finalized report,
     * which is exactly the invariant this file is here to protect, so the
     * fixture goes around Eloquent rather than weakening it.
     */
    private function legacyFinalized(bool $withLogo): Report
    {
        $report = $withLogo
            ? $this->finalizedWithFrozenLogo(['show_logo' => true])
            : $this->finalizedWithFrozenLogo([]);

        $this->overwriteOptions($report, []);

        $report = $this->reload($report);

        $this->assertSame(
            $withLogo,
            $report->frozeALogo(),
            'A fixture legacy tem de congelar (ou não) um logótipo como o teste pede.',
        );

        return $report;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function finalizedWithFrozenLogo(array $options): Report
    {
        $this->identity();
        $this->uploadLogo();

        return $this->asTenant(
            fn (): Report => app(FinalizeReport::class)->finalize($this->draft($options), $this->teacher),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function overwriteOptions(Report $report, array $options): void
    {
        DB::table('reports')->where('id', $report->getKey())->update([
            'options' => json_encode($options),
        ]);
    }

    private function rawRow(Report $report): object
    {
        $row = DB::table('reports')->where('id', $report->getKey())->first();

        $this->assertNotNull($row);

        return $row;
    }

    // --------------------------------------------------------------- helpers

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
            $existing = OrganizationIdentity::query()
                ->where('organization_id', $this->organization->id)
                ->first();

            if ($existing !== null) {
                return;
            }

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
