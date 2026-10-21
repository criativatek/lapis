<?php

namespace Tests\Feature\Reporting;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Enrollment;
use App\Models\EvaluationSheetExport;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\User;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\Export\EvaluationSheetDocument;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGrid;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGridOptions;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The grid of a class fed by the REAL Pauta: the live sheet, the kept moment, the
 * screen and the CSV.
 *
 * The unit test holds the structure against hand-made data. This one holds what
 * the structure is for: that what is printed is what the Pauta shows on screen
 * and writes to the CSV, aluno a aluno; that a kept moment is its payload and not
 * today; that taking a decision moves the right cell and only it; and that
 * reading a class costs the same number of queries for six students and for
 * thirty (§62).
 */
class EvaluationSheetGridSourceTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        // Inside the 1.º Semestre of the demo (14/09/2026 to 29/01/2027).
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $this->asTenant(function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $this->period($class));
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
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    private function period(SchoolClass $class): AcademicPeriod
    {
        return $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
    }

    /**
     * The grid of the live sheet, built as the controller will: the sheet WITHOUT
     * the proposal basis (nobody reads it here), into the document, into the grid.
     *
     * @return array<string, mixed>
     */
    private function liveGrid(?EvaluationSheetGridOptions $options = null, bool $withBasis = false): array
    {
        return $this->asTenant(function () use ($options, $withBasis): array {
            $class = SchoolClass::where('label', '7.º A')->with(['subject', 'academicYear'])->firstOrFail();
            $period = $this->period($class);
            $sheet = app(BuildEvaluationSheet::class)->for($class, $period, ClassificationScope::Period, $withBasis);

            return (new EvaluationSheetGrid)->build(
                EvaluationSheetDocument::fromLiveSheet($class, $period, $sheet, ClassificationScope::Period),
                $options ?? new EvaluationSheetGridOptions,
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotGrid(string $exportUlid, ?EvaluationSheetGridOptions $options = null): array
    {
        return $this->asTenant(fn (): array => (new EvaluationSheetGrid)->build(
            EvaluationSheetDocument::fromSnapshot(EvaluationSheetExport::where('ulid', $exportUlid)->firstOrFail()),
            $options ?? new EvaluationSheetGridOptions,
        ));
    }

    private function classUlid(): string
    {
        return $this->asTenant(fn (): string => SchoolClass::where('label', '7.º A')->firstOrFail()->ulid);
    }

    private function periodUlid(): string
    {
        return $this->asTenant(fn (): string => $this->period(SchoolClass::where('label', '7.º A')->firstOrFail())->ulid);
    }

    private function enrollmentUlid(string $name): string
    {
        return $this->asTenant(fn (): string => SchoolClass::where('label', '7.º A')->firstOrFail()
            ->enrollments()->with('student.identity')->get()
            ->first(fn (Enrollment $enrollment): bool => $enrollment->student->identity->display_name === $name)->ulid);
    }

    private function decide(string $name, string $code): void
    {
        $levelId = $this->asTenant(fn (): int => SchoolClass::where('label', '7.º A')->firstOrFail()
            ->profileVersion->scale->levels()->where('code', $code)->firstOrFail()->id);

        $this->actingAs($this->teacher)->post(
            "/classes/{$this->classUlid()}/classifications/{$this->periodUlid()}/{$this->enrollmentUlid($name)}/decide",
            ['final_scale_level_id' => $levelId],
        )->assertSessionHasNoErrors()->assertRedirect();
    }

    private function keep(string $label): string
    {
        $this->actingAs($this->teacher)->post("/classes/{$this->classUlid()}/pauta-avaliacao/{$this->periodUlid()}/guardar", [
            'moment_label' => $label,
            'effective_at' => '2026-12-15',
            'scope' => ClassificationScope::Period->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return $this->asTenant(fn (): string => EvaluationSheetExport::query()->orderByDesc('id')->firstOrFail()->ulid);
    }

    /**
     * The cells of every row of the grid, flattened across blocks: name => kind => list of cells.
     *
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    private function cells(array $grid): array
    {
        $byName = [];

        foreach ($grid['blocks'] as $block) {
            foreach ($block['rows'] as $row) {
                foreach ($block['columns'] as $position => $column) {
                    $byName[$row['name']][$column['kind']][] = $row['cells'][$position];
                }
            }
        }

        return $byName;
    }

    /**
     * @return array<string, mixed>
     */
    private function screenProps(): array
    {
        $response = $this->actingAs($this->teacher)->get("/classes/{$this->classUlid()}/pauta-avaliacao/{$this->periodUlid()}");
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        return $page['props'];
    }

    private function percent(?string $value): string
    {
        return $value === null ? '—' : number_format((float) $value, 1, ',', '').'%';
    }

    // ------------------------------------------------------------ parity with the Pauta

    #[Test]
    public function the_grid_prints_what_the_pauta_shows_on_screen_student_by_student(): void
    {
        $this->decide('Carolina Nunes', '4');

        $screen = $this->screenProps()['sheet'];
        $grid = $this->liveGrid();
        $cells = $this->cells($grid);

        $this->assertNotEmpty($screen['students']);
        $this->assertSame(array_column($screen['students'], 'name'), array_column($grid['blocks'][0]['rows'], 'name'), 'the same lines, in the same order');
        // A class with five domains does not fit one landscape block: the domains are spread across the
        // blocks, each whole, and together they are the Pauta's, in the Pauta's order.
        $printedDomains = [];

        foreach ($grid['blocks'] as $block) {
            foreach ($block['group_spans'] as $span) {
                if ($span['label'] !== null && ! in_array($span['label'], ['Global', 'Classificação'], true)) {
                    $printedDomains[] = $span['label'];
                }
            }

            $this->assertSame(array_column($screen['students'], 'name'), array_column($block['rows'], 'name'), 'every block carries every student');
        }

        $this->assertSame(array_column($screen['domains'], 'name'), $printedDomains, 'the same domains, in the same order');

        foreach ($screen['students'] as $student) {
            $name = $student['name'];

            foreach ($student['domains'] as $index => $domain) {
                $this->assertSame($this->percent($domain['normalized_value']), rtrim($cells[$name]['domain_result'][$index]['text'], ' *'), "{$name}: domain {$index} percentage");

                $decided = $domain['decided_scale_level_code'] ?? $domain['decided_scale_level_label'];
                $proposed = $domain['scale_level_code'] ?? $domain['scale_level_label'];
                $printed = $cells[$name]['domain_appreciation'][$index];

                $this->assertSame($decided ?? $proposed ?? '—', $printed['text'], "{$name}: domain {$index} appreciation");
                $this->assertSame($decided !== null, $printed['origin'] === 'decision');
            }

            $this->assertSame($this->percent($student['overall']['normalized_value']), rtrim($cells[$name]['result'][0]['text'], ' *'), "{$name}: global");

            $classification = $student['classification'];
            $assigned = $classification['final_scale_level_code'] ?? $classification['final_scale_level_label'] ?? $classification['final_value'] ?? null;

            $this->assertSame($assigned === null ? '—' : (string) $assigned, $cells[$name]['assigned'][0]['text'], "{$name}: assigned level");
            $this->assertSame(
                $classification === null ? '—' : ClassificationStatus::from($classification['status'])->label(),
                $cells[$name]['status'][0]['text'],
                "{$name}: state",
            );
        }

        $carolina = $cells['Carolina Nunes'];
        $this->assertSame('4', $carolina['assigned'][0]['text']);
        $this->assertSame('decision', $carolina['assigned'][0]['origin']);
    }

    #[Test]
    public function the_grid_prints_what_the_csv_of_the_pauta_writes(): void
    {
        $this->decide('Carolina Nunes', '4');

        $cells = $this->cells($this->liveGrid());
        $csv = $this->csvOf($this->actingAs($this->teacher)->get("/classes/{$this->classUlid()}/pauta-avaliacao/csv/{$this->periodUlid()}"));

        $header = array_shift($csv);
        $this->assertNotEmpty($csv);
        $column = fn (string $name): int => array_search($name, $header, true);

        foreach ($csv as $line) {
            $name = $line[1];
            $domains = [];

            foreach ($header as $position => $title) {
                if (str_ends_with($title, ' — Percentagem') && ! str_starts_with($title, 'Global')) {
                    $domains[] = substr($title, 0, -strlen(' — Percentagem'));
                }
            }

            foreach ($domains as $index => $domain) {
                $this->assertSame(
                    $line[$column("{$domain} — Percentagem")] === '' ? '—' : str_replace('.', ',', $line[$column("{$domain} — Percentagem")]).'%',
                    rtrim($cells[$name]['domain_result'][$index]['text'], ' *'),
                );
                $this->assertSame($line[$column("{$domain} — Apreciação")] ?: '—', $cells[$name]['domain_appreciation'][$index]['text']);
            }

            $this->assertSame($line[$column('Global — Percentagem')] === '' ? '—' : str_replace('.', ',', $line[$column('Global — Percentagem')]).'%', rtrim($cells[$name]['result'][0]['text'], ' *'));
            $this->assertSame($line[$column('Proposta do Lapispro')] ?: '—', $cells[$name]['proposal'][0]['text']);
            $this->assertSame($line[$column('Nível atribuído')] ?: '—', $cells[$name]['assigned'][0]['text']);
            $this->assertSame($line[$column('Avisos de cobertura')], $cells[$name]['coverage'][0]['text']);
        }
    }

    /**
     * @return list<list<string>> the rows of the table, header first
     */
    private function csvOf(TestResponse $response): array
    {
        $response->assertOk();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, substr((string) $response->getContent(), 3));
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        $start = array_search('Nº', array_map(fn (array $row): ?string => $row[0] ?? null, $rows), true);

        return array_slice($rows, (int) $start);
    }

    #[Test]
    public function the_proposal_basis_the_screen_reads_changes_nothing_in_the_grid(): void
    {
        $this->assertSame($this->liveGrid(withBasis: false), $this->liveGrid(withBasis: true));
    }

    // ------------------------------------------------------------ decisions

    #[Test]
    public function a_decision_moves_the_assigned_level_and_the_state_of_that_student_and_nothing_else(): void
    {
        $before = $this->cells($this->liveGrid());

        $this->decide('Carolina Nunes', '3');
        $after = $this->cells($this->liveGrid());

        $changed = array_keys(array_filter($after, fn (array $cells, string $name): bool => $cells !== $before[$name], ARRAY_FILTER_USE_BOTH));
        $this->assertSame(['Carolina Nunes'], $changed);

        foreach (['domain_result', 'domain_appreciation', 'result', 'proposal', 'coverage'] as $kind) {
            $this->assertSame($before['Carolina Nunes'][$kind], $after['Carolina Nunes'][$kind], "{$kind} is calculated or proposed: a decision does not move it");
        }

        $this->assertSame('3', $after['Carolina Nunes']['assigned'][0]['text']);
        $this->assertNotSame($before['Carolina Nunes']['status'], $after['Carolina Nunes']['status']);

        $stored = $this->asTenant(fn (): Classification => Classification::query()
            ->where('enrollment_id', Enrollment::query()->where('ulid', $this->enrollmentUlid('Carolina Nunes'))->value('id'))
            ->whereNull('superseded_by_id')->firstOrFail());
        $this->assertSame($stored->status->label(), $after['Carolina Nunes']['status'][0]['text']);
    }

    // ------------------------------------------------------------ the kept moment

    #[Test]
    public function a_kept_moment_prints_its_payload_and_never_the_present(): void
    {
        $this->decide('Carolina Nunes', '3');
        $exportUlid = $this->keep('Momento de novembro');

        $kept = $this->snapshotGrid($exportUlid);
        $liveThen = $this->liveGrid();

        $this->assertTrue($kept['source']['is_historical']);
        $this->assertSame('Momento de novembro', $kept['title']);
        $this->assertSame('15/12/2026', $kept['meta']['reference_date']);
        $this->assertSame($this->cells($liveThen), $this->cells($kept), 'at the moment it was kept, the moment IS the live sheet');

        // The teacher changes their mind. The live grid follows; the kept one does not.
        $this->decide('Carolina Nunes', '4');

        $this->assertSame($kept, $this->snapshotGrid($exportUlid), 'not one cell of a kept moment moves');
        $this->assertSame('4', $this->cells($this->liveGrid())['Carolina Nunes']['assigned'][0]['text']);
        $this->assertSame('3', $this->cells($this->snapshotGrid($exportUlid))['Carolina Nunes']['assigned'][0]['text']);
    }

    #[Test]
    public function a_kept_moment_is_read_without_a_single_query(): void
    {
        $exportUlid = $this->keep('Momento de novembro');

        $export = $this->asTenant(fn (): EvaluationSheetExport => EvaluationSheetExport::where('ulid', $exportUlid)->firstOrFail());
        $document = EvaluationSheetDocument::fromSnapshot($export);

        DB::flushQueryLog();
        DB::enableQueryLog();
        (new EvaluationSheetGrid)->build($document, new EvaluationSheetGridOptions);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries);
    }

    // ------------------------------------------------------------ privacy

    #[Test]
    public function the_free_text_of_a_decision_and_every_identifier_stay_out_of_the_grid(): void
    {
        $this->decide('Carolina Nunes', '4');

        $this->asTenant(function (): void {
            Classification::query()->where('enrollment_id', Enrollment::query()->where('ulid', $this->enrollmentUlid('Carolina Nunes'))->value('id'))
                ->update(['override_reason' => 'MOTIVO-LIVRE-RESERVADO']);
        });

        $withReason = json_encode($this->liveGrid(new EvaluationSheetGridOptions(selfAssessment: true)), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($withReason);
        $this->assertStringNotContainsString('MOTIVO-LIVRE-RESERVADO', $withReason);

        $kept = json_encode($this->snapshotGrid($this->keep('Momento privado'), new EvaluationSheetGridOptions(selfAssessment: true)), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($kept);
        $this->assertStringNotContainsString('MOTIVO-LIVRE-RESERVADO', $kept);

        foreach (['enrollment_id', 'student_id', 'domain_id', 'ulid', 'organization'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $withReason);
            $this->assertStringNotContainsString($forbidden, $kept);
        }
    }

    // ------------------------------------------------------------ performance

    /** @return array{0: int, 1: int} queries, and students read */
    private function measure(): array
    {
        return $this->asTenant(function (): array {
            $class = SchoolClass::where('label', '7.º A')->with(['subject', 'academicYear'])->firstOrFail();
            $period = $this->period($class);

            DB::flushQueryLog();
            DB::enableQueryLog();

            $sheet = app(BuildEvaluationSheet::class)->for($class, $period, ClassificationScope::Period, false);
            $grid = (new EvaluationSheetGrid)->build(
                EvaluationSheetDocument::fromLiveSheet($class, $period, $sheet, ClassificationScope::Period),
                new EvaluationSheetGridOptions,
            );

            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$count, $grid['summary']['students']];
        });
    }

    /** Fills the class up to the requested number, with students that have no elements at all. */
    private function enrolUpTo(int $total): void
    {
        $this->asTenant(function () use ($total): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $organization = $this->teacher->personalOrganization();
            $existing = Enrollment::query()->where('class_id', $class->getKey())->count();

            for ($number = $existing + 1; $number <= $total; $number++) {
                $student = Student::factory()->recycle($organization)->create();

                StudentIdentity::create([
                    'student_id' => $student->getKey(),
                    'organization_id' => $organization->getKey(),
                    'display_name' => "Aluno de Teste {$number}",
                ]);

                Enrollment::factory()->recycle($organization)->create([
                    'class_id' => $class->getKey(),
                    'student_id' => $student->getKey(),
                    'class_number' => $number,
                    'enrolled_on' => '2026-09-14',
                ]);
            }
        });
    }

    #[Test]
    public function reading_a_class_costs_the_same_number_of_queries_for_six_students_and_for_thirty(): void
    {
        [$small, $smallStudents] = $this->measure();

        $this->enrolUpTo(30);

        [$large, $largeStudents] = $this->measure();

        $this->assertSame(6, $smallStudents);
        $this->assertSame(30, $largeStudents);
        $this->assertSame($small, $large, "Six students cost {$small} queries and thirty cost {$large}: the cost grows with the class.");
    }
}
