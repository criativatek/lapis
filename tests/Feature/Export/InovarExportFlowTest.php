<?php

namespace Tests\Feature\Export;

use App\Models\AuditEvent;
use App\Models\Domain;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InovarGridFixture;
use Tests\TestCase;

/**
 * Upload, preview, confirm, download — and the grid that comes back.
 *
 * The file a school uploads to INOVAR is the file INOVAR produced, with some
 * empty cells now carrying F, I, S, B or MB. These hold both halves of that:
 * the mentions really are written, and nothing else in the workbook moved.
 */
class InovarExportFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->subscribeToPro();
    }

    private function subscribeToPro(): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $this->teacher->personalOrganization()->getKey())->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $this->teacher->personalOrganization()->getKey(),
            'plan_id' => Plan::where('key', 'pro')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now()->subDay(),
        ]);

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
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function periodUlid(): string
    {
        return $this->asTenant(fn (): string => $this->schoolClass()
            ->academicYear->periods()->where('sequence', 1)->firstOrFail()->ulid);
    }

    /**
     * Gives the class invented process numbers and builds a grid naming those
     * students and this profile's own domains.
     *
     * @return array{path: string, numbers: array<string, string>, domains: array<string, string>}
     */
    private function grid(): array
    {
        $numbers = $this->asTenant(function (): array {
            $assigned = [];
            $next = 1234;

            foreach ($this->schoolClass()->enrollments()->with('student.identity')->orderBy('class_number')->get() as $enrollment) {
                $number = str_pad((string) $next++, 6, '0', STR_PAD_LEFT);
                $enrollment->student->identity->update(['school_number' => $number]);
                $assigned[$enrollment->student->identity->display_name] = $number;
            }

            return $assigned;
        });

        $domains = $this->asTenant(function (): array {
            $version = $this->schoolClass()->profileVersion;
            $names = Domain::whereIn('id', $version->domains()->pluck('domain_id'))->orderBy('name')->pluck('name')->all();
            $columns = [];

            foreach (array_slice($names, 0, 3) as $index => $name) {
                $columns[chr(ord('D') + $index)] = $name;
            }

            return $columns;
        });

        return [
            'path' => (new InovarGridFixture)->build(['domains' => $domains, 'students' => array_flip($numbers)]),
            'numbers' => $numbers,
            'domains' => $domains,
        ];
    }

    private function upload(string $path): TestResponse
    {
        return $this->actingAs($this->teacher)->post(
            "/classes/{$this->schoolClass()->ulid}/exports/inovar/{$this->periodUlid()}",
            ['template' => UploadedFile::fake()->createWithContent('grelha.xls', (string) file_get_contents($path))],
        );
    }

    // ---------------------------------------------- 1. o percurso completo

    #[Test]
    public function a_teacher_uploads_a_grid_confirms_and_downloads_it_filled_in(): void
    {
        $grid = $this->grid();

        $preview = $this->upload($grid['path']);
        $preview->assertOk()->assertInertia(fn ($page) => $page->component('exports/Inovar'));

        /** @var array<string, mixed> $page */
        $page = $preview->viewData('page');
        $token = $page['props']['token'];

        $this->assertNotNull($token);
        $this->assertSame([], $page['props']['preview']['summary']['blocking_errors']);

        $download = $this->actingAs($this->teacher)->post(
            "/classes/{$this->schoolClass()->ulid}/exports/inovar/{$this->periodUlid()}/{$token}",
        );

        $download->assertOk();
        $this->assertStringContainsString('INOVAR_', $download->headers->get('content-disposition') ?? '');

        // Re-open what came back and read the cells.
        $path = tempnam(sys_get_temp_dir(), 'inovar_gerado_').'.xls';
        file_put_contents($path, $download->streamedContent());

        $sheet = @IOFactory::load($path)->getActiveSheet();

        $written = 0;

        foreach (array_keys($grid['domains']) as $column) {
            for ($row = 4; $row <= 9; $row++) {
                $value = $sheet->getCell($column.$row)->getValue();

                if ($value !== null && $value !== '') {
                    $this->assertContains($value, ['F', 'I', 'S', 'B', 'MB']);
                    $written++;
                }
            }
        }

        $this->assertGreaterThan(0, $written);
        @unlink($path);
    }

    #[Test]
    public function the_codes_written_are_the_ones_the_preview_promised(): void
    {
        $grid = $this->grid();
        $page = $this->upload($grid['path'])->viewData('page');
        $token = $page['props']['token'];

        $expected = [];

        foreach ($page['props']['preview']['values'] as $value) {
            if ($value['writable']) {
                $expected[$value['column'].$value['row']] = $value['inovar_code'];
            }
        }

        $this->assertNotEmpty($expected);

        $download = $this->actingAs($this->teacher)
            ->post("/classes/{$this->schoolClass()->ulid}/exports/inovar/{$this->periodUlid()}/{$token}");

        $path = tempnam(sys_get_temp_dir(), 'inovar_gerado_').'.xls';
        file_put_contents($path, $download->streamedContent());
        $sheet = @IOFactory::load($path)->getActiveSheet();

        foreach ($expected as $coordinate => $code) {
            $this->assertSame($code, $sheet->getCell($coordinate)->getValue(), "célula {$coordinate}");
        }

        @unlink($path);
    }

    // ------------------------------------------------------ 2. fidelidade

    #[Test]
    public function nothing_outside_the_mapped_cells_moves(): void
    {
        $grid = $this->grid();
        $page = $this->upload($grid['path'])->viewData('page');
        $token = $page['props']['token'];

        $targets = [];

        foreach ($page['props']['preview']['values'] as $value) {
            $targets[$value['column'].$value['row']] = true;
        }

        $before = @IOFactory::load($grid['path'])->getActiveSheet();

        $download = $this->actingAs($this->teacher)
            ->post("/classes/{$this->schoolClass()->ulid}/exports/inovar/{$this->periodUlid()}/{$token}");

        $path = tempnam(sys_get_temp_dir(), 'inovar_gerado_').'.xls';
        file_put_contents($path, $download->streamedContent());
        $after = @IOFactory::load($path)->getActiveSheet();

        // The sheet itself.
        $this->assertSame($before->getTitle(), $after->getTitle());
        $this->assertSame($before->calculateWorksheetDimension(), $after->calculateWorksheetDimension());
        $this->assertSame(array_values($before->getMergeCells()), array_values($after->getMergeCells()));

        // Every cell that was not a target: the same value, and the same style.
        $lastColumn = Coordinate::columnIndexFromString($before->getHighestDataColumn());

        for ($row = 1; $row <= $before->getHighestDataRow(); $row++) {
            for ($index = 1; $index <= $lastColumn; $index++) {
                $letter = Coordinate::stringFromColumnIndex($index);
                $coordinate = $letter.$row;

                if (isset($targets[$coordinate])) {
                    continue;
                }

                $this->assertSame(
                    $before->getCell($coordinate)->getValue(),
                    $after->getCell($coordinate)->getValue(),
                    "valor alterado em {$coordinate}",
                );
                $this->assertSame(
                    $before->getCell($coordinate)->getStyle()->getFont()->getBold(),
                    $after->getCell($coordinate)->getStyle()->getFont()->getBold(),
                    "estilo alterado em {$coordinate}",
                );
            }
        }

        // The width the template actually set is the width that came back. The
        // audited difference — every other column gaining an explicit default —
        // changes no width, and is the documented cost of this writer.
        $this->assertEqualsWithDelta(
            $before->getColumnDimension('C')->getWidth(),
            $after->getColumnDimension('C')->getWidth(),
            0.001,
        );

        @unlink($path);
    }

    #[Test]
    public function the_unnamed_column_beside_the_domains_is_never_written(): void
    {
        $grid = $this->grid();
        $page = $this->upload($grid['path'])->viewData('page');

        $download = $this->actingAs($this->teacher)
            ->post("/classes/{$this->schoolClass()->ulid}/exports/inovar/{$this->periodUlid()}/{$page['props']['token']}");

        $path = tempnam(sys_get_temp_dir(), 'inovar_gerado_').'.xls';
        file_put_contents($path, $download->streamedContent());
        $sheet = @IOFactory::load($path)->getActiveSheet();

        // Column I sits inside the banner's span with no name. What it means was
        // never confirmed, so it stays exactly as it arrived (§11).
        for ($row = 4; $row <= 9; $row++) {
            $this->assertNull($sheet->getCell('I'.$row)->getValue());
        }

        @unlink($path);
    }

    // ------------------------------------------------- 3. o que é recusado

    #[Test]
    public function a_grid_that_is_not_recognised_never_reaches_a_preview(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lixo_').'.xls';
        file_put_contents($path, 'nem sequer é uma folha de cálculo');

        $this->upload($path)->assertRedirect()->assertSessionHasErrors('template');

        @unlink($path);
    }

    #[Test]
    public function generation_is_refused_while_anything_blocks_it(): void
    {
        // No process numbers anywhere: the class was typed in by hand.
        $path = (new InovarGridFixture)->build();

        $page = $this->upload($path)->viewData('page');
        $token = $page['props']['token'];

        $this->assertNotEmpty($page['props']['preview']['summary']['blocking_errors']);

        $this->actingAs($this->teacher)
            ->post("/classes/{$this->schoolClass()->ulid}/exports/inovar/{$this->periodUlid()}/{$token}")
            ->assertRedirect()
            ->assertSessionHasErrors('template');
    }

    #[Test]
    public function a_token_that_no_longer_exists_is_refused(): void
    {
        $this->actingAs($this->teacher)
            ->post("/classes/{$this->schoolClass()->ulid}/exports/inovar/{$this->periodUlid()}/nao-existe")
            ->assertRedirect()
            ->assertSessionHasErrors('template');
    }

    // ------------------------------------------------- 4. quem pode exportar

    #[Test]
    public function the_base_plan_cannot_reach_the_export_at_all(): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $this->teacher->personalOrganization()->getKey())->delete();
        app(Entitlements::class)->flush();

        $this->actingAs($this->teacher)
            ->get("/classes/{$this->schoolClass()->ulid}/exports/inovar/{$this->periodUlid()}")
            ->assertForbidden();
    }

    #[Test]
    public function the_institutional_plan_can(): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $this->teacher->personalOrganization()->getKey())->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $this->teacher->personalOrganization()->getKey(),
            'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now()->subDay(),
        ]);
        app(Entitlements::class)->flush();

        $this->actingAs($this->teacher)
            ->get("/classes/{$this->schoolClass()->ulid}/exports/inovar/{$this->periodUlid()}")
            ->assertOk();
    }

    #[Test]
    public function a_teacher_from_another_organization_cannot_export_this_class(): void
    {
        $classUlid = $this->schoolClass()->ulid;
        $periodUlid = $this->periodUlid();

        $this->actingAs(User::factory()->create())
            ->get("/classes/{$classUlid}/exports/inovar/{$periodUlid}")
            ->assertNotFound();
    }

    // ------------------------------------------------------ 5. o registo

    #[Test]
    public function the_export_leaves_a_trail_and_no_personal_data_in_it(): void
    {
        $grid = $this->grid();
        $page = $this->upload($grid['path'])->viewData('page');

        $this->actingAs($this->teacher)
            ->post("/classes/{$this->schoolClass()->ulid}/exports/inovar/{$this->periodUlid()}/{$page['props']['token']}")
            ->assertOk();

        $this->asTenant(function (): void {
            $event = AuditEvent::where('event', 'inovar.exported')->firstOrFail();

            // Counts, never contents: no name, no process number, no mark, and
            // never the file itself.
            $this->assertArrayHasKey('students', $event->properties);
            $this->assertArrayHasKey('cells', $event->properties);
            $this->assertGreaterThan(0, $event->properties['cells']);

            foreach (['values', 'students_names', 'file', 'template'] as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, $event->properties);
            }
        });
    }
}
