<?php

namespace Tests\Unit\Reporting;

use App\Models\ClassificationScope;
use App\Models\EvaluationSheetExport;
use App\Services\Assessment\Export\EvaluationSheetCsvWriter;
use App\Services\Assessment\Export\EvaluationSheetDocument;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGrid;
use App\Services\Reporting\ClassDocuments\EvaluationSheetGridOptions;
use App\Services\Reporting\ClassDocuments\InstrumentGridLayout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\EvaluationSheetSample;
use Tests\TestCase;

/**
 * The printable grid of a class, as a neutral structure.
 *
 * It is a pure function of an `EvaluationSheetDocument`, so these tests need no
 * database — and one of them proves it, by counting the queries a build makes
 * (none). What they hold: the three statements (calculated, proposed, decided)
 * never share a cell; a decision never overwrites the proposal it replaced; the
 * figures are the Pauta's and the CSV's; nothing private travels; an old kept
 * moment still opens; and no column is ever lost to the page.
 */
class EvaluationSheetGridTest extends TestCase
{
    private function build(EvaluationSheetDocument $document, ?EvaluationSheetGridOptions $options = null): array
    {
        return (new EvaluationSheetGrid)->build($document, $options ?? new EvaluationSheetGridOptions);
    }

    /**
     * One student's cell of one column kind (the first, or the nth when a kind repeats per domain).
     */
    private function cell(array $grid, string $name, string $kind, int $nth = 0): array
    {
        $found = [];

        foreach ($grid['blocks'] as $block) {
            foreach ($block['rows'] as $row) {
                if ($row['name'] !== $name) {
                    continue;
                }

                foreach ($block['columns'] as $position => $column) {
                    if ($column['kind'] === $kind) {
                        $found[] = $row['cells'][$position];
                    }
                }
            }
        }

        $this->assertArrayHasKey($nth, $found, "no «{$kind}» cell #{$nth} for {$name}");

        return $found[$nth];
    }

    // ------------------------------------------------------------ the structure

    #[Test]
    public function a_small_sheet_is_one_landscape_block_with_the_identity_of_the_pauta(): void
    {
        $grid = $this->build(EvaluationSheetSample::live());

        $this->assertSame('landscape', $grid['orientation']);
        $this->assertCount(1, $grid['blocks']);
        $this->assertNull($grid['blocks'][0]['caption']);
        $this->assertSame('Semestre — 1.º Semestre', $grid['title']);
        $this->assertSame('7.º A · Português · 1.º Semestre', $grid['subtitle']);
        $this->assertSame('7.º A', $grid['meta']['class']);
        $this->assertSame('Período', $grid['meta']['scope']);
        $this->assertSame(5, $grid['summary']['students']);
        $this->assertSame(2, $grid['summary']['domains']);
        $this->assertFalse($grid['is_empty']);
    }

    #[Test]
    public function the_columns_are_the_numbers_the_domains_and_then_the_global_and_the_classification(): void
    {
        $grid = $this->build(EvaluationSheetSample::live());
        $block = $grid['blocks'][0];

        $this->assertSame(
            ['domain_result', 'domain_appreciation', 'domain_result', 'domain_appreciation', 'result', 'proposal', 'assigned', 'status', 'coverage'],
            array_column($block['columns'], 'kind'),
        );
        $this->assertSame(
            [['Oralidade', 2], ['Escrita', 2], ['Global', 1], ['Classificação', 3], [null, 1]],
            array_map(fn (array $span): array => [$span['label'], $span['span']], $block['group_spans']),
        );
        $this->assertSame(['1', '', '3', '4', '5'], array_column($block['rows'], 'number'));
        $this->assertSame(
            ['Aluno Um', 'Aluno Dois', 'Aluno Três', 'Aluno Quatro', 'Aluno Cinco'],
            array_column($block['rows'], 'name'),
            'the same lines, in the same order, as the Pauta',
        );

        foreach ($block['rows'] as $row) {
            $this->assertCount(count($block['columns']), $row['cells']);
        }
    }

    #[Test]
    public function an_empty_class_and_a_class_without_a_profile_still_make_a_document(): void
    {
        $empty = EvaluationSheetDocument::fromLiveSheet(
            EvaluationSheetSample::schoolClass(),
            EvaluationSheetSample::period(),
            ['domains' => [], 'students' => []],
            ClassificationScope::Period,
        );

        $grid = $this->build($empty);

        $this->assertTrue($grid['is_empty']);
        $this->assertSame(0, $grid['summary']['students']);
        $this->assertSame(['result', 'proposal', 'assigned', 'status', 'coverage'], array_column($grid['blocks'][0]['columns'], 'kind'));

        $noDomains = EvaluationSheetDocument::fromLiveSheet(
            EvaluationSheetSample::schoolClass(),
            EvaluationSheetSample::period(),
            ['domains' => [], 'students' => [EvaluationSheetSample::sheet(false)['students'][0]]],
            ClassificationScope::Period,
        );

        $bare = $this->build($noDomains);
        $this->assertSame(['78,5%', 'B', 'MB', 'Confirmada', ''], array_column($bare['blocks'][0]['rows'][0]['cells'], 'text'));
    }

    // ------------------------------------------------------------ calculated, proposed, decided

    #[Test]
    public function a_domain_decision_is_marked_and_keeps_the_proposal_it_replaced(): void
    {
        $grid = $this->build(EvaluationSheetSample::live());

        $decided = $this->cell($grid, 'Aluno Um', 'domain_appreciation', 0);
        $this->assertSame('MB', $decided['text']);
        $this->assertSame('decision', $decided['origin']);
        $this->assertSame('prof.', $decided['mark']);
        $this->assertSame('Proposta: B', $decided['detail'], 'the proposal stays underneath');

        $proposed = $this->cell($grid, 'Aluno Um', 'domain_appreciation', 1);
        $this->assertSame('S', $proposed['text']);
        $this->assertSame('proposal', $proposed['origin']);
        $this->assertNull($proposed['mark']);
        $this->assertNull($proposed['detail']);

        $percentage = $this->cell($grid, 'Aluno Um', 'domain_result', 0);
        $this->assertSame('81,3%', $percentage['text'], 'the calculated figure is never touched by the decision');
        $this->assertNull($percentage['origin']);
    }

    #[Test]
    public function the_proposal_the_decision_and_the_state_are_three_cells_and_never_one(): void
    {
        $grid = $this->build(EvaluationSheetSample::live());

        // Decided and confirmed.
        $this->assertSame('B', $this->cell($grid, 'Aluno Um', 'proposal')['text']);
        $this->assertSame('MB', $this->cell($grid, 'Aluno Um', 'assigned')['text']);
        $this->assertSame('decision', $this->cell($grid, 'Aluno Um', 'assigned')['origin']);
        $this->assertSame('Confirmada', $this->cell($grid, 'Aluno Um', 'status')['text']);

        // Only a proposal: the assigned level is empty and says why, the proposal is NOT promoted.
        $this->assertSame('3', $this->cell($grid, 'Aluno Três', 'proposal')['text']);
        $this->assertSame('—', $this->cell($grid, 'Aluno Três', 'assigned')['text']);
        $this->assertSame('não decidido', $this->cell($grid, 'Aluno Três', 'assigned')['detail']);
        $this->assertNull($this->cell($grid, 'Aluno Três', 'assigned')['origin']);

        // No classification at all.
        $this->assertSame('—', $this->cell($grid, 'Aluno Dois', 'proposal')['text']);
        $this->assertSame('—', $this->cell($grid, 'Aluno Dois', 'assigned')['text']);
        $this->assertSame('sem classificação', $this->cell($grid, 'Aluno Dois', 'assigned')['detail']);
        $this->assertSame('—', $this->cell($grid, 'Aluno Dois', 'status')['text']);
    }

    #[Test]
    public function a_decision_taken_on_a_percentage_scale_reads_as_a_percentage(): void
    {
        $grid = $this->build(EvaluationSheetSample::live());

        $this->assertSame('65%', $this->cell($grid, 'Aluno Quatro', 'assigned')['text']);
        $this->assertSame('64', $this->cell($grid, 'Aluno Quatro', 'proposal')['text']);
    }

    #[Test]
    public function a_missing_value_is_a_dash_never_a_zero(): void
    {
        $grid = $this->build(EvaluationSheetSample::live());

        $this->assertSame('—', $this->cell($grid, 'Aluno Dois', 'domain_result', 0)['text']);
        $this->assertSame('—', $this->cell($grid, 'Aluno Dois', 'domain_result', 1)['text'], 'a domain the student has no row for');
        $this->assertSame('—', $this->cell($grid, 'Aluno Dois', 'domain_appreciation', 1)['text']);
        $this->assertSame('—', $this->cell($grid, 'Aluno Dois', 'result')['text']);

        foreach ($grid['blocks'][0]['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                $this->assertNotSame('0,0%', $cell['text']);
                $this->assertNotSame('0', $cell['text']);
            }
        }
    }

    #[Test]
    public function the_global_result_carries_the_band_it_falls_in_and_a_star_when_it_rests_on_partial_elements(): void
    {
        $grid = $this->build(EvaluationSheetSample::live());

        $one = $this->cell($grid, 'Aluno Um', 'result');
        $this->assertSame('78,5%', $one['text']);
        $this->assertSame('B', $one['detail']);

        $this->assertSame('70,0% *', $this->cell($grid, 'Aluno Um', 'domain_result', 1)['text']);
        $this->assertSame('Escrita', $this->cell($grid, 'Aluno Um', 'coverage')['text']);

        // A warning without a value has nothing to star: the dash stays a dash.
        $this->assertSame('—', $this->cell($grid, 'Aluno Dois', 'result')['text']);
        $this->assertSame('Global', $this->cell($grid, 'Aluno Dois', 'coverage')['text']);

        $this->assertContains('* O valor assenta em elementos parciais ou em falta.', $grid['notes']);
    }

    #[Test]
    public function the_state_is_provisional_while_anyone_has_no_level_assigned(): void
    {
        $grid = $this->build(EvaluationSheetSample::live());

        $this->assertTrue($grid['state']['is_provisional']);
        $this->assertSame('PROVISÓRIO', $grid['state']['label']);
        $this->assertSame(['decided' => 2, 'pending' => 3], array_intersect_key($grid['summary'], ['decided' => 0, 'pending' => 0]));
        $this->assertContains('3 alunos ainda sem nível atribuído pelo professor.', $grid['notes']);

        // Everybody decided: no stamp, no sentence.
        $sheet = EvaluationSheetSample::sheet(false);
        $sheet['students'] = [$sheet['students'][0]];
        $done = EvaluationSheetDocument::fromLiveSheet(EvaluationSheetSample::schoolClass(), EvaluationSheetSample::period(), $sheet, ClassificationScope::Period);
        $grid = $this->build($done);

        $this->assertFalse($grid['state']['is_provisional']);
        $this->assertSame('Classificações tomadas', $grid['state']['label']);
        $this->assertSame(0, $grid['summary']['pending']);
    }

    // ------------------------------------------------------------ parity

    #[Test]
    public function every_figure_and_level_is_the_one_the_csv_writes(): void
    {
        $document = EvaluationSheetSample::kept(withSelfAssessment: false);
        $grid = $this->build($document);
        $csv = $this->csvRows((new EvaluationSheetCsvWriter)->write($document));

        $header = array_shift($csv);
        $column = fn (string $name): int => array_search($name, $header, true);

        foreach ($csv as $line) {
            $name = $line[1];

            // The domains.
            foreach (['Oralidade', 'Escrita'] as $index => $domain) {
                $this->assertSame(
                    $this->commaPercent($line[$column("{$domain} — Percentagem")]),
                    rtrim($this->cell($grid, $name, 'domain_result', $index)['text'], ' *'),
                    "{$name}: {$domain} percentage",
                );

                $printed = $this->cell($grid, $name, 'domain_appreciation', $index);
                $this->assertSame($line[$column("{$domain} — Apreciação")] ?: '—', $printed['text'], "{$name}: {$domain} appreciation");
                $this->assertSame($line[$column("{$domain} — Origem da apreciação")] === 'Decisão do professor', $printed['origin'] === 'decision');
            }

            // The global and the classification.
            $this->assertSame($this->commaPercent($line[$column('Global — Percentagem')]), rtrim($this->cell($grid, $name, 'result')['text'], ' *'));
            $this->assertSame($line[$column('Global — Nível')] ?: null, $this->cell($grid, $name, 'result')['detail']);
            $this->assertSame($line[$column('Proposta do Lapispro')] ?: '—', $this->cell($grid, $name, 'proposal')['text']);
            $this->assertSame($line[$column('Nível atribuído')] ?: '—', $this->cell($grid, $name, 'assigned')['text']);
            $this->assertSame($line[$column('Avisos de cobertura')], $this->cell($grid, $name, 'coverage')['text']);
        }
    }

    #[Test]
    public function the_same_grid_comes_out_whatever_proposal_basis_the_sheet_was_read_with(): void
    {
        // The screen reads «proposta de hoje» and «desatualizada» next to the stored proposal; a kept moment
        // and the Quadro Síntese do not. The grid reads neither, so it is the same grid.
        $plain = EvaluationSheetSample::sheet(false);
        $withBasis = $plain;

        foreach ($withBasis['students'] as $index => $student) {
            if (is_array($student['classification'])) {
                $withBasis['students'][$index]['classification'] += [
                    'current_normalized_value' => '12.3', 'current_value' => '1', 'current_scale_level_code' => 'I',
                    'current_scale_level_label' => 'Insuficiente', 'proposal_is_stale' => true, 'final_value_scale' => 'Escala antiga',
                ];
            }
        }

        $live = fn (array $sheet): EvaluationSheetDocument => EvaluationSheetDocument::fromLiveSheet(
            EvaluationSheetSample::schoolClass(), EvaluationSheetSample::period(), $sheet, ClassificationScope::Period,
        );

        $this->assertSame($this->build($live($plain)), $this->build($live($withBasis)));
    }

    // ------------------------------------------------------------ the two sources

    #[Test]
    public function the_live_sheet_says_it_is_the_state_on_the_day_of_issue(): void
    {
        $grid = $this->build(EvaluationSheetSample::live());

        $this->assertFalse($grid['source']['is_historical']);
        $this->assertSame('Estado à data de emissão', $grid['source']['label']);
        $this->assertNull($grid['source']['kept_at']);
        $this->assertNull($grid['meta']['reference_date']);
        $this->assertSame([], $grid['warnings']);
    }

    #[Test]
    public function a_kept_moment_says_when_it_was_kept_and_that_nothing_has_changed_since(): void
    {
        $grid = $this->build(EvaluationSheetSample::kept(withSelfAssessment: false));

        $this->assertTrue($grid['source']['is_historical']);
        $this->assertSame('Momento guardado a '.Carbon::parse('2026-12-16 10:30:00', 'UTC')->timezone((string) config('app.timezone'))->format('d/m/Y, H:i'), $grid['source']['label']);
        $this->assertSame('Professora Teste', $grid['source']['author']);
        $this->assertSame('15/12/2026', $grid['meta']['reference_date']);
        $this->assertSame('Momento intercalar do 1.º Semestre', $grid['title']);
        $this->assertSame(['Aviso de cobertura registado no momento.'], $grid['warnings']);
        $this->assertStringContainsString('não refletem alterações posteriores', implode(' ', $grid['notes']));
    }

    #[Test]
    public function a_kept_moment_is_read_from_its_payload_and_never_from_the_present(): void
    {
        $document = EvaluationSheetSample::kept(withSelfAssessment: false);

        DB::enableQueryLog();
        $first = $this->build($document);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries, 'building the grid touches no database');
        $this->assertSame($first, $this->build($document), 'and no clock: two calls, one structure');

        // The code, without its comments (which explain what the class does NOT do).
        $source = '';

        foreach (token_get_all((string) file_get_contents((string) (new \ReflectionClass(EvaluationSheetGrid::class))->getFileName())) as $token) {
            if (! is_array($token)) {
                $source .= $token;
            } elseif (! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $source .= $token[1];
            }
        }

        foreach (['DB::', 'now()', 'Carbon::now', 'BuildEvaluationSheet', 'Classification::', '::query()', 'Gate::', 'auth()'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "the grid must not use {$forbidden}");
        }
    }

    // ------------------------------------------------------------ old kept moments

    #[Test]
    public function a_kept_moment_from_before_the_newer_keys_still_opens(): void
    {
        $sheet = EvaluationSheetSample::sheet(false);

        // The oldest shape: no decision keys, no status, no coverage, no author, no colours, no warnings.
        foreach ($sheet['students'] as $index => $student) {
            foreach ($student['domains'] as $d => $domain) {
                unset($sheet['students'][$index]['domains'][$d]['decided_scale_level_code'], $sheet['students'][$index]['domains'][$d]['decided_scale_level_label'], $sheet['students'][$index]['domains'][$d]['has_coverage_warning']);
            }

            if (is_array($student['classification'])) {
                unset($sheet['students'][$index]['classification']['status'], $sheet['students'][$index]['classification']['final_value_is_percentage']);
            }

            unset($sheet['students'][$index]['overall']['has_coverage_warning']);
        }

        $export = (new EvaluationSheetExport)->forceFill([
            'scope' => ClassificationScope::Period,
            'moment_label' => 'Final de período',
            'effective_at' => null,
            'exported_at' => Carbon::parse('2026-07-01 09:00:00', 'UTC'),
            'payload' => ['domains' => $sheet['domains'], 'students' => $sheet['students']],
        ]);

        $grid = $this->build(EvaluationSheetDocument::fromSnapshot($export));

        $this->assertSame(5, $grid['summary']['students']);
        $this->assertSame('Final de período', $grid['title']);
        $this->assertSame('— · — · —', $grid['subtitle']);
        $this->assertSame('—', $this->cell($grid, 'Aluno Um', 'status')['text'], 'no status key: nothing is invented');
        $this->assertSame('MB', $this->cell($grid, 'Aluno Um', 'assigned')['text']);
        $this->assertNull($grid['meta']['reference_date']);
        $this->assertNull($grid['source']['author']);
    }

    // ------------------------------------------------------------ the self-assessment

    #[Test]
    public function the_self_assessment_is_off_by_default_even_when_the_sheet_has_it(): void
    {
        $document = EvaluationSheetSample::kept(withSelfAssessment: true);

        $grid = $this->build($document);

        $this->assertFalse($grid['options']['self_assessment']);
        $this->assertTrue($grid['self_assessment_available'], 'the page can offer it');
        $this->assertNotContains('domain_self_assessment', array_column($grid['blocks'][0]['columns'], 'kind'));
        $this->assertNotContains('self_assessment', array_column($grid['blocks'][0]['columns'], 'kind'));
        $this->assertStringNotContainsString('critérios anteriores', json_encode($grid, JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function the_self_assessment_is_added_per_domain_and_globally_only_when_asked_for(): void
    {
        $document = EvaluationSheetSample::kept(withSelfAssessment: true);
        $grid = $this->build($document, new EvaluationSheetGridOptions(selfAssessment: true));

        $kinds = array_column($grid['blocks'][0]['columns'], 'kind');
        $this->assertSame(2, count(array_filter($kinds, fn (string $kind): bool => $kind === 'domain_self_assessment')));
        $this->assertContains('self_assessment', $kinds);
        $this->assertTrue($grid['options']['self_assessment']);

        $this->assertSame('MB (critérios anteriores: Perfil A · versão 2)', $this->cell($grid, 'Aluno Um', 'domain_self_assessment', 0)['text']);
        $this->assertSame('B', $this->cell($grid, 'Aluno Um', 'self_assessment')['text']);
        $this->assertSame('—', $this->cell($grid, 'Aluno Cinco', 'self_assessment')['text'], 'did not answer: a dash, not a zero');
    }

    #[Test]
    public function asking_for_the_self_assessment_of_a_sheet_that_has_none_adds_no_column(): void
    {
        $grid = $this->build(EvaluationSheetSample::live(), new EvaluationSheetGridOptions(selfAssessment: true));

        $this->assertFalse($grid['options']['self_assessment']);
        $this->assertFalse($grid['self_assessment_available']);
        $this->assertNotContains('self_assessment', array_column($grid['blocks'][0]['columns'], 'kind'));
    }

    // ------------------------------------------------------------ privacy

    #[Test]
    public function nothing_private_travels_in_the_structure(): void
    {
        $sheet = EvaluationSheetSample::sheet(withSelfAssessment: true);
        $sheet['students'][0]['classification']['override_reason'] = 'SEGREDO-MOTIVO-LIVRE';
        $sheet['students'][0]['enrollment_id'] = 987654;
        $sheet['students'][0]['domains'][0]['scale_level_id'] = 876543;
        $sheet['students'][0]['domains'][0]['coverage'] = ['explanation' => 'SEGREDO-COBERTURA'];
        $sheet['students'][0]['classification']['proposed_scale_level_id'] = 765432;

        $live = EvaluationSheetDocument::fromLiveSheet(EvaluationSheetSample::schoolClass(), EvaluationSheetSample::period(), $sheet, ClassificationScope::Period);
        $json = json_encode($this->build($live, new EvaluationSheetGridOptions(selfAssessment: true)), JSON_UNESCAPED_UNICODE);

        foreach (['SEGREDO', '987654', '876543', '765432', 'enrollment', 'override_reason', 'domain_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "«{$forbidden}» must not travel");
        }

        // The name of a student is what the sheet is about; nothing else identifies anyone.
        $this->assertStringContainsString('Aluno Um', $json);
    }

    // ------------------------------------------------------------ the blocks

    #[Test]
    public function a_wide_sheet_is_cut_into_blocks_without_losing_a_single_column(): void
    {
        foreach ([false, true] as $withSelfAssessment) {
            foreach ([InstrumentGridLayout::AUTO, InstrumentGridLayout::PORTRAIT, InstrumentGridLayout::LANDSCAPE] as $orientation) {
                foreach ([0, 1, 2, 3, 5, 6, 9, 14] as $domains) {
                    $document = $this->wideDocument($domains, $withSelfAssessment);
                    $grid = $this->build($document, new EvaluationSheetGridOptions(selfAssessment: $withSelfAssessment, orientation: $orientation));
                    $label = "{$domains} domains, self-assessment ".json_encode($withSelfAssessment).", {$orientation}";

                    $perDomain = $withSelfAssessment ? 3 : 2;
                    $summaryKinds = $withSelfAssessment
                        ? ['result', 'self_assessment', 'proposal', 'assigned', 'status', 'coverage']
                        : ['result', 'proposal', 'assigned', 'status', 'coverage'];

                    $kinds = array_merge(...array_map(fn (array $block): array => array_column($block['columns'], 'kind'), $grid['blocks']));
                    $this->assertSame($domains * $perDomain + count($summaryKinds), count($kinds), "every column exactly once: {$label}");
                    $this->assertSame(
                        $summaryKinds,
                        array_values(array_filter($kinds, fn (string $kind): bool => in_array($kind, $summaryKinds, true) && ! str_starts_with($kind, 'domain_'))),
                        "the summary in order: {$label}",
                    );

                    // Every domain whole, in order, never split across blocks.
                    $names = [];

                    foreach ($grid['blocks'] as $block) {
                        $blockDomains = array_values(array_filter($block['group_spans'], fn (array $span): bool => str_starts_with((string) $span['label'], 'Domínio')));
                        foreach ($blockDomains as $span) {
                            $this->assertSame($perDomain, $span['span'], "a domain is never split: {$label}");
                            $names[] = $span['label'];
                        }

                        // Every block carries every student, with Nº and Nome.
                        $this->assertCount(3, $block['rows'], $label);
                        $this->assertSame(['Aluno A', 'Aluno B', 'Aluno C'], array_column($block['rows'], 'name'), $label);

                        foreach ($block['rows'] as $row) {
                            $this->assertCount(count($block['columns']), $row['cells'], $label);
                        }
                    }

                    $this->assertSame(array_map(fn (int $i): string => 'Domínio '.($i + 1), $domains === 0 ? [] : range(0, $domains - 1)), $names, "domains in order, once each: {$label}");

                    // No block is wider than its page.
                    $room = InstrumentGridLayout::roomFor($grid['orientation']);

                    foreach ($grid['blocks'] as $block) {
                        $width = 0.0;

                        foreach ($block['columns'] as $column) {
                            $width += $this->widthOf($column['kind']);
                        }

                        $this->assertLessThanOrEqual($room, $width, "a block must fit the page: {$label}");
                    }
                }
            }
        }
    }

    #[Test]
    public function the_default_is_landscape_and_a_wide_sheet_in_portrait_takes_more_blocks(): void
    {
        $document = $this->wideDocument(6, false);

        $landscape = $this->build($document);
        $portrait = $this->build($document, new EvaluationSheetGridOptions(orientation: InstrumentGridLayout::PORTRAIT));
        $auto = $this->build($document, new EvaluationSheetGridOptions(orientation: InstrumentGridLayout::AUTO));

        $this->assertSame('landscape', $landscape['orientation']);
        $this->assertSame('portrait', $portrait['orientation']);
        $this->assertGreaterThan(count($landscape['blocks']), count($portrait['blocks']));
        $this->assertSame('landscape', $auto['orientation'], 'six domains do not fit one portrait block');
        $this->assertSame('Parte 1 de '.count($landscape['blocks']), $landscape['blocks'][0]['caption']);
    }

    #[Test]
    public function a_summary_that_leaves_no_room_for_a_domain_gets_a_block_of_its_own_instead_of_overflowing(): void
    {
        // Portrait: 123 mm of room. With the self-assessment a domain is 46 mm and the summary 107 mm:
        // together they are 153 mm. The summary fits a page, but not beside one domain.
        $grid = $this->build($this->wideDocument(2, true), new EvaluationSheetGridOptions(selfAssessment: true, orientation: InstrumentGridLayout::PORTRAIT));

        $this->assertSame('portrait', $grid['orientation']);
        $summaryBlocks = array_filter($grid['blocks'], fn (array $block): bool => in_array('result', array_column($block['columns'], 'kind'), true));
        $this->assertCount(1, $summaryBlocks);
        $this->assertNotContains('domain_result', array_column(array_values($summaryBlocks)[0]['columns'], 'kind'), 'the summary stands alone');
    }

    // ------------------------------------------------------------ the options

    #[Test]
    public function the_options_are_read_against_a_whitelist(): void
    {
        $defaults = EvaluationSheetGridOptions::fromQuery([]);
        $this->assertFalse($defaults->selfAssessment);
        $this->assertSame(InstrumentGridLayout::LANDSCAPE, $defaults->orientation);

        $this->assertTrue(EvaluationSheetGridOptions::fromQuery(['autoavaliacao' => '1'])->selfAssessment);
        $this->assertTrue(EvaluationSheetGridOptions::fromQuery(['autoavaliacao' => 'true'])->selfAssessment);
        $this->assertFalse(EvaluationSheetGridOptions::fromQuery(['autoavaliacao' => '0'])->selfAssessment);
        $this->assertFalse(EvaluationSheetGridOptions::fromQuery(['autoavaliacao' => ['1']])->selfAssessment, 'an array is off, not a crash');
        $this->assertFalse(EvaluationSheetGridOptions::fromQuery(['autoavaliacao' => 'talvez'])->selfAssessment);

        $this->assertSame(InstrumentGridLayout::PORTRAIT, EvaluationSheetGridOptions::fromQuery(['orientacao' => 'vertical'])->orientation);
        $this->assertSame(InstrumentGridLayout::AUTO, EvaluationSheetGridOptions::fromQuery(['orientacao' => 'auto'])->orientation);
        $this->assertSame(InstrumentGridLayout::LANDSCAPE, EvaluationSheetGridOptions::fromQuery(['orientacao' => 'horizontal'])->orientation);
        $this->assertSame(InstrumentGridLayout::LANDSCAPE, EvaluationSheetGridOptions::fromQuery(['orientacao' => 'portrait'])->orientation);
        $this->assertSame(InstrumentGridLayout::LANDSCAPE, EvaluationSheetGridOptions::fromQuery(['orientacao' => ['vertical']])->orientation);
    }

    // ------------------------------------------------------------ helpers

    private function commaPercent(string $csv): string
    {
        return $csv === '' ? '—' : str_replace('.', ',', $csv).'%';
    }

    /**
     * @return list<list<string>>
     */
    private function csvRows(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, substr($csv, strlen(EvaluationSheetCsvWriter::BOM)));
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        // Skip the preamble: the table starts at the row that begins with «Nº».
        $start = array_search('Nº', array_map(fn (array $row): ?string => $row[0] ?? null, $rows), true);

        return array_slice($rows, (int) $start);
    }

    /**
     * Three students and `$domains` domains: enough columns to need blocks.
     */
    private function wideDocument(int $domains, bool $withSelfAssessment): EvaluationSheetDocument
    {
        $domainList = [];
        $studentDomains = [];

        for ($index = 1; $index <= $domains; $index++) {
            $domainList[] = ['domain_id' => $index, 'name' => 'Domínio '.$index];
            $studentDomains[] = [
                'domain_id' => $index, 'normalized_value' => '60', 'scale_level_code' => 'S', 'scale_level_label' => 'Suficiente',
                'self_assessment' => $withSelfAssessment ? ['display' => 'B', 'code' => 'B'] : null,
            ];
        }

        $students = [];

        foreach (['A', 'B', 'C'] as $position => $letter) {
            $students[] = [
                'enrollment_id' => $position + 1, 'class_number' => $position + 1, 'name' => 'Aluno '.$letter,
                'overall' => ['normalized_value' => '60', 'scale_level_code' => 'S', 'scale_level_label' => 'Suficiente'],
                'domains' => $studentDomains,
                'classification' => ['status' => 'proposed', 'proposed_scale_level_code' => 'S', 'proposed_value' => '3', 'final_scale_level_code' => null, 'final_scale_level_label' => null, 'final_value' => null],
                'self_assessment' => $withSelfAssessment ? ['display' => 'S', 'code' => 'S'] : null,
            ];
        }

        return EvaluationSheetDocument::fromLiveSheet(
            EvaluationSheetSample::schoolClass(),
            EvaluationSheetSample::period(),
            ['domains' => $domainList, 'students' => $students],
            ClassificationScope::Period,
        );
    }

    private function widthOf(string $kind): float
    {
        return match ($kind) {
            'domain_result' => 14.0,
            'domain_appreciation' => 16.0,
            'domain_self_assessment' => EvaluationSheetGrid::SELF_ASSESSMENT_WIDTH,
            'result' => 15.0,
            'self_assessment' => EvaluationSheetGrid::SELF_ASSESSMENT_WIDTH,
            'proposal' => 16.0,
            'assigned', 'status' => 18.0,
            'coverage' => 24.0,
        };
    }
}
