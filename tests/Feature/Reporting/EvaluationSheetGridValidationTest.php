<?php

namespace Tests\Feature\Reporting;

use App\Models\AcademicPeriod;
use App\Models\AuditEvent;
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
use App\Services\Assessment\Export\EvaluationSheetCsvWriter;
use App\Services\Assessment\Export\EvaluationSheetDocument;
use App\Services\Assessment\Export\EvaluationSheetXlsxWriter;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGrid;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGridOptions;
use App\Support\Hashing\CanonicalPayload;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PHPUnit\Framework\Attributes\Test;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

/**
 * The final validation of the grid of a class (Fatia C): the matrix of the audit,
 * over the REAL Pauta, with what the earlier steps did not yet hold together.
 *
 * Each step tested its own piece — the structure, the page, the PDF, the charts.
 * What is held here is what only shows across them: that the Pauta, its CSV, its
 * Excel, the preview and the PDF say the SAME thing about every student, for the
 * live sheet and for a kept moment alike; that a kept moment, even an old one,
 * opens in every consumer without ever asking the present; that the three states of
 * a classification are said in words; that a class without a profile still makes a
 * document; that what a name can contain stays text; and that the cost does not
 * grow with the class.
 */
class EvaluationSheetGridValidationTest extends TestCase
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

    private function classUlid(string $label = '7.º A'): string
    {
        return $this->asTenant(fn (): string => SchoolClass::where('label', $label)->firstOrFail()->ulid);
    }

    private function periodUlid(): string
    {
        return $this->asTenant(fn (): string => $this->period(SchoolClass::where('label', '7.º A')->firstOrFail())->ulid);
    }

    private function decide(string $name, string $code): void
    {
        $levelId = $this->asTenant(fn (): int => SchoolClass::where('label', '7.º A')->firstOrFail()
            ->profileVersion->scale->levels()->where('code', $code)->firstOrFail()->id);
        $enrollment = $this->asTenant(fn (): string => SchoolClass::where('label', '7.º A')->firstOrFail()
            ->enrollments()->with('student.identity')->get()
            ->first(fn (Enrollment $candidate): bool => $candidate->student->identity->display_name === $name)->ulid);

        $this->actingAs($this->teacher)->post(
            "/classes/{$this->classUlid()}/classifications/{$this->periodUlid()}/{$enrollment}/decide",
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

    private function liveDocument(): EvaluationSheetDocument
    {
        return $this->asTenant(function (): EvaluationSheetDocument {
            $class = SchoolClass::where('label', '7.º A')->with(['subject', 'academicYear'])->firstOrFail();
            $period = $this->period($class);

            return EvaluationSheetDocument::fromLiveSheet(
                $class,
                $period,
                app(BuildEvaluationSheet::class)->for($class, $period, ClassificationScope::Period, false),
                ClassificationScope::Period,
            );
        });
    }

    private function snapshotDocument(string $exportUlid): EvaluationSheetDocument
    {
        return $this->asTenant(fn (): EvaluationSheetDocument => EvaluationSheetDocument::fromSnapshot(EvaluationSheetExport::where('ulid', $exportUlid)->firstOrFail()));
    }

    // ================================================================= os cinco documentos dizem o mesmo

    /**
     * What the CSV says about each student: name => [domain percentages, appreciations, proposal, assigned, global].
     *
     * @return array<string, array<string, mixed>>
     */
    private function csvOf(EvaluationSheetDocument $document): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, substr((new EvaluationSheetCsvWriter)->write($document), strlen(EvaluationSheetCsvWriter::BOM)));
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        $start = (int) array_search('Nº', array_map(fn (array $row): ?string => $row[0] ?? null, $rows), true);
        $header = $rows[$start];
        $said = [];

        foreach (array_slice($rows, $start + 1) as $line) {
            $entry = ['domains' => [], 'appreciations' => []];

            foreach ($header as $position => $title) {
                if (str_ends_with($title, ' — Percentagem') && ! str_starts_with($title, 'Global')) {
                    $entry['domains'][] = $line[$position] === '' ? '—' : str_replace('.', ',', $line[$position]).'%';
                }

                if (str_ends_with($title, ' — Apreciação')) {
                    $entry['appreciations'][] = $line[$position] === '' ? '—' : $line[$position];
                }
            }

            $at = fn (string $name): string => $line[(int) array_search($name, $header, true)];
            $entry['global'] = $at('Global — Percentagem') === '' ? '—' : str_replace('.', ',', $at('Global — Percentagem')).'%';
            $entry['proposal'] = $at('Proposta do Lapispro') === '' ? '—' : $at('Proposta do Lapispro');
            $entry['assigned'] = $at('Nível atribuído') === '' ? '—' : $at('Nível atribuído');
            $said[$line[1]] = $entry;
        }

        return $said;
    }

    /**
     * What the Excel says about each student, in the same shape — read from the real file.
     *
     * @return array<string, array<string, mixed>>
     */
    private function xlsxOf(EvaluationSheetDocument $document): array
    {
        $path = tempnam(sys_get_temp_dir(), 'lapis_validation_');
        $this->assertIsString($path);
        file_put_contents($path, (new EvaluationSheetXlsxWriter)->write($document));

        try {
            $spreadsheet = (new XlsxReader)->load($path);
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
            $spreadsheet->disconnectWorksheets();
        } finally {
            @unlink($path);
        }

        // Two header rows: the groups above, the names of the columns beneath.
        $groupRow = (int) array_search('Nº', array_map(fn (array $row): mixed => $row[0], $rows), true);
        $names = $rows[$groupRow + 1];
        $groups = $rows[$groupRow];
        $said = [];

        // The group of each column: a merged cell leaves the following ones empty.
        $groupOf = [];
        $current = '';

        foreach ($groups as $position => $title) {
            $current = $title !== null && $title !== '' ? (string) $title : $current;
            $groupOf[$position] = $current;
        }

        foreach (array_slice($rows, $groupRow + 2) as $line) {
            if (($line[1] ?? null) === null || $line[1] === '') {
                continue;
            }

            $entry = ['domains' => [], 'appreciations' => []];

            foreach ($names as $position => $title) {
                $group = $groupOf[$position] ?? '';

                if ($title === 'Quant.') {
                    $entry['domains'][] = $line[$position] === null ? '—' : number_format((float) $line[$position], 1, ',', '').'%';
                }

                if ($title === 'Apreciação') {
                    $entry['appreciations'][] = $line[$position] === null || $line[$position] === '' ? '—' : (string) $line[$position];
                }

                if ($group === 'Global' && $title === 'Percentagem') {
                    $entry['global'] = $line[$position] === null ? '—' : number_format((float) $line[$position], 1, ',', '').'%';
                }

                if ($group === 'Classificação' && $title === 'Proposta do Lapispro') {
                    $entry['proposal'] = $line[$position] === null || $line[$position] === '' ? '—' : (string) $line[$position];
                }

                if ($group === 'Classificação' && $title === 'Nível atribuído') {
                    $entry['assigned'] = $line[$position] === null || $line[$position] === '' ? '—' : (string) $line[$position];
                }
            }

            $said[(string) $line[1]] = $entry;
        }

        return $said;
    }

    /**
     * What the grid (the preview's structure) says about each student, in the same shape.
     *
     * @return array<string, array<string, mixed>>
     */
    private function gridOf(EvaluationSheetDocument $document): array
    {
        $grid = (new EvaluationSheetGrid)->build($document, new EvaluationSheetGridOptions);
        $said = [];

        foreach ($grid['blocks'] as $block) {
            foreach ($block['rows'] as $row) {
                foreach ($block['columns'] as $position => $column) {
                    $cell = $row['cells'][$position]['text'];
                    $entry = &$said[$row['name']];
                    $entry['domains'] ??= [];
                    $entry['appreciations'] ??= [];

                    match ($column['kind']) {
                        'domain_result' => $entry['domains'][] = rtrim($cell, ' *'),
                        'domain_appreciation' => $entry['appreciations'][] = $cell,
                        'result' => $entry['global'] = rtrim($cell, ' *'),
                        'proposal' => $entry['proposal'] = $cell,
                        'assigned' => $entry['assigned'] = $cell,
                        default => null,
                    };
                    unset($entry);
                }
            }
        }

        return $said;
    }

    /**
     * The text of each page of a PDF, in page order.
     *
     * @return list<string>
     */
    private function pages(string $bytes): array
    {
        return array_values(array_map(
            fn ($page): string => preg_replace('/\s+/u', ' ', $page->getText()) ?? '',
            (new Parser)->parseContent($bytes)->getPages(),
        ));
    }

    #[Test]
    public function the_pauta_its_csv_its_excel_and_the_grid_say_the_same_about_every_student_live_and_kept(): void
    {
        $this->decide('Carolina Nunes', '4');
        $this->decide('Ana Marques', '5');
        $exportUlid = $this->keep('Momento de teste');

        // Later changes to the present must not reach the kept moment in ANY of the documents.
        $this->decide('Carolina Nunes', '2');
        $this->decide('Bruno Teixeira', '3');

        foreach (['live' => $this->liveDocument(), 'kept' => $this->snapshotDocument($exportUlid)] as $source => $document) {
            $grid = $this->gridOf($document);
            $csv = $this->csvOf($document);
            $xlsx = $this->xlsxOf($document);

            $this->assertNotEmpty($grid, $source);
            $this->assertSame(array_keys($grid), array_keys($csv), "{$source}: the same students in the same order, CSV");
            $this->assertSame(array_keys($grid), array_keys($xlsx), "{$source}: the same students in the same order, Excel");

            foreach ($grid as $name => $said) {
                $this->assertSame($said['domains'], $csv[$name]['domains'], "{$source}: {$name}: domain percentages, CSV");
                $this->assertSame($said['domains'], $xlsx[$name]['domains'], "{$source}: {$name}: domain percentages, Excel");
                $this->assertSame($said['appreciations'], $csv[$name]['appreciations'], "{$source}: {$name}: appreciations, CSV");
                $this->assertSame($said['appreciations'], $xlsx[$name]['appreciations'], "{$source}: {$name}: appreciations, Excel");

                foreach (['global', 'proposal', 'assigned'] as $key) {
                    $this->assertSame($said[$key], $csv[$name][$key], "{$source}: {$name}: {$key}, CSV");
                    $this->assertSame($said[$key], $xlsx[$name][$key], "{$source}: {$name}: {$key}, Excel");
                }
            }
        }

        // And the two sources really differ where the teacher changed their mind: the check above is not vacuous.
        $this->assertSame('4', $this->gridOf($this->snapshotDocument($exportUlid))['Carolina Nunes']['assigned']);
        $this->assertSame('2', $this->gridOf($this->liveDocument())['Carolina Nunes']['assigned']);
    }

    #[Test]
    public function the_pdf_the_preview_and_the_charts_of_a_kept_moment_say_what_its_csv_and_excel_say(): void
    {
        $this->decide('Carolina Nunes', '4');
        $exportUlid = $this->keep('Momento de teste');
        $this->decide('Carolina Nunes', '2');

        $document = $this->snapshotDocument($exportUlid);
        $csv = $this->csvOf($document);
        $base = "/classes/{$this->classUlid()}/pauta-avaliacao/historico/{$exportUlid}/impressao";

        $page = null;
        $this->actingAs($this->teacher)->get($base.'?graficos=1')->assertOk()->assertInertia(function ($inertia) use (&$page): void {
            $page = $inertia->toArray()['props']['document'];
        });

        $text = implode(' ', $this->pages((string) $this->actingAs($this->teacher)->get($base.'/pdf?graficos=1')->assertOk()->getContent()));

        foreach ($csv as $name => $said) {
            $this->assertStringContainsString($name, $text);

            foreach ([...$said['domains'], $said['global']] as $figure) {
                if ($figure !== '—') {
                    $this->assertStringContainsString($figure, $text, "{$name}: {$figure}");
                }
            }
        }

        // The page, the PDF and the grid built straight from the payload are one structure.
        $expected = (new EvaluationSheetGrid)->build($document, EvaluationSheetGridOptions::fromQuery(['graficos' => '1']));
        $this->assertSame(json_decode((string) json_encode($expected['blocks']), true), $page['blocks']);
        $this->assertSame(json_decode((string) json_encode($expected['charts']), true), $page['charts']);

        // The charts count the very levels the CSV lists: 4 was assigned to Carolina, never the 2 of today.
        $assigned = array_count_values(array_filter(array_column($csv, 'assigned'), fn (string $level): bool => $level !== '—'));
        $this->assertSame(['4' => 1], $assigned);
        $this->assertSame(1, $page['charts']['assigned']['n']);
    }

    // ================================================================= fotografias antigas, em todos os consumidores

    #[Test]
    public function the_oldest_kept_moments_open_in_every_consumer_without_asking_the_present(): void
    {
        $this->decide('Carolina Nunes', '4');
        $exportUlid = $this->keep('Momento antigo');

        // The oldest shape in production: version 1, no moment kind, no author, no self-assessment, no level codes,
        // no colours, no domain decisions, no scale.
        $this->asTenant(function (): void {
            $export = EvaluationSheetExport::query()->orderByDesc('id')->firstOrFail();
            $payload = $export->payload;

            $payload['version'] = 1;
            unset($payload['scale'], $payload['author'], $payload['moment']['kind'], $payload['warnings']);

            foreach ($payload['students'] as $index => $student) {
                unset($payload['students'][$index]['self_assessment'], $payload['students'][$index]['overall']['scale_level_code']);

                foreach ($student['domains'] as $domainIndex => $domain) {
                    unset(
                        $payload['students'][$index]['domains'][$domainIndex]['self_assessment'],
                        $payload['students'][$index]['domains'][$domainIndex]['scale_level_code'],
                        $payload['students'][$index]['domains'][$domainIndex]['decided_scale_level_code'],
                        $payload['students'][$index]['domains'][$domainIndex]['decided_scale_level_label'],
                    );
                }

                if (is_array($student['classification'])) {
                    unset($payload['students'][$index]['classification']['status']);
                }
            }

            foreach ($payload['domains'] as $index => $domain) {
                unset($payload['domains'][$index]['color']);
            }

            DB::table('evaluation_sheet_exports')->where('id', $export->id)->update([
                'payload' => json_encode($payload),
                'payload_hash' => CanonicalPayload::hash($payload),
            ]);
        });

        $document = $this->snapshotDocument($exportUlid);

        // The present moves on: not one of the documents may notice.
        $this->decide('Carolina Nunes', '2');
        $this->decide('Ana Marques', '5');

        DB::enableQueryLog();
        $grid = (new EvaluationSheetGrid)->build($document, EvaluationSheetGridOptions::fromQuery(['graficos' => '1', 'autoavaliacao' => '1']));
        $csv = $this->csvOf($document);
        $xlsx = $this->xlsxOf($document);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries, 'the grid, the CSV and the Excel of a kept moment touch no database');
        $this->assertSame(['Carolina Nunes'], array_keys(array_filter($csv, fn (array $said): bool => $said['assigned'] !== '—')));
        $this->assertSame('4', $csv['Carolina Nunes']['assigned']);
        $this->assertSame($csv['Carolina Nunes']['assigned'], $xlsx['Carolina Nunes']['assigned']);
        $this->assertFalse($grid['self_assessment_available']);
        $this->assertFalse($grid['charts']['proposals']['available'], 'no scale was frozen: the level charts say so');
        $this->assertNotSame([], $grid['charts']['means']['bars']);
        $this->assertNull($grid['source']['author']);

        // And through the real routes: the preview and the PDF.
        $base = "/classes/{$this->classUlid()}/pauta-avaliacao/historico/{$exportUlid}/impressao";
        $this->actingAs($this->teacher)->get($base.'?graficos=1')->assertOk();
        $text = implode(' ', $this->pages((string) $this->actingAs($this->teacher)->get($base.'/pdf?graficos=1')->assertOk()->getContent()));

        $this->assertStringContainsString('Carolina Nunes', $text);
        $this->assertStringContainsString('guardada antes de a escala viajar com ela', $text);
        $this->assertStringContainsString('Média da turma', $text);
    }

    // ================================================================= propostas, decisões, estados

    #[Test]
    public function the_three_states_of_a_classification_are_said_in_words_everywhere(): void
    {
        $this->decide('Carolina Nunes', '4');
        $this->decide('Ana Marques', '5');

        // Ana's decision is published; Carolina's stays confirmed; Bruno is only a proposal.
        // (Names are encrypted at rest: found through the model, never through a where.)
        $this->asTenant(function (): void {
            $ana = SchoolClass::where('label', '7.º A')->firstOrFail()->enrollments()->with('student.identity')->get()
                ->first(fn (Enrollment $candidate): bool => $candidate->student->identity->display_name === 'Ana Marques');

            Classification::query()->where('enrollment_id', $ana->getKey())->update(['status' => ClassificationStatus::Published]);
        });

        $statuses = function (EvaluationSheetDocument $document): array {
            $said = [];

            foreach ((new EvaluationSheetGrid)->build($document, new EvaluationSheetGridOptions)['blocks'] as $block) {
                $position = array_search('status', array_column($block['columns'], 'kind'), true);

                if ($position === false) {
                    continue;
                }

                foreach ($block['rows'] as $row) {
                    $said[$row['name']] = $row['cells'][$position]['text'];
                }
            }

            return $said;
        };

        $said = $statuses($this->liveDocument());

        $this->assertSame('Publicada', $said['Ana Marques']);
        $this->assertSame('Confirmada', $said['Carolina Nunes']);
        $this->assertSame('Proposta', $said['Bruno Teixeira']);
        $this->assertSame('—', $said['Diogo Ferreira'], 'no classification at all: a dash, not a state');

        // The same words on paper, and in a kept moment.
        $exportUlid = $this->keep('Momento');
        $this->assertSame($said, $statuses($this->snapshotDocument($exportUlid)));

        $text = implode(' ', $this->pages((string) $this->actingAs($this->teacher)
            ->get("/classes/{$this->classUlid()}/pauta-avaliacao/impressao/{$this->periodUlid()}/pdf")->assertOk()->getContent()));

        foreach (['Publicada', 'Confirmada', 'Proposta'] as $word) {
            $this->assertStringContainsString($word, $text);
        }
    }

    // ================================================================= turma sem perfil

    #[Test]
    public function a_class_without_a_profile_still_makes_the_preview_the_pdf_and_the_charts(): void
    {
        $classUlid = $this->asTenant(function (): string {
            $demo = SchoolClass::where('label', '7.º A')->firstOrFail();
            $organization = $this->teacher->personalOrganization();
            $class = SchoolClass::create([
                'academic_year_id' => $demo->academic_year_id, 'subject_id' => $demo->subject_id,
                'label' => '7.º Sem Perfil', 'grade_level' => '7.º', 'status' => 'active',
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            foreach ([1 => 'Aluno Sem Perfil Um', 2 => 'Aluno Sem Perfil Dois'] as $number => $name) {
                $student = Student::factory()->recycle($organization)->create();
                StudentIdentity::create(['student_id' => $student->getKey(), 'organization_id' => $organization->getKey(), 'display_name' => $name]);
                Enrollment::factory()->recycle($organization)->create([
                    'class_id' => $class->getKey(), 'student_id' => $student->getKey(), 'class_number' => $number, 'enrolled_on' => '2026-09-14',
                ]);
            }

            return $class->ulid;
        });

        $base = "/classes/{$classUlid}/pauta-avaliacao/impressao/{$this->periodUlid()}";
        $page = null;
        $this->actingAs($this->teacher)->get($base.'?graficos=1')->assertOk()->assertInertia(function ($inertia) use (&$page): void {
            $page = $inertia->toArray()['props']['document'];
        });

        // The Pauta shows no sheet for such a class — and says why; so does the grid, instead of «no students».
        $this->assertTrue($page['is_empty']);
        $this->assertSame(0, $page['summary']['domains']);
        $this->assertSame('Esta turma não tem perfil de avaliação associado, por isso não há pauta a mostrar.', $page['empty_message']);
        $this->assertSame(['result', 'proposal', 'assigned', 'status', 'coverage'], array_column($page['blocks'][0]['columns'], 'kind'));
        $this->assertFalse($page['charts']['proposals']['available'], 'no scale: no level chart, and no guess');
        $this->assertSame([], $page['charts']['means']['bars'], 'nobody has a result');
        $this->assertNull($page['charts']['assigned']);

        $pdf = (string) $this->actingAs($this->teacher)->get($base.'/pdf?graficos=1')->assertOk()->getContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('não tem perfil de avaliação associado', implode(' ', $this->pages($pdf)));
    }

    // ================================================================= o que um nome pode conter

    #[Test]
    public function what_a_name_can_contain_stays_text_in_the_page_the_pdf_and_the_audit(): void
    {
        $hostile = '<b>Zé</b> & "Cia" <script>alert(1)</script>';
        $long = 'Maria Francisca de Assis Antónia Rodrigues da Conceição Vasconcelos de Albuquerque e Sousa Pereira';

        // Names are encrypted at rest: found through the model, never through a where.
        $this->asTenant(function () use ($hostile, $long): void {
            foreach (StudentIdentity::all() as $identity) {
                match ($identity->display_name) {
                    'Diogo Ferreira' => $identity->update(['display_name' => $hostile]),
                    'Filipe Andrade' => $identity->update(['display_name' => $long]),
                    default => null,
                };
            }
        });

        $base = "/classes/{$this->classUlid()}/pauta-avaliacao/impressao/{$this->periodUlid()}";
        $page = null;
        $this->actingAs($this->teacher)->get($base)->assertOk()->assertInertia(function ($inertia) use (&$page): void {
            $page = $inertia->toArray()['props']['document'];
        });

        $names = array_column($page['blocks'][0]['rows'], 'name');
        $this->assertContains($hostile, $names, 'the page carries the name as text; Vue escapes it');
        $this->assertContains($long, $names);

        $text = implode(' ', $this->pages((string) $this->actingAs($this->teacher)->get($base.'/pdf')->assertOk()->getContent()));

        // The PDF prints the markup as letters — it is not interpreted — and a long name is not lost.
        $this->assertStringContainsString('<b>Zé</b>', $text);
        $this->assertStringContainsString('<script>alert(1)</script>', $text);
        $this->assertStringContainsString('Maria Francisca de Assis', $text);
        $this->assertStringContainsString('de Albuquerque e Sousa Pereira', $text);

        // The trail never carries a name, hostile or not.
        foreach (AuditEvent::where('event', 'evaluation_sheets.grid_document.exported')->get() as $event) {
            $this->assertStringNotContainsString('Zé', (string) json_encode($event->properties, JSON_UNESCAPED_UNICODE));
            $this->assertStringNotContainsString('Zé', (string) $event->summary);
        }
    }

    // ================================================================= desempenho

    /**
     * The queries one request costs, to the byte of its own answer.
     */
    private function queriesOf(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->teacher)->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function enrolUpTo(int $total): void
    {
        $this->asTenant(function () use ($total): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $organization = $this->teacher->personalOrganization();
            $existing = Enrollment::query()->where('class_id', $class->getKey())->count();

            for ($number = $existing + 1; $number <= $total; $number++) {
                $student = Student::factory()->recycle($organization)->create();
                StudentIdentity::create(['student_id' => $student->getKey(), 'organization_id' => $organization->getKey(), 'display_name' => "Aluno de Teste {$number}"]);
                Enrollment::factory()->recycle($organization)->create([
                    'class_id' => $class->getKey(), 'student_id' => $student->getKey(), 'class_number' => $number, 'enrolled_on' => '2026-09-14',
                ]);
            }
        });
    }

    #[Test]
    public function the_preview_and_the_pdf_cost_the_same_number_of_queries_for_six_students_and_for_thirty(): void
    {
        $exportUlid = $this->keep('Momento');
        $live = "/classes/{$this->classUlid()}/pauta-avaliacao/impressao/{$this->periodUlid()}?graficos=1";
        $snapshot = "/classes/{$this->classUlid()}/pauta-avaliacao/historico/{$exportUlid}/impressao?graficos=1";

        // Warm every one-off (the container, the entitlement and the session caches), so only the class is measured.
        foreach ([$live, $live.'&x=1', $snapshot] as $warm) {
            $this->queriesOf($warm);
        }

        $small = [
            'live preview' => $this->queriesOf($live),
            'live pdf' => $this->queriesOf(str_replace('?graficos=1', '/pdf?graficos=1', $live)),
        ];

        $this->enrolUpTo(30);

        $large = [
            'live preview' => $this->queriesOf($live),
            'live pdf' => $this->queriesOf(str_replace('?graficos=1', '/pdf?graficos=1', $live)),
        ];

        foreach ($small as $what => $count) {
            $this->assertSame($count, $large[$what], "{$what}: six students cost {$count} queries and thirty cost {$large[$what]}: the cost grows with the class.");
        }

        // A kept moment is its payload: the same few queries (the record, the class, the session) whatever its size.
        $keptSmall = $this->queriesOf($snapshot);
        $keptBig = $this->keep('Momento grande');
        $this->assertSame($keptSmall, $this->queriesOf("/classes/{$this->classUlid()}/pauta-avaliacao/historico/{$keptBig}/impressao?graficos=1"));
    }
}
