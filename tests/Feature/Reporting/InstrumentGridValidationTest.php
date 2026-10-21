<?php

namespace Tests\Feature\Reporting;

use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\ResultsAnalysisNote;
use App\Models\ResultState;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\Analysis\BuildResultsAnalysis;
use App\Services\Assessment\CompleteCorrection;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\RecordScores;
use App\Services\Documents\Pdf\LetterheadLayout;
use App\Services\Reporting\ClassDocuments\InstrumentGrid;
use App\Services\Reporting\ClassDocuments\InstrumentGridOptions;
use App\Services\StudentEnrollmentService;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\InstrumentTypesSeeder;
use Database\Seeders\SystemScalesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

/**
 * B6 — the final validation of the printable instrument grid (Fatia B).
 *
 * The earlier steps tested each piece where it was built; this file holds what the
 * audit's matrix asked for and no step covered end to end: the same figures on the
 * correction grid, in the preview and on the PDF; every absence rule; every scale; a
 * split question, a bonus past 100 % and a deduction; reopening; support
 * impersonation; an empty class, a class without a profile, a hostile title; and
 * what the PDF — not only the props — must never carry.
 */
class InstrumentGridValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();

        $this->seed(SystemScalesSeeder::class);
        $this->seed(InstrumentTypesSeeder::class);
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

    // ------------------------------------------------------------- fixtures

    /**
     * A class on a scale, domains «A» and «B» at 60/40, with `$students` enrolled.
     *
     * @return array{0: SchoolClass, 1: Domain, 2: Domain}
     */
    private function scenario(string $scale = 'Escala 1 a 5', int $students = 6, string $label = '8.º A', ?string $absenceMode = null, bool $profile = true): array
    {
        return $this->asTenant(function () use ($scale, $students, $label, $absenceMode, $profile): array {
            $year = AcademicYear::first() ?? AcademicYear::create([
                'label' => '2026/2027', 'starts_on' => '2026-09-14', 'ends_on' => '2027-06-30',
                'status' => 'active', 'country_code' => 'PT',
            ]);
            $year->periods()->firstOrCreate(['sequence' => 1], [
                'label' => '1.º Período', 'kind' => 'term',
                'starts_on' => '2026-09-14', 'ends_on' => '2026-12-19', 'status' => 'open',
            ]);
            $subject = Subject::factory()->recycle($this->organization)->create(['name' => 'Disciplina '.$label]);

            $versionId = null;
            if ($profile) {
                $built = app(ProfileBuilder::class)->create(
                    ['academic_year_id' => $year->id, 'subject_id' => $subject->id, 'name' => 'Perfil '.$label, 'description' => 'Cenário.'],
                    Scale::where('name', $scale)->firstOrFail()->id,
                    [['name' => 'A '.$label, 'weight' => 60], ['name' => 'B '.$label, 'weight' => 40]],
                    ['8.º'],
                );
                $version = app(ActivateProfileVersion::class)->activate($built->draftVersion(), $this->teacher);
                $versionId = $version->id;

                if ($absenceMode !== null) {
                    // A frozen version refuses to be edited through the model, and rightly:
                    // this test changes the rule underneath it on purpose.
                    DB::table('assessment_profile_versions')->where('id', $versionId)->update(['absence_mode' => $absenceMode]);
                }
            }

            $class = SchoolClass::create([
                'academic_year_id' => $year->id, 'subject_id' => $subject->id,
                'label' => $label, 'grade_level' => '8.º', 'status' => 'active',
                'assessment_profile_version_id' => $versionId,
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            $service = app(StudentEnrollmentService::class);
            foreach (range(1, $students) as $n) {
                $service->enrollNew($class, ['name' => "Aluno {$n}", 'class_number' => $n, 'enrolled_on' => '2026-09-14']);
            }

            return [
                $class,
                $profile ? Domain::where('name', 'A '.$label)->firstOrFail() : new Domain,
                $profile ? Domain::where('name', 'B '.$label)->firstOrFail() : new Domain,
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $attributes
     */
    private function make(SchoolClass $class, array $items, array $attributes = []): Instrument
    {
        return $this->asTenant(fn (): Instrument => app(InstrumentBuilder::class)->create($class, [
            'academic_period_id' => $class->academicYear->periods()->firstOrFail()->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Teste', 'applied_on' => '2026-10-15', 'status' => 'in_correction',
            'counts_toward_classification' => true, 'purpose' => 'summative', 'total_points' => 20,
            ...$attributes,
        ], $items));
    }

    /**
     * @param  array<int, array<string, array{0: ResultState, 1: float|null}>>  $marks  class number => [item code => [state, points]]
     */
    private function score(Instrument $instrument, SchoolClass $class, array $marks): void
    {
        $this->asTenant(function () use ($instrument, $class, $marks): void {
            $items = $instrument->items()->get()->keyBy('code');
            $enrollments = $class->enrollments()->get()->keyBy('class_number');
            $cells = [];

            foreach ($marks as $number => $byCode) {
                foreach ($byCode as $code => [$state, $points]) {
                    $cells[] = [
                        'enrollment_id' => $enrollments[$number]->id,
                        'instrument_item_id' => $items[$code]->id,
                        'result_state' => $state->value,
                        ...($points === null ? [] : ['points_earned' => $points]),
                    ];
                }
            }

            app(RecordScores::class)->save($instrument, $cells, $this->teacher);
        });
    }

    private function conclude(Instrument $instrument): Instrument
    {
        return $this->asTenant(fn (): Instrument => app(CompleteCorrection::class)->complete(Instrument::findOrFail($instrument->id), $this->teacher));
    }

    /** @return array<string, mixed> */
    private function document(Instrument $instrument, string $query = ''): array
    {
        parse_str($query, $parsed);

        return $this->asTenant(fn (): array => app(InstrumentGrid::class)->build(Instrument::findOrFail($instrument->id), InstrumentGridOptions::fromQuery($parsed)));
    }

    /**
     * The text of a PDF, joined.
     */
    private function pdfText(Instrument $instrument, string $query = ''): string
    {
        $bytes = (string) $this->actingAs($this->teacher)->get("/instruments/{$instrument->ulid}/impressao/pdf{$query}")->assertOk()->getContent();

        return implode(' ', array_map(
            fn ($page): string => preg_replace('/\s+/u', ' ', $page->getText()) ?? '',
            (new Parser)->parseContent($bytes)->getPages(),
        ));
    }

    /** The percentage as the screen writes it, by an independent route. */
    private function screenPercent(array $cell): ?string
    {
        $raw = $cell['value_precise'] ?? $cell['value'];

        return $raw === null ? null : number_format((float) $raw, $cell['value_precise'] !== null ? 2 : 1, ',', '').' %';
    }

    /** Two questions, A and B, 10 points each, and the marks of the first `$rows` students. */
    private function twoQuestions(SchoolClass $class, Domain $a, Domain $b, array $marks, string $title = 'Teste', array $attributes = []): Instrument
    {
        $instrument = $this->make($class, [
            ['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10, 'domains' => [['domain_id' => $a->id, 'allocation_percent' => 100]]],
            ['code' => 'Q2', 'label' => 'Q2', 'points_possible' => 10, 'domains' => [['domain_id' => $b->id, 'allocation_percent' => 100]]],
        ], ['title' => $title, ...$attributes]);
        $this->score($instrument, $class, $marks);

        return $instrument;
    }

    // ============================================== paridade: grelha = pré-vis = PDF

    #[Test]
    public function the_correction_grid_the_preview_and_the_pdf_say_the_same_for_every_student(): void
    {
        [$class, $a, $b] = $this->scenario(students: 8);
        $instrument = $this->twoQuestions($class, $a, $b, [
            1 => ['Q1' => [ResultState::Assessed, 10.0], 'Q2' => [ResultState::Assessed, 4.0]],
            2 => ['Q1' => [ResultState::Assessed, 8.0], 'Q2' => [ResultState::Assessed, 6.0]],
            3 => ['Q1' => [ResultState::Assessed, 6.0], 'Q2' => [ResultState::Assessed, 8.0]],
            4 => ['Q1' => [ResultState::Assessed, 9.0], 'Q2' => [ResultState::Assessed, 5.0]],
            5 => ['Q1' => [ResultState::Assessed, 7.0], 'Q2' => [ResultState::Assessed, 7.0]],
            6 => ['Q1' => [ResultState::Absent, null], 'Q2' => [ResultState::Absent, null]],
            7 => ['Q1' => [ResultState::AbsentJustified, null], 'Q2' => [ResultState::AbsentJustified, null]],
            8 => ['Q1' => [ResultState::Exempt, null], 'Q2' => [ResultState::Assessed, 3.0]],
        ]);
        $this->conclude($instrument);

        // 1. The correction grid's own figures (what the teacher sees while correcting).
        $screen = null;
        $this->actingAs($this->teacher)->get("/instruments/{$instrument->ulid}")->assertInertia(function ($page) use (&$screen): void {
            $screen = $page->toArray()['props'];
        });

        // 2. The preview's document.
        $preview = null;
        $this->actingAs($this->teacher)->get("/instruments/{$instrument->ulid}/impressao?graficos=1")->assertInertia(function ($page) use (&$preview): void {
            $preview = $page->toArray()['props']['document'];
        });

        // 3. The PDF's text.
        $pdf = $this->pdfText($instrument, '?graficos=1');

        $enrollmentByNumber = collect($screen['students'])->pluck('enrollment_id', 'class_number');
        $rows = [];
        foreach ($preview['blocks'] as $block) {
            foreach ($block['rows'] as $row) {
                $rows[$row['number']] = $row;
            }
        }
        $kinds = array_column($preview['blocks'][0]['columns'], 'kind');
        $resultColumn = array_search('result', $kinds, true);

        $this->assertCount(8, $rows);

        foreach ($rows as $number => $row) {
            $official = $screen['official']['students'][$enrollmentByNumber[(int) $number]];
            $expected = $this->screenPercent($official['global']) ?? $official['status_label'];

            // The preview carries what the correction grid shows (a partial mark aside)…
            $printed = $row['cells'][$resultColumn]['text'];
            $this->assertSame($expected.($official['global']['is_partial'] && $this->screenPercent($official['global']) !== null ? ' *' : ''), $printed, "student {$number}");

            // … and the PDF draws that very text.
            $this->assertStringContainsString($row['name'], $pdf);
            $this->assertStringContainsString(rtrim($printed, ' *'), $pdf, "student {$number} on paper");
        }
    }

    // ============================================== regras de ausência (4 modos)

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function absenceModes(): iterable
    {
        // mode => [what an UNJUSTIFIED absence reads, what a JUSTIFIED absence reads]
        yield 'exclude_all' => ['exclude_all', 'Ausente', 'Ausência justificada'];
        yield 'exclude_all_warn' => ['exclude_all_warn', 'Ausente', 'Ausência justificada'];
        yield 'zero_all' => ['zero_all', '0,0 %', '0,0 %'];
        yield 'zero_unjustified_only' => ['zero_unjustified_only', '0,0 %', 'Ausência justificada'];
    }

    #[Test]
    #[DataProvider('absenceModes')]
    public function the_grid_reads_an_absence_the_way_the_profile_says_and_agrees_with_resultados(string $mode, string $unjustified, string $justified): void
    {
        [$class, $a, $b] = $this->scenario(absenceMode: $mode);
        $instrument = $this->twoQuestions($class, $a, $b, [
            1 => ['Q1' => [ResultState::Absent, null], 'Q2' => [ResultState::Absent, null]],
            2 => ['Q1' => [ResultState::AbsentJustified, null], 'Q2' => [ResultState::AbsentJustified, null]],
            3 => ['Q1' => [ResultState::Assessed, 8.0], 'Q2' => [ResultState::Assessed, 8.0]],
            4 => ['Q1' => [ResultState::Assessed, 6.0], 'Q2' => [ResultState::Assessed, 6.0]],
            5 => ['Q1' => [ResultState::Assessed, 9.0], 'Q2' => [ResultState::Assessed, 9.0]],
            6 => ['Q1' => [ResultState::Assessed, 7.0], 'Q2' => [ResultState::Assessed, 7.0]],
        ]);
        $this->conclude($instrument);

        $document = $this->document($instrument, 'graficos=1');
        $official = $this->asTenant(fn (): array => app(BuildResultsAnalysis::class)->officialCells(Instrument::findOrFail($instrument->id)));
        $ids = $this->asTenant(fn () => $class->enrollments()->pluck('id', 'class_number'));

        $kinds = array_column($document['blocks'][0]['columns'], 'kind');
        $position = array_search('result', $kinds, true);
        $rows = array_column($document['blocks'][0]['rows'], null, 'number');

        $this->assertSame($unjustified, $rows['1']['cells'][$position]['text'], 'absence');
        $this->assertSame($justified, $rows['2']['cells'][$position]['text'], 'justified absence');

        // Every row is what the engine's own cell is, whatever the rule.
        foreach ($rows as $number => $row) {
            $cell = $official['students'][$ids[(int) $number]];
            $this->assertSame($this->screenPercent($cell['global']) ?? $cell['status_label'], rtrim($row['cells'][$position]['text'], ' *'), "student {$number}");
        }

        // The chart counts those that have a result under THAT rule — the analyzer's classified.
        $analysis = $this->asTenant(fn (): array => app(BuildResultsAnalysis::class)->forInstrument(Instrument::findOrFail($instrument->id), true)['dimensions'][0]['analysis']);
        $this->assertSame((int) $analysis['classified'], $document['charts']['distribution']['n']);
    }

    // ============================================== um item repartido 60/40

    #[Test]
    public function a_question_split_between_two_domains_counts_whole_in_the_raw_score_and_by_allocation_in_the_domains(): void
    {
        [$class, $a, $b] = $this->scenario();
        $instrument = $this->make($class, [
            ['code' => 'Q1', 'label' => 'Dividida', 'points_possible' => 10, 'domains' => [
                ['domain_id' => $a->id, 'allocation_percent' => 60], ['domain_id' => $b->id, 'allocation_percent' => 40],
            ]],
            ['code' => 'Q2', 'label' => 'Só B', 'points_possible' => 10, 'domains' => [['domain_id' => $b->id, 'allocation_percent' => 100]]],
        ]);
        $this->score($instrument, $class, array_fill(1, 6, ['Q1' => [ResultState::Assessed, 5.0], 'Q2' => [ResultState::Assessed, 10.0]]));
        $this->conclude($instrument);

        $document = $this->document($instrument, 'graficos=1');
        $official = $this->asTenant(fn (): array => app(BuildResultsAnalysis::class)->officialCells(Instrument::findOrFail($instrument->id)));
        $first = $this->asTenant(fn () => $class->enrollments()->where('class_number', 1)->firstOrFail()->id);

        $kinds = array_column($document['blocks'][0]['columns'], 'kind');
        $row = $document['blocks'][0]['rows'][0];

        // The raw score is points over quota, whole: 5 + 10 of 20.
        $this->assertSame('15/20', $row['cells'][array_search('raw', $kinds, true)]['text']);

        // The domains are the engine's, by allocation — not points ÷ quota.
        $cells = $official['students'][$first]['domains'];
        $domainPositions = array_keys(array_filter($kinds, fn (string $kind): bool => $kind === 'domain'));
        foreach (array_values($cells) as $index => $cell) {
            $this->assertSame($this->screenPercent($cell) ?? '—', rtrim($row['cells'][$domainPositions[$index]]['text'], ' *'));
        }
        $this->assertNotSame('75,0 %', $row['cells'][array_search('result', $kinds, true)]['text'], 'the engine weights by domain, so the global is not 15/20');

        // And the chart by domain is those same means.
        $this->assertCount(3, $document['charts']['means']['bars']);
    }

    // ============================================== escala percentagem

    #[Test]
    public function a_percentage_scale_has_no_levels_and_is_distributed_in_ten_classes(): void
    {
        [$class, $a, $b] = $this->scenario('Percentagem (0 a 100)');
        $instrument = $this->twoQuestions($class, $a, $b, array_fill(1, 6, ['Q1' => [ResultState::Assessed, 6.0], 'Q2' => [ResultState::Assessed, 8.0]]));
        $this->conclude($instrument);

        $document = $this->document($instrument, 'graficos=1');

        $this->assertNotContains('level', array_column($document['blocks'][0]['columns'], 'kind'));
        $this->assertContains('result', array_column($document['blocks'][0]['columns'], 'kind'));
        $this->assertSame('Por intervalos de 10 pontos percentuais', $document['charts']['distribution']['basis']);
        $this->assertCount(10, $document['charts']['distribution']['bars']);
    }

    // ============================================== bónus acima de 100 % e deduções

    #[Test]
    public function a_result_above_one_hundred_percent_is_printed_as_it_is_and_the_chart_does_not_hide_it(): void
    {
        [$class, $a] = $this->scenario();
        $instrument = $this->make($class, [
            ['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10, 'domains' => [['domain_id' => $a->id, 'allocation_percent' => 100]]],
            ['code' => 'B1', 'label' => 'Bónus', 'points_possible' => 2, 'is_bonus' => true, 'domains' => [['domain_id' => $a->id, 'allocation_percent' => 100]]],
        ], ['total_points' => 10, 'allow_bonus' => true]);
        $this->score($instrument, $class, [
            1 => ['Q1' => [ResultState::Assessed, 10.0], 'B1' => [ResultState::Assessed, 2.0]],
            2 => ['Q1' => [ResultState::Assessed, 8.0], 'B1' => [ResultState::Assessed, 0.0]],
            3 => ['Q1' => [ResultState::Assessed, 7.0], 'B1' => [ResultState::Assessed, 0.0]],
            4 => ['Q1' => [ResultState::Assessed, 6.0], 'B1' => [ResultState::Assessed, 0.0]],
            5 => ['Q1' => [ResultState::Assessed, 9.0], 'B1' => [ResultState::Assessed, 1.0]],
            6 => ['Q1' => [ResultState::Assessed, 5.0], 'B1' => [ResultState::Assessed, 0.0]],
        ]);
        $this->conclude($instrument);

        $document = $this->document($instrument, 'graficos=1');
        $kinds = array_column($document['blocks'][0]['columns'], 'kind');

        // 10 + 2 over 10 is 120 %: the sheet says 120,0 %, and the raw score says 12/10.
        $this->assertSame('120,0 %', $document['blocks'][0]['rows'][0]['cells'][array_search('result', $kinds, true)]['text']);
        $this->assertSame('12/10', $document['blocks'][0]['rows'][0]['cells'][array_search('raw', $kinds, true)]['text']);

        // The distribution cannot lose the student that no level of the scale reaches:
        // the bars and the note together account for every student with a result.
        $chart = $document['charts']['distribution'];
        $placed = array_sum(array_map(fn (array $bar): int => (int) explode(' · ', $bar['value_text'])[0], $chart['bars']));

        $this->assertSame(6, $chart['n']);
        $this->assertSame(5, $placed, 'the 120 % result is in no band of the scale');
        $this->assertNotNull($chart['unplaced_note'], 'and the chart says so instead of adding up to less than the class');
        $this->assertStringContainsString('1 resultado', (string) $chart['unplaced_note']);
        $this->assertStringContainsString((string) $chart['unplaced_note'], $this->pdfText($instrument, '?graficos=1'), 'the PDF says it too');

        // The chart by question: the bonus question is 3 points earned over 6 × 2 possible.
        $bonus = array_column($document['charts']['questions']['bars'], null, 'label')['B1'];
        $this->assertSame('25,0 %', $bonus['value_text']);
        // The mean of the six results — 120, 80, 70, 60, 100 and 50 — is 80, on an absolute axis.
        $mean = $document['charts']['means']['bars'][0];
        $this->assertSame('80,0 %', $mean['value_text']);
        $this->assertSame('80.0', $mean['width']);
    }

    #[Test]
    public function a_deduction_is_a_negative_mark_in_the_raw_score_and_has_no_bar_by_question(): void
    {
        [$class, $a] = $this->scenario();
        $instrument = $this->make($class, [
            ['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10, 'domains' => [['domain_id' => $a->id, 'allocation_percent' => 100]]],
            ['code' => 'D1', 'label' => 'Dedução', 'points_possible' => 0, 'is_bonus' => true, 'domains' => [['domain_id' => $a->id, 'allocation_percent' => 100]]],
        ], ['total_points' => 10, 'allow_bonus' => true]);
        $this->score($instrument, $class, array_fill(1, 6, ['Q1' => [ResultState::Assessed, 8.0], 'D1' => [ResultState::Assessed, -1.5]]));
        $this->conclude($instrument);

        $document = $this->document($instrument, 'graficos=1');
        $kinds = array_column($document['blocks'][0]['columns'], 'kind');

        $this->assertSame('6,5/10', $document['blocks'][0]['rows'][0]['cells'][array_search('raw', $kinds, true)]['text']);
        $this->assertSame('-1,5', $document['blocks'][0]['rows'][0]['cells'][1]['text'], 'the deduction is shown with its sign');
        $deduction = array_column($document['charts']['questions']['bars'], null, 'label')['D1'];
        $this->assertNull($deduction['width']);
    }

    // ============================================== estados do instrumento

    #[Test]
    public function reopening_a_correction_takes_the_charts_and_the_concluded_mark_away_again(): void
    {
        [$class, $a, $b] = $this->scenario();
        $instrument = $this->twoQuestions($class, $a, $b, array_fill(1, 6, ['Q1' => [ResultState::Assessed, 8.0], 'Q2' => [ResultState::Assessed, 7.0]]));
        $this->conclude($instrument);

        $concluded = $this->document($instrument, 'graficos=1');
        $this->assertNotNull($concluded['charts']);
        $this->assertFalse($concluded['state']['is_provisional']);

        $this->asTenant(fn () => app(CompleteCorrection::class)->reopen(Instrument::findOrFail($instrument->id), $this->teacher));

        $reopened = $this->document($instrument, 'graficos=1');
        $this->assertNull($reopened['charts']);
        $this->assertFalse($reopened['charts_available']);
        $this->assertTrue($reopened['state']['is_provisional']);
        $this->assertStringNotContainsString('Distribuição dos resultados', $this->pdfText($instrument, '?graficos=1'));
    }

    #[Test]
    public function an_archived_instrument_is_provisional_and_a_published_one_is_concluded(): void
    {
        [$class, $a, $b] = $this->scenario();
        $instrument = $this->twoQuestions($class, $a, $b, array_fill(1, 6, ['Q1' => [ResultState::Assessed, 8.0], 'Q2' => [ResultState::Assessed, 7.0]]));
        $this->conclude($instrument);

        $this->asTenant(fn () => Instrument::findOrFail($instrument->id)->forceFill(['status' => 'archived'])->save());
        $archived = $this->document($instrument, 'graficos=1');
        $this->assertTrue($archived['state']['is_provisional'], 'archiving is not a statement that the correction was finished');
        $this->assertNull($archived['charts']);

        $this->asTenant(fn () => Instrument::findOrFail($instrument->id)->forceFill(['status' => 'published'])->save());
        $published = $this->document($instrument, 'graficos=1');
        $this->assertFalse($published['state']['is_provisional']);
        $this->assertNotNull($published['charts']);
    }

    #[Test]
    public function a_concluded_diagnostic_has_charts_and_says_it_is_a_diagnostic_on_screen_and_on_paper(): void
    {
        [$class, $a, $b] = $this->scenario();
        $instrument = $this->make($class, [
            ['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10, 'domains' => [['domain_id' => $a->id, 'allocation_percent' => 100]]],
            ['code' => 'Q2', 'label' => 'Q2', 'points_possible' => 10, 'domains' => [['domain_id' => $b->id, 'allocation_percent' => 100]]],
        ], ['title' => 'Diagnóstico', 'purpose' => 'diagnostic', 'counts_toward_classification' => false]);
        $this->score($instrument, $class, array_fill(1, 6, ['Q1' => [ResultState::Assessed, 6.0], 'Q2' => [ResultState::Assessed, 5.0]]));
        $this->conclude($instrument);

        $document = $this->document($instrument, 'graficos=1');
        $pdf = $this->pdfText($instrument, '?graficos=1');

        $this->assertTrue($document['is_diagnostic']);
        $this->assertNotNull($document['charts']);
        $this->assertStringContainsString('Avaliação diagnóstica', $pdf);
        $this->assertStringContainsString('Finalidade diagnóstica', $pdf);
        $this->assertStringContainsString('Distribuição dos resultados', $pdf);
    }

    // ============================================== situações-limite

    #[Test]
    public function a_class_the_instrument_does_not_cover_gives_an_empty_document_in_the_preview_and_the_pdf(): void
    {
        [$class, $a, $b] = $this->scenario(students: 3);
        // Everyone entered after the test: nobody is covered.
        $this->asTenant(fn () => Enrollment::where('class_id', $class->id)->update(['enrolled_on' => '2026-12-01']));
        $instrument = $this->twoQuestions($class, $a, $b, []);

        $document = $this->document($instrument);

        $this->assertTrue($document['is_empty']);
        $this->assertSame(3, $document['summary']['excluded']);
        $this->assertSame(0, $document['summary']['students']);

        $pdf = $this->pdfText($instrument);
        $this->assertStringContainsString('Não há alunos abrangidos por este elemento de avaliação.', $pdf);
        $this->assertStringContainsString('3 alunos não abrangidos', $pdf);
        $this->assertStringNotContainsString('Aluno 1', $pdf);
    }

    #[Test]
    public function a_class_without_an_assessment_profile_still_gives_a_document_that_says_so(): void
    {
        [$class] = $this->scenario(profile: false);
        $instrument = $this->make($class, [
            ['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10],
            ['code' => 'Q2', 'label' => 'Q2', 'points_possible' => 10],
        ]);
        $this->score($instrument, $class, [1 => ['Q1' => [ResultState::Assessed, 8.0], 'Q2' => [ResultState::Assessed, 6.0]]]);

        $document = $this->document($instrument, 'graficos=1');

        $this->assertNotContains('level', array_column($document['blocks'][0]['columns'], 'kind'));
        $this->assertContains('Esta turma não tem um perfil de avaliação ativo — não é possível calcular resultados para este instrumento.', $document['notes']);
        $this->assertNull($document['charts'], 'in correction: no charts');
        // The raw score does not depend on a profile.
        $kinds = array_column($document['blocks'][0]['columns'], 'kind');
        $this->assertSame('14/20', $document['blocks'][0]['rows'][0]['cells'][array_search('raw', $kinds, true)]['text']);

        $this->assertStringContainsString('perfil de avaliação', $this->pdfText($instrument));
    }

    #[Test]
    public function a_student_without_a_class_number_is_in_the_grid_with_a_dash_and_on_the_pdf(): void
    {
        [$class, $a, $b] = $this->scenario(students: 3);
        $this->asTenant(fn () => app(StudentEnrollmentService::class)->enrollNew($class, ['name' => 'Aluno Sem Número', 'class_number' => null, 'enrolled_on' => '2026-09-14']));
        $instrument = $this->twoQuestions($class, $a, $b, [1 => ['Q1' => [ResultState::Assessed, 8.0], 'Q2' => [ResultState::Assessed, 6.0]]]);

        $document = $this->document($instrument);
        $row = collect($document['blocks'][0]['rows'])->firstWhere('name', 'Aluno Sem Número');

        $this->assertSame('—', $row['number']);
        $this->assertStringContainsString('Aluno Sem Número', $this->pdfText($instrument));
    }

    #[Test]
    public function a_hostile_title_or_description_is_text_on_the_pdf_never_markup(): void
    {
        [$class, $a, $b] = $this->scenario(students: 3);
        $instrument = $this->make($class, [
            ['code' => 'Q1', 'label' => '<img src=x onerror=alert(1)> & "q"', 'points_possible' => 10, 'domains' => [['domain_id' => $a->id, 'allocation_percent' => 100]]],
            ['code' => 'Q2', 'label' => 'Q2', 'points_possible' => 10, 'domains' => [['domain_id' => $b->id, 'allocation_percent' => 100]]],
        ], ['title' => '<script>alert(1)</script> Teste & "T"']);

        $document = $this->document($instrument);
        $html = View::make('instruments.grid-document', ['document' => $document, 'layout' => LetterheadLayout::Standard])->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Teste &amp; &quot;T&quot;', $html);

        // The structure carries the raw text (the preview escapes it as text); the PDF prints it as text.
        $this->assertSame('<script>alert(1)</script> Teste & "T"', $document['title']);
        $this->assertStringContainsString('Teste & "T"', $this->pdfText($instrument));
    }

    // ============================================== privacidade, também no PDF

    #[Test]
    public function the_pdf_never_carries_a_free_text_note_a_school_number_a_birth_date_or_a_pseudonym(): void
    {
        [$class, $a, $b] = $this->scenario(students: 2);
        $this->asTenant(function () use ($class): void {
            app(StudentEnrollmentService::class)->enrollNew($class, [
                'name' => 'Aluna Privada', 'class_number' => 9, 'enrolled_on' => '2026-09-14',
                'school_number' => '99887766', 'birth_date' => '2011-03-04',
            ]);
        });
        $instrument = $this->twoQuestions($class, $a, $b, [
            9 => ['Q1' => [ResultState::Absent, null], 'Q2' => [ResultState::Absent, null]],
            1 => ['Q1' => [ResultState::Assessed, 8.0], 'Q2' => [ResultState::Assessed, 6.0]],
        ]);
        $this->asTenant(function () use ($instrument): void {
            StudentItemScore::where('instrument_id', $instrument->id)->where('result_state', ResultState::Absent->value)->update(['state_reason' => 'MOTIVO-DE-SAUDE']);
            Instrument::findOrFail($instrument->id)->forceFill(['internal_notes' => 'NOTA-INTERNA-DO-PROFESSOR'])->save();
            ResultsAnalysisNote::create(['context_kind' => 'instrument', 'instrument_id' => $instrument->id, 'body' => 'OBSERVACAO-PRIVADA', 'lock_version' => 0, 'created_by' => $this->teacher->id, 'updated_by' => $this->teacher->id]);
        });
        $pseudonyms = $this->asTenant(fn () => Student::pluck('pseudonym_code')->all());

        foreach (['', '?graficos=1'] as $query) {
            $pdf = $this->pdfText($instrument, $query);

            foreach (['MOTIVO-DE-SAUDE', 'NOTA-INTERNA-DO-PROFESSOR', 'OBSERVACAO-PRIVADA', '99887766', '2011-03-04', '04/03/2011'] as $secret) {
                $this->assertStringNotContainsString($secret, $pdf, "«{$secret}» on the PDF {$query}");
            }
            foreach ($pseudonyms as $pseudonym) {
                $this->assertStringNotContainsString((string) $pseudonym, $pdf);
            }
            $this->assertStringContainsString('Aluna Privada', $pdf, 'the name and the mark are the document');
        }
    }

    // ============================================== autorização: personificação

    #[Test]
    public function support_impersonation_is_held_to_the_same_policy_and_the_export_is_attributed_to_the_support_person(): void
    {
        [$class, $a, $b] = $this->scenario();
        $instrument = $this->twoQuestions($class, $a, $b, [1 => ['Q1' => [ResultState::Assessed, 8.0], 'Q2' => [ResultState::Assessed, 6.0]]]);
        $support = User::factory()->create();

        // Impersonating the teacher of the class: allowed — and the trail names the support person.
        $this->withSession(['organization_id' => $this->organization->id, 'impersonator_id' => $support->id])
            ->actingAs($this->teacher)
            ->get("/instruments/{$instrument->ulid}/impressao/pdf")
            ->assertOk();

        $event = AuditEvent::where('event', 'instruments.grid_document.exported')->firstOrFail();
        $this->assertSame($support->id, $event->causer_id);
        $this->assertSame($this->teacher->id, $event->properties['acting_as_user_id']);

        // Impersonating a colleague who does not teach the class: refused, preview and PDF alike.
        $colleague = User::factory()->create();
        $this->organization->members()->syncWithoutDetaching([$colleague->id => ['joined_at' => now()]]);

        foreach (['impressao', 'impressao/pdf'] as $path) {
            $this->withSession(['organization_id' => $this->organization->id, 'impersonator_id' => $support->id])
                ->actingAs($colleague)
                ->get("/instruments/{$instrument->ulid}/{$path}")
                ->assertForbidden();
        }
    }

    #[Test]
    public function an_instrument_of_another_organization_is_not_found_even_with_the_charts_and_the_pdf_asked_for(): void
    {
        [$class, $a, $b] = $this->scenario();
        $instrument = $this->twoQuestions($class, $a, $b, array_fill(1, 6, ['Q1' => [ResultState::Assessed, 8.0], 'Q2' => [ResultState::Assessed, 6.0]]));
        $this->conclude($instrument);
        $stranger = User::factory()->create();

        foreach (['/impressao?graficos=1', '/impressao/pdf?graficos=1'] as $path) {
            $response = $this->actingAs($stranger)->get("/instruments/{$instrument->ulid}{$path}");
            $response->assertNotFound();
            $this->assertStringNotContainsString('Aluno 1', (string) $response->getContent());
        }

        $this->assertSame(0, AuditEvent::where('event', 'instruments.grid_document.exported')->count());
    }

    #[Test]
    public function a_blocked_module_closes_every_route_of_the_grid_document(): void
    {
        [$class, $a, $b] = $this->scenario();
        $instrument = $this->twoQuestions($class, $a, $b, []);

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->getKey(),
            'module_id' => Module::where('key', 'instruments')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Teste de override.',
        ]);
        app(Entitlements::class)->flush();

        foreach (['/impressao', '/impressao?graficos=1', '/impressao/pdf', '/impressao/pdf?graficos=1'] as $path) {
            $this->actingAs($this->teacher)->get("/instruments/{$instrument->ulid}{$path}")->assertForbidden();
        }
    }

    // ============================================== desempenho: o PDF também

    #[Test]
    public function the_pdf_costs_the_same_number_of_queries_for_three_students_and_for_fourteen(): void
    {
        [$small, $a, $b] = $this->scenario(students: 3, label: '8.º A');
        $smallInstrument = $this->twoQuestions($small, $a, $b, array_fill(1, 3, ['Q1' => [ResultState::Assessed, 8.0], 'Q2' => [ResultState::Assessed, 6.0]]), 'Pequeno');
        $this->conclude($smallInstrument);

        [$large, $c, $d] = $this->scenario(students: 14, label: '8.º B');
        $largeInstrument = $this->twoQuestions($large, $c, $d, array_fill(1, 14, ['Q1' => [ResultState::Assessed, 8.0], 'Q2' => [ResultState::Assessed, 6.0]]), 'Grande');
        $this->conclude($largeInstrument);

        $count = function (Instrument $instrument): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->teacher)->get("/instruments/{$instrument->ulid}/impressao/pdf?graficos=1")->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        // Warm anything the first request alone would load (entitlements, settings…).
        $count($smallInstrument);

        $this->assertSame($count($smallInstrument), $count($largeInstrument), 'eleven more students must not add a query to the PDF');
    }
}
