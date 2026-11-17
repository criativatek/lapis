<?php

namespace Tests\Feature\Reporting;

use App\Models\AcademicPeriod;
use App\Models\AuditEvent;
use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\ProfileVersionPeriod;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use ZipArchive;

/**
 * O «Pacote de reunião»: um ZIP de folhas de Excel com a Pauta, o Quadro Síntese e a grelha de cada
 * elemento de avaliação elegível do ano letivo até ao período pedido.
 *
 * O QUE ESTES TESTES DEFENDEM: que cada folha é a que o endpoint próprio dá (fonte única, célula a
 * célula); que os elementos que entram são os que o cálculo conta, por ordem cronológica e com nomes
 * únicos; que os que ficam de fora são contados por razão e os provisórios assinalados no LEIA-ME; que
 * a falta do módulo dos elementos tira só esses ficheiros, sem erro; que as portas são as da Pauta; que
 * fica UM registo de auditoria, sem nomes, e os endpoints antigos continuam a deixar o seu; e que nada
 * fica no servidor depois de enviado.
 */
class MeetingPackageTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->organization = $this->teacher->personalOrganization();
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));
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

    private function subscribe(string $plan): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            ['plan_id' => Plan::where('key', $plan)->firstOrFail()->id, 'status' => SubscriptionStatus::Active, 'starts_at' => Carbon::parse('2026-01-01 00:00:00'), 'ends_at' => null],
        );
        app(Entitlements::class)->flush();
    }

    private function classUlid(): string
    {
        return $this->asTenant(fn (): string => SchoolClass::where('label', '7.º A')->firstOrFail()->ulid);
    }

    private function periodUlid(): string
    {
        return $this->asTenant(fn (): string => SchoolClass::where('label', '7.º A')->firstOrFail()->academicYear->periods()->where('sequence', 1)->firstOrFail()->ulid);
    }

    private function url(string $query = '', ?string $period = null): string
    {
        return "/classes/{$this->classUlid()}/pauta-avaliacao/pacote-reuniao/".($period ?? $this->periodUlid()).$query;
    }

    /** @return list<string> the display names of the class's students */
    private function studentNames(): array
    {
        return $this->asTenant(fn (): array => SchoolClass::where('label', '7.º A')->firstOrFail()
            ->enrollments()->with('student.identity')->get()
            ->map(fn ($enrollment): string => $enrollment->student->identity->display_name)
            ->values()->all());
    }

    /** The demo's one element of the 1.º Semestre (in correction). */
    private function demoInstrument(): Instrument
    {
        return $this->asTenant(function (): Instrument {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            return Instrument::where('class_id', $class->id)->where('academic_period_id', $period->id)->firstOrFail();
        });
    }

    /**
     * A copy of the demo element, changed — the cheapest way to have an element in every state the
     * package has to tell apart, with the same period and class.
     *
     * @param  array<string, mixed>  $changes
     */
    private function copyOfDemoInstrument(array $changes): Instrument
    {
        return $this->asTenant(function () use ($changes): Instrument {
            $copy = $this->demoInstrument()->replicate(['ulid']);
            $copy->forceFill($changes)->save();

            return $copy;
        });
    }

    /**
     * Downloads the package and opens it.
     *
     * @return array{response: TestResponse<Response>, files: array<string, string>, path: string}
     */
    private function download(string $query = '', ?string $period = null): array
    {
        $response = $this->actingAs($this->teacher)->get($this->url($query, $period));
        $response->assertOk();

        $base = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $base);

        $path = $base->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $files = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $files[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
        }
        $zip->close();

        return ['response' => $response, 'files' => $files, 'path' => $path];
    }

    /**
     * Every cell of every sheet of a workbook — to compare two workbooks without caring about their
     * bytes (a .xlsx carries its own timestamps).
     *
     * @return array<string, array<int, array<int, mixed>>>
     */
    private function cells(string $bytes): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'lapis-test-');
        file_put_contents($path, $bytes);

        try {
            $book = IOFactory::load($path);
            $sheets = [];

            foreach ($book->getAllSheets() as $sheet) {
                $sheets[$sheet->getTitle()] = $sheet->toArray(null, true, false);
            }

            $book->disconnectWorksheets();

            return $sheets;
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  array<string, string>  $files
     * @return list<string>
     */
    private function names(array $files): array
    {
        return array_keys($files);
    }

    /** @param array<string, string> $files */
    private function entry(array $files, string $prefix): string
    {
        foreach ($files as $name => $bytes) {
            if (str_starts_with($name, $prefix)) {
                return $bytes;
            }
        }

        $this->fail("o pacote não tem nenhum ficheiro «{$prefix}…»");
    }

    private function events(): int
    {
        return AuditEvent::withoutGlobalScopes()->where('event', 'like', '%exported')->count();
    }

    // ================================================================= o conteúdo

    #[Test]
    public function the_package_holds_the_pauta_the_synopsis_the_counting_element_and_a_readme(): void
    {
        $this->subscribe('base');
        $title = str($this->demoInstrument()->title)->slug()->limit(40, '')->value();

        $package = $this->download();

        $this->assertSame(
            ['1-01-pauta-7o-a-1o-semestre.xlsx', '2-quadro-sintese-7o-a-2026-2027.xlsx', "3-01-{$title}-2026-10-15.xlsx", 'LEIA-ME.txt'],
            $this->names($package['files']),
        );

        // They are real workbooks, with the class's students in them.
        foreach (['1-01-pauta', '2-quadro', '3-01'] as $prefix) {
            $sheets = $this->cells($this->entry($package['files'], $prefix));
            $this->assertNotEmpty($sheets, $prefix);
            $this->assertStringContainsString($this->studentNames()[0], (string) json_encode($sheets, JSON_UNESCAPED_UNICODE), "{$prefix} leva os alunos");
        }
    }

    #[Test]
    public function each_workbook_is_what_its_own_endpoint_gives(): void
    {
        $this->subscribe('base');
        $package = $this->download();
        $class = $this->classUlid();

        $pauta = $this->actingAs($this->teacher)->get("/classes/{$class}/pauta-avaliacao/xlsx/{$this->periodUlid()}");
        $synopsis = $this->actingAs($this->teacher)->get("/classes/{$class}/results/quadro-sintese/xlsx");
        $grid = $this->actingAs($this->teacher)->get("/instruments/{$this->demoInstrument()->ulid}/grelha/resultados");

        foreach ([$pauta, $synopsis, $grid] as $response) {
            $response->assertOk();
        }

        $this->assertSame($this->cells((string) $pauta->getContent()), $this->cells($this->entry($package['files'], '1-01-pauta')));
        $this->assertSame($this->cells((string) $synopsis->getContent()), $this->cells($this->entry($package['files'], '2-quadro')));

        $baseGrid = $grid->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $baseGrid);
        $this->assertSame($this->cells((string) file_get_contents($baseGrid->getFile()->getPathname())), $this->cells($this->entry($package['files'], '3-01')));
    }

    #[Test]
    public function elements_are_numbered_chronologically_and_never_collide_even_with_the_same_title(): void
    {
        $this->subscribe('base');

        $this->copyOfDemoInstrument(['applied_on' => '2026-09-20', 'status' => InstrumentStatus::Completed]);
        $this->copyOfDemoInstrument(['applied_on' => '2026-11-20', 'status' => InstrumentStatus::Completed]);

        $package = $this->download();
        $elements = array_values(array_filter($this->names($package['files']), fn (string $name): bool => str_starts_with($name, '3-')));

        $this->assertCount(3, $elements);
        $this->assertCount(3, array_unique($elements));
        $this->assertStringStartsWith('3-01-', $elements[0]);
        $this->assertStringEndsWith('2026-09-20.xlsx', $elements[0]);
        $this->assertStringEndsWith('2026-10-15.xlsx', $elements[1]);
        $this->assertStringEndsWith('2026-11-20.xlsx', $elements[2]);
    }

    #[Test]
    public function elements_that_do_not_count_stay_out_and_the_readme_says_how_many_and_why(): void
    {
        $this->subscribe('base');

        $this->copyOfDemoInstrument(['status' => InstrumentStatus::Cancelled]);
        $this->copyOfDemoInstrument(['purpose' => 'diagnostic']);
        $this->copyOfDemoInstrument(['counts_toward_classification' => false]);
        $this->copyOfDemoInstrument(['status' => InstrumentStatus::Draft]);

        $package = $this->download();
        $readme = $package['files']['LEIA-ME.txt'];

        $elements = array_filter($this->names($package['files']), fn (string $name): bool => str_starts_with($name, '3-'));
        $this->assertCount(1, $elements, 'só o elemento que conta');

        $this->assertStringContainsString('O QUE FICOU DE FORA', $readme);
        $this->assertStringContainsString('Elementos de avaliação anulados: 1', $readme);
        $this->assertStringContainsString('Elementos de diagnóstico, que nunca contam para a classificação: 1', $readme);
        $this->assertStringContainsString('Elementos marcados como não contando para a classificação: 1', $readme);
        $this->assertStringContainsString('Elementos ainda em preparação: 1', $readme);
        // The demo's element of the 2.º Semestre is not of this period.
        $this->assertStringContainsString('Elementos de períodos posteriores: 1', $readme);
    }

    #[Test]
    public function an_element_with_a_correction_still_open_goes_in_marked_as_provisional(): void
    {
        $this->subscribe('base');
        $this->copyOfDemoInstrument(['status' => InstrumentStatus::Completed, 'title' => 'Ficha concluída']);

        $readme = $this->download()['files']['LEIA-ME.txt'];

        // The demo's element is in correction; the copy is concluded.
        $this->assertSame(1, substr_count($readme, 'PROVISÓRIO: a correção ainda não está concluída.'));
        $this->assertStringContainsString('«Ficha concluída»', $readme);
        $this->assertStringContainsString('Alterar um ficheiro de Excel não altera nada no Lapispro', $readme);
        $this->assertStringContainsString('dados de alunos identificáveis', $readme);
        $this->assertStringContainsString('15/12/2026, 10:00', $readme);
        $this->assertStringNotContainsString('não vêm neste pacote', $readme);
    }

    /**
     * The 1.º and 2.º Semestre of the demo year, and a 3.º created after them, for the «ano até aqui» tests.
     *
     * @return array{AcademicPeriod, AcademicPeriod, AcademicPeriod}
     */
    private function periodsOfTheYear(): array
    {
        return $this->asTenant(function (): array {
            $year = SchoolClass::where('label', '7.º A')->firstOrFail()->academicYear;
            $third = $year->periods()->create([
                'label' => '3.º Semestre', 'kind' => 'semester', 'sequence' => 3, 'starts_on' => '2027-06-17', 'ends_on' => '2027-07-31', 'status' => 'draft',
            ]);
            $periods = $year->periods()->orderBy('sequence')->get();

            return [$periods[0], $periods[1], $third];
        });
    }

    #[Test]
    public function asking_for_the_second_period_brings_everything_behind_it_and_nothing_after(): void
    {
        $this->subscribe('base');
        [$first, $second, $third] = $this->periodsOfTheYear();
        $this->copyOfDemoInstrument(['academic_period_id' => $third->id, 'applied_on' => '2027-06-20']);

        $package = $this->download(period: $second->ulid);

        // Two Pautas, chronologically, the one asked for last.
        $sheets = array_values(array_filter($this->names($package['files']), fn (string $name): bool => str_starts_with($name, '1-')));
        $this->assertSame(['1-01-pauta-7o-a-1o-semestre.xlsx', '1-02-pauta-7o-a-2o-semestre.xlsx'], $sheets);

        // The elements of the 1.º and of the 2.º Semestre, in order; not the 3.º.
        $elements = array_values(array_filter($this->names($package['files']), fn (string $name): bool => str_starts_with($name, '3-')));
        $this->assertCount(2, $elements);
        $this->assertStringEndsWith('2026-10-15.xlsx', $elements[0]);
        $this->assertStringEndsWith('2027-03-10.xlsx', $elements[1]);

        $readme = $package['files']['LEIA-ME.txt'];
        $this->assertStringContainsString('Elementos de períodos posteriores: 1', $readme);
        $this->assertStringContainsString('A avaliação é contínua', $readme);
        // Grouped by period, each with its name as a heading.
        $firstHeading = strpos($readme, "\r\n".$first->label."\r\n- 3-01-");
        $secondHeading = strpos($readme, "\r\n".$second->label."\r\n- 3-02-");
        $this->assertNotFalse($firstHeading);
        $this->assertNotFalse($secondHeading);
        $this->assertLessThan($secondHeading, $firstHeading);
        $this->assertStringNotContainsString("\r\n".$third->label."\r\n", $readme);
        $this->assertStringNotContainsString('NOTA SOBRE O ACUMULADO', $readme);

        // The Pautas are what their own endpoint gives for each period.
        foreach ([[$first, '1-01-pauta-7o-a-1o-semestre.xlsx'], [$second, '1-02-pauta-7o-a-2o-semestre.xlsx']] as [$period, $name]) {
            $alone = $this->actingAs($this->teacher)->get("/classes/{$this->classUlid()}/pauta-avaliacao/xlsx/{$period->ulid}");
            $alone->assertOk();
            $this->assertSame($this->cells((string) $alone->getContent()), $this->cells($package['files'][$name]), $name);
        }

        $event = AuditEvent::withoutGlobalScopes()->where('event', 'evaluation_sheets.meeting_package.exported')->firstOrFail();
        $this->assertSame(2, $event->properties['periods_included']);
        $this->assertSame(2, $event->properties['sheets_included']);
        $this->assertSame(2, $event->properties['elements_included']);
        $this->assertSame(0, $event->properties['elements_outside_accumulated']);
        $this->assertSame(['other_period' => 1], $event->properties['elements_omitted']);
    }

    #[Test]
    public function asking_for_the_first_period_brings_nothing_from_behind(): void
    {
        $this->subscribe('base');

        $package = $this->download();

        $this->assertCount(1, array_filter($this->names($package['files']), fn (string $name): bool => str_starts_with($name, '1-')));
        $this->assertCount(1, array_filter($this->names($package['files']), fn (string $name): bool => str_starts_with($name, '3-')));
        $this->assertStringContainsString('Elementos de períodos posteriores: 1', $package['files']['LEIA-ME.txt']);

        $event = AuditEvent::withoutGlobalScopes()->where('event', 'evaluation_sheets.meeting_package.exported')->firstOrFail();
        $this->assertSame(1, $event->properties['periods_included']);
        $this->assertSame(1, $event->properties['sheets_included']);
    }

    #[Test]
    public function a_period_the_profile_leaves_out_of_the_accumulated_still_comes_and_the_readme_says_it_does_not_count_there(): void
    {
        $this->subscribe('base');
        [$first, $second] = $this->periodsOfTheYear();

        $this->asTenant(fn () => ProfileVersionPeriod::create([
            'assessment_profile_version_id' => SchoolClass::where('label', '7.º A')->firstOrFail()->assessment_profile_version_id,
            'academic_period_id' => $first->getKey(),
            'contributes_to_accumulated' => false,
        ]));

        $package = $this->download(period: $second->ulid);
        $readme = $package['files']['LEIA-ME.txt'];

        $this->assertCount(2, array_filter($this->names($package['files']), fn (string $name): bool => str_starts_with($name, '3-')), 'os dois períodos vêm');
        $this->assertStringContainsString('NOTA SOBRE O ACUMULADO', $readme);
        $this->assertSame(1, substr_count($readme, 'Não entra no acumulado, segundo o perfil de avaliação.'));

        $event = AuditEvent::withoutGlobalScopes()->where('event', 'evaluation_sheets.meeting_package.exported')->firstOrFail();
        $this->assertSame(1, $event->properties['elements_outside_accumulated']);
    }

    #[Test]
    public function the_interim_moment_titles_the_pauta_and_the_archive(): void
    {
        $this->subscribe('base');

        $package = $this->download('?momento=interim');

        $this->assertArrayHasKey('1-01-pauta-7o-a-1o-semestre-intercalar.xlsx', $package['files']);
        $this->assertStringContainsString('Momento intercalar do 1.º Semestre', $package['files']['LEIA-ME.txt']);
        $this->assertStringContainsString('intercalar', (string) $package['response']->headers->get('Content-Disposition'));
    }

    #[Test]
    public function without_the_elements_module_only_their_grids_are_left_out_and_the_reason_is_plan(): void
    {
        $this->subscribe('pro');

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->getKey(),
            'module_id' => Module::where('key', 'instruments')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Teste de override.',
        ]);
        app(Entitlements::class)->flush();

        $package = $this->download();

        $this->assertSame(['1-01-pauta-7o-a-1o-semestre.xlsx', '2-quadro-sintese-7o-a-2026-2027.xlsx', 'LEIA-ME.txt'], $this->names($package['files']));
        $this->assertStringContainsString('Elementos de avaliação: O plano da organização não inclui os elementos de avaliação.', $package['files']['LEIA-ME.txt']);

        $event = AuditEvent::withoutGlobalScopes()->where('event', 'evaluation_sheets.meeting_package.exported')->firstOrFail();
        $this->assertSame(['pauta', 'quadro-sintese'], $event->properties['documents_included']);
        // assertEquals, não assertSame: o MySQL reordena as chaves de um objeto
        // JSON, e a ordem delas não faz parte do que este registo promete.
        $this->assertEquals([['document' => 'elementos', 'reason' => 'plan']], $event->properties['documents_omitted']);
    }

    #[Test]
    public function a_class_without_elements_still_gets_the_pauta_and_the_synopsis(): void
    {
        $this->subscribe('base');
        $this->asTenant(fn () => Instrument::where('class_id', SchoolClass::where('label', '7.º A')->firstOrFail()->id)->get()->each->delete());

        $package = $this->download();

        $this->assertSame(['1-01-pauta-7o-a-1o-semestre.xlsx', '2-quadro-sintese-7o-a-2026-2027.xlsx', 'LEIA-ME.txt'], $this->names($package['files']));
    }

    // ================================================================= o ficheiro

    #[Test]
    public function it_is_a_zip_attachment_that_is_never_cached_and_never_named_after_a_student(): void
    {
        $this->subscribe('base');

        $package = $this->download();
        $response = $package['response'];

        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertSame('attachment; filename="reuniao-7o-a-1o-semestre-2026-12-15.zip"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));

        foreach ($this->names($package['files']) as $name) {
            foreach ($this->studentNames() as $student) {
                $this->assertStringNotContainsString(strtolower(explode(' ', $student)[0]), strtolower($name));
            }
        }
    }

    #[Test]
    public function nothing_stays_on_the_server_once_the_file_is_sent(): void
    {
        $this->subscribe('base');

        $package = $this->download();
        $this->assertFileExists($package['path']);

        ob_start();
        $package['response']->baseResponse->sendContent();
        ob_end_clean();

        $this->assertFileDoesNotExist($package['path']);
    }

    // ================================================================= portas

    #[Test]
    public function a_colleague_who_does_not_teach_the_class_gets_403_and_no_data(): void
    {
        $colleague = User::factory()->create();
        $this->organization->members()->syncWithoutDetaching([$colleague->id => ['joined_at' => now()]]);
        $this->withSession(['organization_id' => $this->organization->id]);

        $before = $this->events();
        $response = $this->actingAs($colleague)->get($this->url());

        $response->assertForbidden();
        $this->assertStringNotContainsString('Carolina Nunes', (string) $response->getContent());
        $this->assertSame($before, $this->events());
    }

    #[Test]
    public function a_period_of_another_academic_year_or_organization_does_not_resolve(): void
    {
        $this->actingAs($this->teacher)->get($this->url(period: '01JZZZZZZZZZZZZZZZZZZZZZZZ'))->assertNotFound();
        $this->actingAs($this->teacher)->get($this->url(period: 'não-é-um-ulid'))->assertNotFound();

        $otherYear = $this->asTenant(function (): string {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $year = $class->academicYear->replicate(['ulid']);
            $year->forceFill(['label' => '2025/2026', 'starts_on' => '2025-09-14', 'ends_on' => '2026-06-30', 'status' => 'archived'])->save();

            return $year->periods()->create([
                'label' => '1.º Período', 'kind' => 'term', 'sequence' => 1, 'starts_on' => '2025-09-14', 'ends_on' => '2025-12-19', 'status' => 'open',
            ])->ulid;
        });

        $this->actingAs($this->teacher)->get($this->url(period: $otherYear))->assertNotFound();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get($this->url())->assertNotFound();
    }

    #[Test]
    public function a_guest_is_sent_to_login(): void
    {
        $this->get($this->url())->assertRedirect('/login');
    }

    // ================================================================= auditoria

    #[Test]
    public function exactly_one_trail_is_left_with_counts_and_no_names(): void
    {
        $this->subscribe('pro');
        $this->copyOfDemoInstrument(['status' => InstrumentStatus::Cancelled]);
        $before = $this->events();

        $this->download('?momento=interim');

        $events = AuditEvent::withoutGlobalScopes()->where('event', 'like', '%exported')->orderBy('id')->get();
        $this->assertCount($before + 1, $events);

        $event = $events->last();
        $this->assertSame('evaluation_sheets.meeting_package.exported', $event->event);
        $this->assertSame($this->teacher->id, $event->causer_id);
        $this->assertSame('zip', $event->properties['format']);
        $this->assertSame('interim', $event->properties['moment']);
        $this->assertSame($this->periodUlid(), $event->properties['academic_period_ulid']);
        $this->assertSame(['pauta', 'quadro-sintese', 'elementos'], $event->properties['documents_included']);
        $this->assertSame([], $event->properties['documents_omitted']);
        $this->assertSame(1, $event->properties['elements_included']);
        $this->assertSame(1, $event->properties['elements_provisional']);

        $omitted = $event->properties['elements_omitted'];
        ksort($omitted);
        $this->assertSame(['cancelled' => 1, 'other_period' => 1], $omitted);
        $this->assertGreaterThan(0, $event->properties['students']);

        $trail = json_encode($event->properties).$event->summary;

        foreach ($this->studentNames() as $name) {
            $this->assertStringNotContainsString($name, $trail);
        }
    }

    #[Test]
    public function the_package_leaves_none_of_the_individual_trails_and_the_endpoints_still_leave_theirs(): void
    {
        $this->subscribe('base');

        $this->download();

        foreach (['report.exported', 'class-synopsis.exported'] as $event) {
            $this->assertSame(0, AuditEvent::withoutGlobalScopes()->where('event', $event)->count(), "o pacote não emite «{$event}»");
        }

        $class = $this->classUlid();
        $this->actingAs($this->teacher)->get("/classes/{$class}/pauta-avaliacao/xlsx/{$this->periodUlid()}")->assertOk();
        $this->actingAs($this->teacher)->get("/classes/{$class}/pauta-avaliacao/csv/{$this->periodUlid()}")->assertOk();
        $this->actingAs($this->teacher)->get("/classes/{$class}/results/quadro-sintese/xlsx")->assertOk();

        $this->assertSame(2, AuditEvent::withoutGlobalScopes()->where('event', 'report.exported')->count());
        $this->assertSame(1, AuditEvent::withoutGlobalScopes()->where('event', 'class-synopsis.exported')->count());
    }

    #[Test]
    public function the_package_route_is_declared_before_the_period_wildcard(): void
    {
        $route = app('router')->getRoutes()->match(app('request')->create($this->url()))->getName();

        $this->assertSame('evaluation-sheets.meeting-package', $route);
    }
}
