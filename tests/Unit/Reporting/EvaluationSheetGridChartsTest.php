<?php

namespace Tests\Unit\Reporting;

use App\Models\ClassificationScope;
use App\Services\Assessment\Export\EvaluationSheetDocument;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGrid;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGridCharts;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGridOptions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\EvaluationSheetSample;
use Tests\TestCase;

/**
 * The factual charts of the grid of a class, over hand-made documents.
 *
 * Pure: they are counted from the document, so these tests need no database — and
 * one proves it. What they hold: every band of the scale has its bar, the bars are
 * a share of the students WITH a level and never of the whole class; a student
 * with nothing is counted aside and never as a zero; a result outside every band
 * is said; a mean is the statistics' own (decimal, one place, half up) over the
 * students who have a value; below five students a caution; and no name, no
 * ranking and no interpretation in any of it.
 */
class EvaluationSheetGridChartsTest extends TestCase
{
    private const LEVELS = [
        ['code' => '1', 'label' => 'Insuficiente', 'sequence' => 1, 'is_negative' => true],
        ['code' => '2', 'label' => 'Insuficiente', 'sequence' => 2, 'is_negative' => true],
        ['code' => '3', 'label' => 'Suficiente', 'sequence' => 3, 'is_negative' => false],
        ['code' => '4', 'label' => 'Bom', 'sequence' => 4, 'is_negative' => false],
        ['code' => '5', 'label' => 'Muito Bom', 'sequence' => 5, 'is_negative' => false],
    ];

    /**
     * One student: a global result, one value per domain, a proposal and (maybe) a decision.
     *
     * @param  list<string|null>  $domainValues
     * @return array<string, mixed>
     */
    private function student(string $name, ?string $global, array $domainValues, ?string $proposed, ?string $decided = null): array
    {
        $domains = [];

        foreach ($domainValues as $index => $value) {
            $domains[] = ['domain_id' => $index + 1, 'normalized_value' => $value, 'scale_level_code' => null, 'scale_level_label' => null];
        }

        return [
            'enrollment_id' => 1, 'class_number' => 1, 'name' => $name,
            'overall' => ['normalized_value' => $global, 'scale_level_code' => null, 'scale_level_label' => null],
            'domains' => $domains,
            'classification' => $proposed === null && $decided === null ? null : [
                'status' => $decided === null ? 'proposed' : 'confirmed',
                'proposed_scale_level_code' => $proposed, 'proposed_scale_level_label' => null, 'proposed_value' => $proposed,
                'final_scale_level_code' => $decided, 'final_scale_level_label' => null, 'final_value' => $decided,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $students
     * @param  array<string, mixed>|null  $scale
     */
    private function document(array $students, int $domains = 2, ?array $scale = ['name' => 'Escala 1 a 5', 'kind' => 'level', 'levels' => self::LEVELS]): EvaluationSheetDocument
    {
        return EvaluationSheetDocument::fromLiveSheet(
            EvaluationSheetSample::schoolClass(),
            EvaluationSheetSample::period(),
            [
                'domains' => array_map(fn (int $i): array => ['domain_id' => $i, 'name' => 'Domínio '.$i], $domains === 0 ? [] : range(1, $domains)),
                'students' => $students,
                'scale' => $scale,
            ],
            ClassificationScope::Period,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $students
     * @return array{proposals: array<string, mixed>, assigned: array<string, mixed>|null, means: array<string, mixed>}
     */
    private function charts(array $students, int $domains = 2, ?array $scale = ['name' => 'Escala 1 a 5', 'kind' => 'level', 'levels' => self::LEVELS]): array
    {
        return (new EvaluationSheetGridCharts)->build($this->document($students, $domains, $scale));
    }

    /** Nine students: proposals 3,3,3,4,4,5,1 — and two without (one with a result and none yet proposed, one with nothing at all). */
    private function class(): array
    {
        return [
            $this->student('A', '60.0', ['60', '60'], '3'),
            $this->student('B', '62.0', ['62', '62'], '3'),
            $this->student('C', '64.0', ['64', '64'], '3'),
            $this->student('D', '75.0', ['75', '75'], '4'),
            $this->student('E', '76.0', ['76', '76'], '4'),
            $this->student('F', '95.0', ['95', '95'], '5'),
            $this->student('G', '30.0', ['30', null], '1'),
            $this->student('H', '81.0', ['81', '81'], null),
            $this->student('I', null, [null, null], null),
        ];
    }

    // ------------------------------------------------------------ the levels

    #[Test]
    public function every_band_of_the_scale_has_its_bar_in_the_scales_own_order_including_the_empty_ones(): void
    {
        $chart = $this->charts($this->class())['proposals'];

        $this->assertTrue($chart['available']);
        $this->assertSame(
            ['1 · Insuficiente', '2 · Insuficiente', '3 · Suficiente', '4 · Bom', '5 · Muito Bom'],
            array_column($chart['bars'], 'label'),
        );
        $this->assertSame(['1 · 14,3 %', '0 · 0,0 %', '3 · 42,9 %', '2 · 28,6 %', '1 · 14,3 %'], array_column($chart['bars'], 'value_text'));
        $this->assertSame(['14.3', '0.0', '42.9', '28.6', '14.3'], array_column($chart['bars'], 'width'));
        $this->assertSame([true, true, false, false, false], array_column($chart['bars'], 'negative'));
    }

    #[Test]
    public function the_bars_are_a_share_of_the_students_with_a_level_never_of_the_whole_class(): void
    {
        $chart = $this->charts($this->class())['proposals'];

        $this->assertSame(7, $chart['n']);
        $this->assertSame('n = 7 alunos com proposta', $chart['n_label']);
        // Seven with a proposal out of nine: the other two are not hidden, and not a zero bar.
        $this->assertSame([['label' => 'sem proposta', 'count' => 2]], $chart['missing']);
        $this->assertSame('Sem proposta: 2', $chart['missing_note']);
        $this->assertNull($chart['caution']);
    }

    #[Test]
    public function the_assigned_chart_is_a_chart_of_its_own_and_only_exists_when_somebody_was_given_a_level(): void
    {
        $this->assertNull($this->charts($this->class())['assigned']);

        $students = $this->class();
        $students[0] = $this->student('A', '60.0', ['60', '60'], '3', '4');
        $students[1] = $this->student('B', '62.0', ['62', '62'], '3', '3');
        $assigned = $this->charts($students)['assigned'];

        $this->assertSame('Distribuição dos níveis atribuídos', $assigned['title']);
        $this->assertSame('Decisão do professor, por nível da escala', $assigned['basis']);
        $this->assertSame(['0 · 0,0 %', '0 · 0,0 %', '1 · 50,0 %', '1 · 50,0 %', '0 · 0,0 %'], array_column($assigned['bars'], 'value_text'));
        $this->assertSame('n = 2 de 9 alunos com nível atribuído', $assigned['n_label']);
        $this->assertSame('Sem nível atribuído: 7', $assigned['missing_note']);
        $this->assertSame('Poucos alunos (n = 2): leia com cautela.', $assigned['caution']);

        // The proposals are untouched by a decision: they answer another question.
        $this->assertSame($this->charts($this->class())['proposals']['bars'], $this->charts($students)['proposals']['bars']);
    }

    #[Test]
    public function a_level_the_scale_does_not_have_is_counted_aside_and_not_dropped_into_a_band(): void
    {
        $students = [
            $this->student('A', '60.0', ['60', '60'], '3'),
            $this->student('B', '70.0', ['70', '70'], '99'),
            $this->student('C', '71.0', ['71', '71'], '4'),
        ];

        $chart = $this->charts($students)['proposals'];

        $this->assertSame(2, $chart['n']);
        $this->assertSame(['0 · 0,0 %', '0 · 0,0 %', '1 · 50,0 %', '1 · 50,0 %', '0 · 0,0 %'], array_column($chart['bars'], 'value_text'));
        $this->assertStringContainsString('1 aluno com um nível que não consta da escala', (string) $chart['unplaced_note']);
    }

    #[Test]
    public function a_scale_of_values_is_counted_value_by_value_in_the_numbers_own_order(): void
    {
        $scale = ['name' => '0 a 20', 'kind' => 'numeric', 'levels' => []];
        $students = [
            $this->student('A', '50.0', ['50', '50'], '10'),
            $this->student('B', '50.0', ['50', '50'], '9'),
            $this->student('C', '50.0', ['50', '50'], '10'),
            $this->student('D', '50.0', ['50', '50'], '12'),
            $this->student('E', '50.0', ['50', '50'], '9'),
        ];

        $chart = $this->charts($students, 2, $scale)['proposals'];

        // Only the values somebody has, and 9 before 10 — never by how many have each.
        $this->assertSame(['9', '10', '12'], array_column($chart['bars'], 'label'));
        $this->assertSame(['2 · 40,0 %', '2 · 40,0 %', '1 · 20,0 %'], array_column($chart['bars'], 'value_text'));
        $this->assertNull($chart['caution'], 'five students are enough');
    }

    #[Test]
    public function without_the_scale_the_level_charts_say_so_instead_of_guessing_an_order_and_the_means_remain(): void
    {
        $students = $this->class();
        $students[0] = $this->student('A', '60.0', ['60', '60'], '3', '4');

        $charts = $this->charts($students, 2, null);

        foreach (['proposals', 'assigned'] as $key) {
            $this->assertFalse($charts[$key]['available'], $key);
            $this->assertSame([], $charts[$key]['bars'], $key);
            $this->assertSame([], $charts[$key]['missing'], $key);
            $this->assertStringContainsString('guardada antes de a escala viajar com ela', (string) $charts[$key]['unavailable_message']);
        }

        $this->assertNotSame([], $charts['means']['bars']);
    }

    #[Test]
    public function with_nobody_to_count_the_chart_says_so_and_draws_no_frames(): void
    {
        $chart = $this->charts([$this->student('A', null, [null, null], null), $this->student('B', null, [null, null], null)])['proposals'];

        $this->assertTrue($chart['available']);
        $this->assertSame([], $chart['bars']);
        $this->assertSame(0, $chart['n']);
        $this->assertSame('Sem proposta: 2', $chart['missing_note']);
        $this->assertNull($chart['caution']);
        $this->assertNotSame('', $chart['empty_message']);
    }

    // ------------------------------------------------------------ the means

    #[Test]
    public function the_means_are_the_global_and_each_domain_with_the_number_of_students_behind_each(): void
    {
        $means = $this->charts($this->class())['means'];

        $this->assertSame('Média da turma', $means['title']);
        $this->assertSame('Global e por domínio', $means['basis']);
        $this->assertSame(['Global', 'Domínio 1', 'Domínio 2'], array_column($means['bars'], 'label'));

        // Global: 60+62+64+75+76+95+30+81 = 543 over 8 = 67.875 → 67,9 (half up). Domain 1 the same eight; domain 2 has seven.
        $this->assertSame('67,9 %', $means['bars'][0]['value_text']);
        $this->assertSame('n = 8 alunos com resultado', $means['bars'][0]['detail']);
        $this->assertSame('67,9 %', $means['bars'][1]['value_text']);
        // 543 − 30 = 513 over 7 = 73.2857 → 73,3.
        $this->assertSame('73,3 %', $means['bars'][2]['value_text']);
        $this->assertSame('n = 7 alunos com resultado', $means['bars'][2]['detail']);
        $this->assertSame('Escala absoluta de 0 a 100 %.', $means['axis']);
    }

    #[Test]
    public function a_student_without_a_value_is_not_a_zero_in_the_mean_and_is_counted_aside(): void
    {
        $with = $this->charts([
            $this->student('A', '80.0', ['80', '80'], '4'),
            $this->student('B', '60.0', ['60', '60'], '3'),
        ])['means'];
        $without = $this->charts([
            $this->student('A', '80.0', ['80', '80'], '4'),
            $this->student('B', '60.0', ['60', '60'], '3'),
            $this->student('C', null, [null, null], null),
        ])['means'];

        // The third student moves no bar: not a zero dragging the mean down to 46,7.
        $this->assertSame($with['bars'], $without['bars']);
        $this->assertSame('70,0 %', $without['bars'][0]['value_text']);
        $this->assertSame([['label' => 'sem resultado global', 'count' => 1]], $without['missing']);
        $this->assertSame('Sem resultado global: 1', $without['missing_note']);
        $this->assertSame([], $with['missing']);
    }

    #[Test]
    public function a_domain_nobody_has_a_result_in_has_a_dash_and_no_bar_never_a_zero(): void
    {
        $means = $this->charts([
            $this->student('A', '80.0', ['80', null], '4'),
            $this->student('B', '60.0', ['60', null], '3'),
        ])['means'];

        $this->assertSame('—', $means['bars'][2]['value_text']);
        $this->assertSame('sem resultados', $means['bars'][2]['detail']);
        $this->assertNull($means['bars'][2]['width']);
    }

    #[Test]
    public function a_result_above_100_enters_the_mean_as_it_is_the_bar_stops_at_the_end_of_the_axis_and_it_is_said(): void
    {
        $means = $this->charts([
            $this->student('A', '120.0', ['120', '90'], '5'),
            $this->student('B', '100.0', ['100', '90'], '5'),
        ])['means'];

        // (120 + 100) / 2 = 110: a mean past the end of the axis, not rescaled.
        $this->assertSame('110,0 %', $means['bars'][0]['value_text']);
        $this->assertSame('100.0', $means['bars'][0]['width']);
        $this->assertStringContainsString('2 resultados acima de 100 %', (string) $means['unplaced_note']);
    }

    #[Test]
    public function with_a_single_domain_its_mean_is_the_global_one_and_is_drawn_once(): void
    {
        $means = $this->charts([$this->student('A', '80.0', ['80'], '4'), $this->student('B', '60.0', ['60'], '3')], 1)['means'];

        $this->assertSame(['Global'], array_column($means['bars'], 'label'));
        $this->assertSame('Global', $means['basis']);
    }

    #[Test]
    public function below_five_students_a_caution_names_the_smallest_group(): void
    {
        $few = $this->charts([
            $this->student('A', '80.0', ['80', '80'], '4'),
            $this->student('B', '60.0', ['60', null], '3'),
            $this->student('C', '70.0', ['70', null], '3'),
            $this->student('D', '71.0', ['71', null], '3'),
            $this->student('E', '72.0', ['72', '72'], '3'),
        ]);

        // Global rests on five, domain 2 on two: the caution speaks of the smallest.
        $this->assertSame('Poucos alunos com resultado (n = 2): leia com cautela.', $few['means']['caution']);
        $this->assertNull($few['proposals']['caution']);

        $four = $this->charts([
            $this->student('A', '80.0', ['80', '80'], '4'), $this->student('B', '60.0', ['60', '60'], '3'),
            $this->student('C', '70.0', ['70', '70'], '3'), $this->student('D', '71.0', ['71', '71'], '3'),
        ]);

        $this->assertSame('Poucos alunos com resultado (n = 4): leia com cautela.', $four['means']['caution']);
        $this->assertSame('Poucos alunos (n = 4): leia com cautela.', $four['proposals']['caution']);
    }

    // ------------------------------------------------------------ what is never in them

    #[Test]
    public function every_bar_is_inside_the_axis_and_no_name_or_identifier_travels(): void
    {
        $students = $this->class();
        $students[0] = $this->student('Aluna Reservada', '60.0', ['60', '60'], '3', '4');
        $charts = $this->charts($students);
        $json = (string) json_encode($charts, JSON_UNESCAPED_UNICODE);

        foreach (array_filter([$charts['proposals'], $charts['assigned'], $charts['means']]) as $chart) {
            foreach ($chart['bars'] as $bar) {
                $this->assertTrue($bar['width'] === null || ((float) $bar['width'] >= 0 && (float) $bar['width'] <= 100));
            }
        }

        foreach (['Aluna Reservada', 'enrollment', 'domain_id', 'ulid'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    #[Test]
    public function there_is_no_ranking_no_trend_and_no_interpretation(): void
    {
        $json = strtolower((string) json_encode($this->charts($this->class()), JSON_UNESCAPED_UNICODE));

        foreach (['melhor', 'pior', 'ranking', 'evolu', 'tendência', 'subiu', 'desceu', 'preocupa', 'positiv'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, $forbidden);
        }
    }

    #[Test]
    public function the_charts_read_nothing_but_the_document(): void
    {
        $document = $this->document($this->class());

        DB::enableQueryLog();
        $first = (new EvaluationSheetGridCharts)->build($document);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries, 'no database');
        $this->assertSame($first, (new EvaluationSheetGridCharts)->build($document), 'no clock: two calls, one result');

        $source = '';

        foreach (token_get_all((string) file_get_contents((string) (new \ReflectionClass(EvaluationSheetGridCharts::class))->getFileName())) as $token) {
            if (! is_array($token)) {
                $source .= $token;
            } elseif (! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $source .= $token[1];
            }
        }

        foreach (['DB::', 'now()', 'Carbon', 'BuildEvaluationSheet', 'BuildClassStatistics', '::query()', 'Gate::', 'auth()'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "the charts must not use {$forbidden}");
        }
    }

    // ------------------------------------------------------------ in the grid

    #[Test]
    public function the_grid_draws_no_charts_unless_asked_and_the_option_is_whitelisted(): void
    {
        $document = $this->document($this->class());

        $off = (new EvaluationSheetGrid)->build($document, new EvaluationSheetGridOptions);
        $on = (new EvaluationSheetGrid)->build($document, new EvaluationSheetGridOptions(charts: true));

        $this->assertNull($off['charts']);
        $this->assertFalse($off['options']['charts']);
        $this->assertTrue($on['options']['charts']);
        $this->assertSame((new EvaluationSheetGridCharts)->build($document), $on['charts']);
        // Asking for the charts changes nothing in the table.
        $this->assertSame($off['blocks'], $on['blocks']);
        $this->assertSame($off['summary'], $on['summary']);

        $this->assertFalse(EvaluationSheetGridOptions::fromQuery([])->charts);
        $this->assertTrue(EvaluationSheetGridOptions::fromQuery(['graficos' => '1'])->charts);
        $this->assertFalse(EvaluationSheetGridOptions::fromQuery(['graficos' => '0'])->charts);
        $this->assertFalse(EvaluationSheetGridOptions::fromQuery(['graficos' => ['1']])->charts, 'an array is off, not a crash');
        $this->assertFalse(EvaluationSheetGridOptions::fromQuery(['graficos' => 'talvez'])->charts);
    }

    #[Test]
    public function an_empty_class_has_charts_that_say_there_is_nothing_to_draw(): void
    {
        $charts = $this->charts([], 0);

        $this->assertSame([], $charts['proposals']['bars']);
        $this->assertNull($charts['assigned']);
        $this->assertSame([], $charts['means']['bars']);
        $this->assertNull($charts['means']['caution']);
    }
}
