<?php

namespace Tests\Feature\Reporting;

use App\Domain\Assessment\CalculationEngine;
use App\Models\AcademicYear;
use App\Models\Domain;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\ResultState;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Ai\Gateway\AiGateway;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\Analysis\BuildResultsAnalysis;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\CompleteCorrection;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\RecordScores;
use App\Services\Documents\Pdf\LetterheadLayout;
use App\Services\Reporting\ClassDocuments\InstrumentGrid;
use App\Services\Reporting\ClassDocuments\InstrumentGridOptions;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\InstrumentTypesSeeder;
use Database\Seeders\SystemScalesSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

/**
 * The factual charts of a concluded instrument's grid (B5).
 *
 * What these hold: the charts exist only for a concluded correction; the
 * distribution and the means are the very figures Resultados shows (the analyzer's,
 * not a second reading); the mean by question is exact and counts an absence as
 * coverage, never as a zero; a student without a result is never a bar at zero;
 * and the PDF draws exactly the bars and the words the preview draws, because both
 * read the same array.
 */
class InstrumentGridChartsTest extends TestCase
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

    /**
     * A class on the given scale, with `$students` enrolled, and the two domains 60/40.
     *
     * @return array{0: SchoolClass, 1: Domain, 2: Domain}
     */
    private function classOn(string $scale, int $students, string $label = '8.º A'): array
    {
        return $this->asTenant(function () use ($scale, $students, $label): array {
            $year = AcademicYear::first() ?? AcademicYear::create([
                'label' => '2026/2027', 'starts_on' => '2026-09-14', 'ends_on' => '2027-06-30',
                'status' => 'active', 'country_code' => 'PT',
            ]);
            $year->periods()->firstOrCreate(['sequence' => 1], [
                'label' => '1.º Período', 'kind' => 'term',
                'starts_on' => '2026-09-14', 'ends_on' => '2026-12-19', 'status' => 'open',
            ]);
            $subject = Subject::factory()->recycle($this->organization)->create(['name' => 'Matemática '.$label]);

            $profile = app(ProfileBuilder::class)->create(
                ['academic_year_id' => $year->id, 'subject_id' => $subject->id, 'name' => 'Perfil '.$label, 'description' => 'Cenário.'],
                Scale::where('name', $scale)->firstOrFail()->id,
                [['name' => 'Números '.$label, 'weight' => 60], ['name' => 'Geometria '.$label, 'weight' => 40]],
                ['8.º'],
            );
            $version = app(ActivateProfileVersion::class)->activate($profile->draftVersion(), $this->teacher);

            $class = SchoolClass::create([
                'academic_year_id' => $year->id, 'subject_id' => $subject->id,
                'label' => $label, 'grade_level' => '8.º', 'status' => 'active',
                'assessment_profile_version_id' => $version->id,
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            $service = app(StudentEnrollmentService::class);
            foreach (range(1, $students) as $n) {
                $service->enrollNew($class, ['name' => "Aluno {$n}", 'class_number' => $n, 'enrolled_on' => '2026-09-14']);
            }

            return [
                $class,
                Domain::where('name', 'Números '.$label)->firstOrFail(),
                Domain::where('name', 'Geometria '.$label)->firstOrFail(),
            ];
        });
    }

    /**
     * An instrument of two questions (Q1 in domain A, Q2 in domain B, 10 points each).
     *
     * @param  array<int, array<int, array{0: ResultState, 1: float|null}>>  $marks  class number => [Q1 mark, Q2 mark]
     */
    private function instrument(SchoolClass $class, Domain $a, Domain $b, array $marks, bool $conclude = true, string $title = 'Teste'): Instrument
    {
        return $this->asTenant(function () use ($class, $a, $b, $marks, $conclude, $title): Instrument {
            $instrument = app(InstrumentBuilder::class)->create($class, [
                'academic_period_id' => $class->academicYear->periods()->firstOrFail()->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => $title, 'applied_on' => '2026-10-15', 'status' => 'in_correction',
                'counts_toward_classification' => true, 'purpose' => 'summative', 'total_points' => 20,
            ], [
                ['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10, 'domains' => [['domain_id' => $a->id, 'allocation_percent' => 100]]],
                ['code' => 'Q2', 'label' => 'Q2', 'points_possible' => 10, 'domains' => [['domain_id' => $b->id, 'allocation_percent' => 100]]],
            ]);

            $enrollments = $class->enrollments()->get()->keyBy('class_number');
            $items = $instrument->items()->orderBy('sequence')->get()->values();
            $cells = [];

            foreach ($marks as $number => $pair) {
                foreach ($pair as $index => [$state, $points]) {
                    $cells[] = [
                        'enrollment_id' => $enrollments[$number]->id,
                        'instrument_item_id' => $items[$index]->id,
                        'result_state' => $state->value,
                        ...($points === null ? [] : ['points_earned' => $points]),
                    ];
                }
            }
            app(RecordScores::class)->save($instrument, $cells, $this->teacher);

            if ($conclude) {
                app(CompleteCorrection::class)->complete(Instrument::findOrFail($instrument->id), $this->teacher);
            }

            return Instrument::findOrFail($instrument->id);
        });
    }

    private function grid(Instrument $instrument, string $query = 'graficos=1'): array
    {
        parse_str($query, $parsed);

        return $this->asTenant(fn (): array => app(InstrumentGrid::class)->build(
            Instrument::findOrFail($instrument->id),
            InstrumentGridOptions::fromQuery($parsed),
        ));
    }

    private function assessed(float $q1, float $q2): array
    {
        return [[ResultState::Assessed, $q1], [ResultState::Assessed, $q2]];
    }

    private function absent(): array
    {
        return [[ResultState::Absent, null], [ResultState::Absent, null]];
    }

    // ============================================================ disponibilidade

    #[Test]
    public function the_charts_are_off_by_default_and_the_option_is_read_from_the_query(): void
    {
        $this->assertFalse((new InstrumentGridOptions)->charts);
        $this->assertTrue(InstrumentGridOptions::fromQuery(['graficos' => '1'])->charts);
        $this->assertFalse(InstrumentGridOptions::fromQuery(['graficos' => '0'])->charts);
        // A forged value is the default — off — never an error.
        $this->assertFalse(InstrumentGridOptions::fromQuery(['graficos' => 'banana'])->charts);
        $this->assertFalse(InstrumentGridOptions::fromQuery(['graficos' => ['1']])->charts);
        $this->assertFalse(InstrumentGridOptions::fromQuery(['graficos' => ''])->charts);
    }

    #[Test]
    public function an_instrument_still_being_corrected_has_no_charts_even_when_they_are_asked_for(): void
    {
        [$class, $a, $b] = $this->classOn('Escala 1 a 5', 6);
        $instrument = $this->instrument($class, $a, $b, [1 => $this->assessed(9, 8)], conclude: false);

        $document = $this->grid($instrument);

        $this->assertNull($document['charts']);
        $this->assertFalse($document['charts_available']);
        $this->assertFalse($document['options']['charts'], 'the options say what was actually drawn');
    }

    #[Test]
    public function a_concluded_instrument_has_charts_only_when_asked_for(): void
    {
        [$class, $a, $b] = $this->classOn('Escala 1 a 5', 6);
        $instrument = $this->instrument($class, $a, $b, array_fill(1, 6, $this->assessed(8, 8)));

        $without = $this->grid($instrument, '');
        $with = $this->grid($instrument);

        $this->assertNull($without['charts']);
        $this->assertTrue($without['charts_available']);
        $this->assertNotNull($with['charts']);
        $this->assertTrue($with['options']['charts']);
        $this->assertSame(['distribution', 'means', 'questions'], array_keys($with['charts']));
    }

    // ================================================================ distribuição

    #[Test]
    public function the_distribution_is_the_analysers_own_by_level_in_the_scales_order(): void
    {
        [$class, $a, $b] = $this->classOn('Escala 1 a 5', 6);
        $instrument = $this->instrument($class, $a, $b, [
            1 => $this->assessed(10, 10), 2 => $this->assessed(9, 8), 3 => $this->assessed(6, 6),
            4 => $this->assessed(5, 5), 5 => $this->assessed(2, 1), 6 => $this->assessed(0, 1),
        ]);

        $chart = $this->grid($instrument)['charts']['distribution'];
        $global = $this->asTenant(fn (): array => app(BuildResultsAnalysis::class)->forInstrument(Instrument::findOrFail($instrument->id), true)['dimensions'][0]['analysis']);

        $this->assertSame('Por nível da escala', $chart['basis']);
        $this->assertSame(6, $chart['n']);
        $this->assertSame('n = 6 alunos com resultado', $chart['n_label']);

        // The same categories, in the same order, with the same counts and percentages as Resultados.
        $categories = $global['qualitative']['categories'];
        $this->assertCount(count($categories), $chart['bars']);

        foreach ($categories as $index => $category) {
            $bar = $chart['bars'][$index];
            $percent = (string) $category['percent'];

            $this->assertSame($category['label'], $bar['label']);
            $this->assertSame($category['count'].' · '.str_replace('.', ',', $percent).' %', $bar['value_text']);
            $this->assertSame($percent, $bar['width']);
            $this->assertSame((bool) $category['is_negative'], $bar['negative']);
        }

        // The counts add up to the students that have a result.
        $this->assertSame(6, array_sum(array_column($categories, 'count')));
        $this->assertNull($chart['caution']);
    }

    #[Test]
    public function a_scale_without_bands_is_distributed_in_ten_classes_of_ten_points(): void
    {
        [$class, $a, $b] = $this->classOn('Escala 0 a 20', 6);
        $instrument = $this->instrument($class, $a, $b, array_fill(1, 6, $this->assessed(7, 8)));

        $chart = $this->grid($instrument)['charts']['distribution'];

        $this->assertSame('Por intervalos de 10 pontos percentuais', $chart['basis']);
        $this->assertCount(10, $chart['bars']);
        $this->assertSame('[0, 10[', $chart['bars'][0]['label']);
        $this->assertSame('[90, 100]', $chart['bars'][9]['label']);
        // 75 % for everybody: all six in [70, 80[.
        $this->assertSame('6 · 100,0 %', $chart['bars'][7]['value_text']);
        $this->assertSame('0 · 0,0 %', $chart['bars'][0]['value_text']);
    }

    #[Test]
    public function the_students_without_a_result_are_counted_apart_and_never_drawn_as_a_bar_at_zero(): void
    {
        [$class, $a, $b] = $this->classOn('Escala 1 a 5', 6);
        $instrument = $this->instrument($class, $a, $b, [
            1 => $this->assessed(8, 8), 2 => $this->assessed(7, 7), 3 => $this->assessed(6, 6),
            4 => $this->assessed(9, 9), 5 => $this->assessed(5, 5), 6 => $this->absent(),
        ]);

        $chart = $this->grid($instrument)['charts']['distribution'];

        $this->assertSame(5, $chart['n'], 'the absent student has no result');
        $this->assertSame([['label' => 'ausente', 'count' => 1]], $chart['missing']);
        $this->assertSame('Sem resultado: 1 ausente', $chart['missing_note']);
        // None of the bars is the absent student, and the counts only add up to the five.
        $this->assertSame(5, array_sum(array_map(fn (array $bar): int => (int) explode(' · ', $bar['value_text'])[0], $chart['bars'])));
    }

    #[Test]
    public function with_nobody_classified_the_chart_says_so_and_draws_no_frames(): void
    {
        [$class, $a, $b] = $this->classOn('Escala 1 a 5', 4);
        $instrument = $this->instrument($class, $a, $b, array_fill(1, 4, $this->absent()));

        $charts = $this->grid($instrument)['charts'];

        $this->assertSame(0, $charts['distribution']['n']);
        $this->assertSame([], $charts['distribution']['bars']);
        $this->assertSame('Sem resultados: nenhum aluno tem ainda um resultado calculado.', $charts['distribution']['empty_message']);
        $this->assertSame('Sem resultado: 4 ausentes', $charts['distribution']['missing_note']);
        $this->assertNull($charts['distribution']['caution'], 'no caution for a figure that is not there');
        $this->assertSame([], $charts['means']['bars']);
        $this->assertSame([], $charts['questions']['bars']);
    }

    #[Test]
    public function few_students_carry_a_caution_and_enough_do_not(): void
    {
        [$class, $a, $b] = $this->classOn('Escala 1 a 5', 3);
        $few = $this->instrument($class, $a, $b, array_fill(1, 3, $this->assessed(8, 8)));

        $charts = $this->grid($few)['charts'];

        $this->assertSame('Poucos alunos com resultado (n = 3): leia com cautela.', $charts['distribution']['caution']);
        $this->assertSame('Poucos alunos com resultado (n = 3): leia com cautela.', $charts['means']['caution']);
        $this->assertSame('Poucos alunos (n = 3): leia com cautela.', $charts['questions']['caution']);

        [$bigClass, $c, $d] = $this->classOn('Escala 1 a 5', 5, '8.º B');
        $enough = $this->instrument($bigClass, $c, $d, array_fill(1, 5, $this->assessed(8, 8)), title: 'Cinco');
        $charts = $this->grid($enough)['charts'];

        $this->assertNull($charts['distribution']['caution']);
        $this->assertNull($charts['means']['caution']);
        $this->assertNull($charts['questions']['caution']);
    }

    // ===================================================================== médias

    #[Test]
    public function the_means_are_the_analysers_on_an_absolute_axis_with_the_number_of_students(): void
    {
        [$class, $a, $b] = $this->classOn('Escala 1 a 5', 6);
        $instrument = $this->instrument($class, $a, $b, [
            1 => $this->assessed(10, 4), 2 => $this->assessed(8, 6), 3 => $this->assessed(6, 8),
            4 => $this->assessed(9, 5), 5 => $this->assessed(7, 7), 6 => $this->absent(),
        ]);

        $chart = $this->grid($instrument)['charts']['means'];
        $analysis = $this->asTenant(fn (): array => app(BuildResultsAnalysis::class)->forInstrument(Instrument::findOrFail($instrument->id), true)['dimensions']);

        $this->assertSame('Global e por domínio', $chart['basis']);
        $this->assertSame('Escala absoluta de 0 a 100 %.', $chart['axis']);
        $this->assertCount(3, $chart['bars'], 'global and two domains');
        $this->assertSame('Global', $chart['bars'][0]['label']);

        foreach ($analysis as $index => $dimension) {
            $bar = $chart['bars'][$index];
            $mean = (string) $dimension['analysis']['mean'];

            $this->assertSame(str_replace('.', ',', $mean).' %', $bar['value_text']);
            $this->assertSame($mean, $bar['width'], 'an absolute axis: the length is the mean');
            $this->assertSame('n = 5 alunos com resultado', $bar['detail'], 'the absent student is not in the mean');
        }
    }

    #[Test]
    public function with_one_domain_only_the_global_mean_is_drawn(): void
    {
        [$class, $a] = $this->classOn('Escala 1 a 5', 6);
        $instrument = $this->asTenant(function () use ($class, $a): Instrument {
            $instrument = app(InstrumentBuilder::class)->create($class, [
                'academic_period_id' => $class->academicYear->periods()->firstOrFail()->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Um domínio', 'applied_on' => '2026-10-15', 'status' => 'in_correction',
                'counts_toward_classification' => true, 'purpose' => 'summative', 'total_points' => 10,
            ], [['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10, 'domains' => [['domain_id' => $a->id, 'allocation_percent' => 100]]]]);

            $cells = [];
            foreach ($class->enrollments()->get() as $enrollment) {
                $cells[] = ['enrollment_id' => $enrollment->id, 'instrument_item_id' => $instrument->items()->firstOrFail()->id, 'result_state' => ResultState::Assessed->value, 'points_earned' => 8.0];
            }
            app(RecordScores::class)->save($instrument, $cells, $this->teacher);
            app(CompleteCorrection::class)->complete(Instrument::findOrFail($instrument->id), $this->teacher);

            return Instrument::findOrFail($instrument->id);
        });

        $chart = $this->grid($instrument)['charts']['means'];

        $this->assertSame('Global', $chart['basis']);
        $this->assertSame(['Global'], array_column($chart['bars'], 'label'));
    }

    #[Test]
    public function a_mean_below_the_scales_threshold_is_marked_as_such(): void
    {
        [$class, $a, $b] = $this->classOn('Escala 1 a 5', 6);
        $instrument = $this->instrument($class, $a, $b, array_fill(1, 6, [[ResultState::Assessed, 3.0], [ResultState::Assessed, 8.0]]));

        $bars = $this->grid($instrument)['charts']['means']['bars'];

        // Domain A at 30 % is under the 49,5 % of the 1–5 scale; domain B at 80 % is not.
        $this->assertTrue($bars[1]['negative']);
        $this->assertFalse($bars[2]['negative']);
    }

    // ================================================================== por questão

    #[Test]
    public function the_mean_by_question_is_the_exact_mean_of_the_assessed_points_over_the_quota(): void
    {
        [$class, $a, $b] = $this->classOn('Escala 1 a 5', 6);
        $instrument = $this->instrument($class, $a, $b, [
            1 => $this->assessed(10, 2.5), 2 => $this->assessed(7.5, 5), 3 => $this->assessed(5, 7.5),
            4 => $this->assessed(2.5, 10), 5 => $this->assessed(9, 6), 6 => $this->assessed(4, 4),
        ]);

        $chart = $this->grid($instrument)['charts']['questions'];

        $this->assertSame('Média por questão', $chart['title']);
        $this->assertSame(['Q1', 'Q2'], array_column($chart['bars'], 'label'));
        // Q1: (10 + 7.5 + 5 + 2.5 + 9 + 4) = 38 / 6 / 10 = 63,3 %.   Q2: (2.5 + 5 + 7.5 + 10 + 6 + 4) = 35 / 6 / 10 = 58,3 %.
        $this->assertSame('63,3 %', $chart['bars'][0]['value_text']);
        $this->assertSame('63.3', $chart['bars'][0]['width']);
        $this->assertSame('58,3 %', $chart['bars'][1]['value_text']);
        $this->assertSame('6 de 6 avaliados', $chart['bars'][0]['detail']);
    }

    #[Test]
    public function an_absence_is_coverage_and_never_a_zero_in_the_mean_by_question(): void
    {
        [$class, $a, $b] = $this->classOn('Escala 1 a 5', 6);
        $instrument = $this->instrument($class, $a, $b, [
            1 => $this->assessed(10, 10), 2 => $this->assessed(10, 10), 3 => $this->assessed(10, 10),
            4 => $this->assessed(10, 10), 5 => $this->absent(),
            6 => [[ResultState::Exempt, null], [ResultState::Assessed, 10.0]],
        ]);

        $chart = $this->grid($instrument)['charts']['questions'];

        // The absent student and the exempt one are not zeros: 100 % of those assessed.
        $this->assertSame('100,0 %', $chart['bars'][0]['value_text']);
        $this->assertSame('4 de 6 avaliados · 1 ausente · 1 dispensado', $chart['bars'][0]['detail']);
        $this->assertSame('5 de 6 avaliados · 1 ausente', $chart['bars'][1]['detail']);
        $this->assertStringContainsString('não contam como zero', $chart['note']);
    }

    #[Test]
    public function a_question_nobody_was_assessed_on_has_no_bar_and_says_what_the_coverage_is(): void
    {
        [$class, $a, $b] = $this->classOn('Escala 1 a 5', 6);
        $instrument = $this->instrument($class, $a, $b, array_fill(1, 6, [[ResultState::Assessed, 8.0], [ResultState::Exempt, null]]));

        $bars = $this->grid($instrument)['charts']['questions']['bars'];

        $this->assertSame('Q2', $bars[1]['label']);
        $this->assertNull($bars[1]['width'], 'not a bar at zero');
        $this->assertSame('—', $bars[1]['value_text']);
        $this->assertSame('0 de 6 avaliados · 6 dispensados', $bars[1]['detail']);
    }

    #[Test]
    public function questions_without_a_domain_and_deductions_are_handled(): void
    {
        [$class, $a] = $this->classOn('Escala 1 a 5', 6);
        $instrument = $this->asTenant(function () use ($class, $a): Instrument {
            $instrument = app(InstrumentBuilder::class)->create($class, [
                'academic_period_id' => $class->academicYear->periods()->firstOrFail()->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Sem domínio', 'applied_on' => '2026-10-15', 'status' => 'in_correction',
                'counts_toward_classification' => true, 'purpose' => 'summative', 'total_points' => 20, 'allow_bonus' => true,
            ], [
                ['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10, 'domains' => [['domain_id' => $a->id, 'allocation_percent' => 100]]],
                ['code' => 'Q2', 'label' => 'Sem domínio', 'points_possible' => 10],
                ['code' => 'B1', 'label' => 'Bónus', 'points_possible' => 2, 'is_bonus' => true, 'domains' => [['domain_id' => $a->id, 'allocation_percent' => 100]]],
                ['code' => 'D1', 'label' => 'Dedução', 'points_possible' => 0, 'is_bonus' => true, 'domains' => [['domain_id' => $a->id, 'allocation_percent' => 100]]],
            ]);

            $items = $instrument->items()->orderBy('sequence')->get()->values();
            $cells = [];
            foreach ($class->enrollments()->get() as $enrollment) {
                foreach ([[0, 8.0], [1, 6.0], [2, 2.0], [3, -1.0]] as [$index, $points]) {
                    $cells[] = ['enrollment_id' => $enrollment->id, 'instrument_item_id' => $items[$index]->id, 'result_state' => ResultState::Assessed->value, 'points_earned' => $points];
                }
            }
            app(RecordScores::class)->save($instrument, $cells, $this->teacher);
            app(CompleteCorrection::class)->complete(Instrument::findOrFail($instrument->id), $this->teacher);

            return Instrument::findOrFail($instrument->id);
        });

        $charts = $this->grid($instrument)['charts'];
        $bars = array_column($charts['questions']['bars'], null, 'label');

        // The question without a domain is on the chart (it counts in the raw score) …
        $this->assertSame('60,0 %', $bars['Q2']['value_text']);
        // … a bonus can pass what the bar can show, and the text keeps the truth …
        $this->assertSame('100,0 %', $bars['B1']['value_text']);
        // … and a deduction has no quota to measure against, so no bar.
        $this->assertNull($bars['D1']['width']);
        $this->assertSame('—', $bars['D1']['value_text']);
        $this->assertStringContainsString('sem cotação', $bars['D1']['detail']);
        // The means by domain do not know the question without a domain.
        $this->assertCount(1, $charts['means']['bars'], 'one domain touched: only the global mean');
    }

    // ============================================================ privacidade e custo

    #[Test]
    public function the_charts_carry_no_name_no_identifier_and_no_per_student_bar(): void
    {
        [$class, $a, $b] = $this->classOn('Escala 1 a 5', 6);
        $instrument = $this->instrument($class, $a, $b, array_fill(1, 6, $this->assessed(8, 7)));

        $charts = $this->grid($instrument)['charts'];
        $json = json_encode($charts, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Aluno', (string) $json);

        $forbidden = ['id', 'ulid', 'enrollment_id', 'student_id', 'name', 'number'];
        $found = [];
        array_walk_recursive($charts, function ($value, $key) use ($forbidden, &$found): void {
            if (in_array($key, $forbidden, true)) {
                $found[] = $key;
            }
        });
        $this->assertSame([], $found);

        // Bars are as many as levels, domains or questions — never as many as students.
        $this->assertCount(2, $charts['questions']['bars']);
    }

    #[Test]
    public function no_ai_is_called_and_the_cohort_is_still_calculated_once(): void
    {
        $this->mock(AiGateway::class);

        $calculator = new class(app(CalculationEngine::class)) extends ClassResultsCalculator
        {
            public int $cohortCalls = 0;

            public function forInstrumentCohort(SchoolClass $class, Instrument $instrument, Collection $enrollments): array
            {
                $this->cohortCalls++;

                return parent::forInstrumentCohort($class, $instrument, $enrollments);
            }
        };
        $this->app->instance(ClassResultsCalculator::class, $calculator);

        [$class, $a, $b] = $this->classOn('Escala 1 a 5', 6);
        $instrument = $this->instrument($class, $a, $b, array_fill(1, 6, $this->assessed(8, 7)));
        $calculator->cohortCalls = 0;

        $this->grid($instrument);

        $this->assertSame(1, $calculator->cohortCalls, 'the cells and the charts share one calculation');
    }

    #[Test]
    public function the_queries_with_charts_do_not_grow_with_the_number_of_students(): void
    {
        [$class, $a, $b] = $this->classOn('Escala 1 a 5', 3);
        $small = $this->instrument($class, $a, $b, array_fill(1, 3, $this->assessed(8, 7)), title: 'Pequeno');

        [$bigClass, $c, $d] = $this->classOn('Escala 1 a 5', 14, '8.º B');
        $large = $this->instrument($bigClass, $c, $d, array_fill(1, 14, $this->assessed(8, 7)), title: 'Grande');

        $count = function (Instrument $instrument): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->grid($instrument);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->assertSame($count($small), $count($large), 'eleven more students must not add a query');
    }

    // ================================================================ ecrã = PDF

    #[Test]
    public function the_pdf_draws_exactly_the_bars_and_the_words_the_preview_receives(): void
    {
        [$class, $a, $b] = $this->classOn('Escala 1 a 5', 8);
        $instrument = $this->instrument($class, $a, $b, [
            1 => $this->assessed(10, 4), 2 => $this->assessed(8, 6), 3 => $this->assessed(6, 8), 4 => $this->assessed(9, 5),
            5 => $this->assessed(7, 7), 6 => $this->assessed(3, 2), 7 => $this->absent(), 8 => $this->assessed(5, 9),
        ]);

        $document = $this->grid($instrument);
        $html = View::make('instruments.grid-document', ['document' => $document, 'layout' => LetterheadLayout::Standard])->render();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xpath = new DOMXPath($dom);
        // A line break between a figure and its detail is, to a reader, a space.
        foreach (iterator_to_array($xpath->query('//br')) as $break) {
            $break->parentNode->replaceChild($dom->createTextNode(' '), $break);
        }
        $clean = fn (string $text): string => trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        $drawn = $xpath->query('//div[contains(@class, "chart")]');
        $this->assertSame(3, $drawn->length);

        foreach (array_values($document['charts']) as $index => $chart) {
            $node = $drawn->item($index);

            $this->assertSame($chart['title'], $clean($xpath->query('./h2', $node)->item(0)->textContent));

            $rows = $xpath->query('.//table[contains(@class, "chart")]/tr', $node);
            $this->assertSame(count($chart['bars']), $rows->length, "chart {$index}: one row per bar");

            foreach ($chart['bars'] as $barIndex => $bar) {
                $row = $rows->item($barIndex);
                $this->assertSame($bar['label'], $clean($xpath->query('./td[contains(@class, "chart-label")]', $row)->item(0)->textContent));

                $value = $clean($xpath->query('./td[contains(@class, "chart-value")]', $row)->item(0)->textContent);
                $this->assertSame($clean($bar['value_text'].($bar['detail'] !== null ? ' '.$bar['detail'] : '')), $value);

                $fill = $xpath->query('.//td[contains(@class, "fill")]', $row);
                if ($bar['width'] === null || (float) $bar['width'] === 0.0) {
                    $this->assertSame(0, $fill->length, 'no bar for a bar that is not there');
                } else {
                    $this->assertSame(1, $fill->length);
                    $this->assertStringContainsString("width: {$bar['width']}%", $fill->item(0)->getAttribute('style'));
                    $this->assertSame($bar['negative'], str_contains($fill->item(0)->getAttribute('class'), 'negative'));
                }
            }
        }

        $text = $clean($dom->textContent);
        foreach ([$document['charts']['distribution']['missing_note'], $document['charts']['questions']['note'], $document['charts']['means']['axis']] as $line) {
            $this->assertStringContainsString($line, $text);
        }
    }

    #[Test]
    public function the_pdf_has_the_charts_when_they_are_asked_for_and_concluded_and_never_otherwise(): void
    {
        [$class, $a, $b] = $this->classOn('Escala 1 a 5', 6);
        $concluded = $this->instrument($class, $a, $b, array_fill(1, 6, $this->assessed(8, 7)), title: 'Concluído');
        $open = $this->instrument($class, $a, $b, [1 => $this->assessed(8, 7)], conclude: false, title: 'Aberto');

        $text = fn (Instrument $instrument, string $query): string => implode(' ', array_map(
            fn ($page): string => preg_replace('/\s+/u', ' ', $page->getText()) ?? '',
            (new Parser)->parseContent((string) $this->actingAs($this->teacher)->get("/instruments/{$instrument->ulid}/impressao/pdf{$query}")->assertOk()->getContent())->getPages(),
        ));

        $with = $text($concluded, '?graficos=1');
        $this->assertStringContainsString('Distribuição dos resultados', $with);
        $this->assertStringContainsString('Média da turma', $with);
        $this->assertStringContainsString('Média por questão', $with);

        $this->assertStringNotContainsString('Distribuição dos resultados', $text($concluded, ''));
        // Asked for, but not concluded: the PDF carries none.
        $this->assertStringNotContainsString('Distribuição dos resultados', $text($open, '?graficos=1'));
    }

    #[Test]
    public function the_preview_receives_the_same_charts_the_pdf_draws(): void
    {
        [$class, $a, $b] = $this->classOn('Escala 1 a 5', 6);
        $instrument = $this->instrument($class, $a, $b, array_fill(1, 6, $this->assessed(8, 7)));

        $props = null;
        $this->actingAs($this->teacher)->get("/instruments/{$instrument->ulid}/impressao?graficos=1")->assertOk()->assertInertia(function ($page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

        $expected = $this->asTenant(fn (): array => app(InstrumentGrid::class)->build(Instrument::findOrFail($instrument->id), InstrumentGridOptions::fromQuery(['graficos' => '1'])));

        $this->assertSame(json_decode(json_encode($expected['charts']), true), $props['document']['charts']);
        $this->assertTrue($props['document']['options']['charts']);
    }
}
