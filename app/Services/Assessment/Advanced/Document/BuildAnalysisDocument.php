<?php

namespace App\Services\Assessment\Advanced\Document;

use App\Models\SchoolClass;
use App\Services\Assessment\Advanced\AdvancedAnalyticsUnavailable;
use App\Services\Assessment\Advanced\BuildAssessmentComparison;
use App\Services\Assessment\Advanced\BuildAssessmentTimeline;
use App\Services\Assessment\Advanced\BuildClassStrengths;
use App\Services\Assessment\Advanced\BuildClassTrends;
use App\Support\Entitlements\Entitlements;

/**
 * The Pro analysis document: the three readings of the «Análise da Avaliação» — comparison, strengths
 * and difficulties of a moment, trends and persistent negative results — as ONE structure that the
 * preview and the PDF both draw.
 *
 * FAITHFUL BY CONSTRUCTION. The document is composed from the very payloads the page shows
 * (`BuildAssessmentComparison`, `BuildClassStrengths`, `BuildClassTrends`), asked for the same moments
 * by the same query, and from nothing else: it has no figure of its own, no rounding of its own and no
 * wording of its own beyond joining facts into a sentence (`DocumentFormat`, which says them as the
 * page does). Neither renderer formats anything: the structure carries finished text, so the screen and
 * the paper cannot differ.
 *
 * BLOCKS, NOT A LAYOUT. A section is a list of simple blocks — a heading, a paragraph, key/value lines,
 * a list, a table — that the Vue preview and the Blade for Dompdf both know how to draw. Adding a fact
 * to the document is adding it here, once.
 *
 * WHAT SUPPORTS EACH CONCLUSION TRAVELS WITH IT: the values, the counts with their denominators, the
 * criteria in words, and — gathered at the top, because they limit everything below — the warnings:
 * coverage, a comparison that cannot be made and why, moments left out of a line, too few moments.
 *
 * NAMES ARE OPT-IN (`AnalysisDocumentOptions::students`). The counts are always there; the nominal
 * lists only when the teacher asks, and they are the class's own students listed for the class's own
 * teacher — nothing a screen does not already show them.
 *
 * NOT A DIAGNOSIS. It describes marks and moments; it says so at the foot, and suggests no cause,
 * forecast or measure.
 *
 * PRO. Refuses an organization without `advanced_analytics`, like the readings it is made of.
 */
class BuildAnalysisDocument
{
    public function __construct(
        protected BuildAssessmentComparison $comparison,
        protected BuildClassStrengths $strengths,
        protected BuildClassTrends $trends,
        protected Entitlements $entitlements,
    ) {}

    /**
     * @param  array<string, mixed>  $timeline  as `BuildAssessmentTimeline::for()` wrote it
     * @return array<string, mixed>
     *
     * @throws AdvancedAnalyticsUnavailable
     */
    public function build(SchoolClass $class, array $timeline, ?string $from, ?string $to, ?string $moment, AnalysisDocumentOptions $options): array
    {
        if (! $this->entitlements->allows(BuildAssessmentTimeline::ANALYTICS_MODULE)) {
            throw new AdvancedAnalyticsUnavailable;
        }

        $comparison = $this->comparison->between($timeline, $from, $to);
        $strengths = $this->strengths->for($timeline, $moment);
        $trends = $this->trends->for($timeline);

        $sections = [];

        if ($options->has(AnalysisDocumentOptions::COMPARISON)) {
            $sections[] = ['key' => AnalysisDocumentOptions::COMPARISON, 'title' => __('Comparação entre momentos'), 'blocks' => $this->comparisonBlocks($comparison, $options->students)];
        }

        if ($options->has(AnalysisDocumentOptions::STRENGTHS)) {
            $sections[] = ['key' => AnalysisDocumentOptions::STRENGTHS, 'title' => __('Pontos fortes e dificuldades'), 'blocks' => $this->strengthsBlocks($strengths, $options->students)];
        }

        if ($options->has(AnalysisDocumentOptions::TRENDS)) {
            $sections[] = ['key' => AnalysisDocumentOptions::TRENDS, 'title' => __('Tendências e resultados persistentes'), 'blocks' => $this->trendsBlocks($trends, $options->students)];
        }

        $class->loadMissing(['subject', 'academicYear']);

        return [
            'title' => __('Análise da Avaliação'),
            'subtitle' => "{$class->label} · {$class->subject->name} · {$class->academicYear->label}",
            'identification' => $this->identification($class, $comparison, $strengths, $trends, $options),
            'warnings' => $this->warnings($comparison, $strengths, $trends, $options),
            'sections' => $sections,
            'options' => ['sections' => $options->sections, 'students' => $options->students],
            'summary' => $this->summary($timeline, $comparison, $strengths, $trends, $options),
            'notice' => __('Este documento descreve notas e momentos, a partir dos dados da aplicação à data da emissão. Não é um diagnóstico, não indica causas, não faz previsões e não propõe medidas: a decisão pedagógica é do professor.'),
            'is_empty' => $sections === [],
        ];
    }

    // ================================================================= identification, warnings, summary

    /**
     * @param  array<string, mixed>  $comparison
     * @param  array<string, mixed>  $strengths
     * @param  array<string, mixed>  $trends
     * @return list<array{label: string, value: string}>
     */
    private function identification(SchoolClass $class, array $comparison, array $strengths, array $trends, AnalysisDocumentOptions $options): array
    {
        $rows = [
            ['label' => __('Turma'), 'value' => $class->label],
            ['label' => __('Disciplina'), 'value' => (string) $class->subject->name],
            ['label' => __('Ano letivo'), 'value' => (string) $class->academicYear->label],
        ];

        if ($comparison['scale'] !== null) {
            $rows[] = ['label' => __('Escala de avaliação'), 'value' => (string) $comparison['scale']['name']];
        }

        if ($options->has(AnalysisDocumentOptions::COMPARISON)) {
            $rows[] = ['label' => __('Momentos comparados'), 'value' => $comparison['state'] === 'ready'
                ? "{$comparison['from']['label']} → {$comparison['to']['label']}"
                : __('Sem dois momentos com resultados')];
        }

        if ($options->has(AnalysisDocumentOptions::STRENGTHS)) {
            $rows[] = ['label' => __('Momento analisado'), 'value' => $strengths['moment'] !== null ? $strengths['moment']['label'].' — '.$strengths['moment']['basis']['label'] : __('Sem resultados')];
        }

        if ($options->has(AnalysisDocumentOptions::TRENDS) && $trends['state'] === 'ready') {
            $rows[] = ['label' => __('Linha de tendências'), 'value' => implode(' → ', array_map(fn (array $point): string => $point['comparable'] ? $point['label'] : "({$point['label']})", $trends['points']))];
        }

        return $rows;
    }

    /**
     * What limits the conclusions below, gathered once and said once.
     *
     * @param  array<string, mixed>  $comparison
     * @param  array<string, mixed>  $strengths
     * @param  array<string, mixed>  $trends
     * @return list<string>
     */
    private function warnings(array $comparison, array $strengths, array $trends, AnalysisDocumentOptions $options): array
    {
        $warnings = [];

        if ($options->has(AnalysisDocumentOptions::COMPARISON) && $comparison['state'] === 'ready') {
            foreach ($comparison['cautions'] as $caution) {
                $warnings[] = (string) $caution['text'];
            }

            foreach ($comparison['comparability']['reasons'] as $reason) {
                $warnings[] = (string) $reason['text'];
            }
        } elseif ($options->has(AnalysisDocumentOptions::COMPARISON)) {
            $warnings[] = (string) $comparison['reason'];
        }

        if ($options->has(AnalysisDocumentOptions::STRENGTHS) && $strengths['state'] === 'ready') {
            foreach ($strengths['cautions'] as $caution) {
                $warnings[] = (string) $caution['text'];
            }

            if (! $strengths['negative_line']['known'] && $strengths['negative_line']['text'] !== null) {
                $warnings[] = (string) $strengths['negative_line']['text'];
            }
        } elseif ($options->has(AnalysisDocumentOptions::STRENGTHS)) {
            $warnings[] = (string) $strengths['reason'];
        }

        if ($options->has(AnalysisDocumentOptions::TRENDS)) {
            if ($trends['state'] === 'ready') {
                if ($trends['availability']['reason'] !== null) {
                    $warnings[] = (string) $trends['availability']['reason'];
                }

                foreach ($trends['left_out'] as $left) {
                    $warnings[] = $left['label'].': '.implode(' ', array_column($left['reasons'], 'text'));
                }

                foreach ($trends['cautions'] as $caution) {
                    $warnings[] = (string) $caution['text'];
                }
            } else {
                $warnings[] = (string) $trends['reason'];
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * Counts and flags only — what the audit trail may keep, and nothing a name or a mark.
     *
     * @param  array<string, mixed>  $timeline
     * @param  array<string, mixed>  $comparison
     * @param  array<string, mixed>  $strengths
     * @param  array<string, mixed>  $trends
     * @return array<string, mixed>
     */
    private function summary(array $timeline, array $comparison, array $strengths, array $trends, AnalysisDocumentOptions $options): array
    {
        return [
            'students' => count($timeline['students']),
            'domains' => count($timeline['domains']),
            'points_with_results' => (int) $timeline['availability']['points_with_results'],
            'comparison' => $comparison['state'],
            'strengths' => $strengths['state'],
            'trends' => $trends['state'],
            'comparable_points' => (int) $trends['availability']['comparable_points'],
            'highlights' => count($strengths['highlights']['strengths']) + count($strengths['highlights']['attention']),
            'include_students' => $options->students,
        ];
    }

    // ================================================================= blocks

    /** @return array{type: string, text: string, tone: string} */
    private function paragraph(string $text, string $tone = 'plain'): array
    {
        return ['type' => 'paragraph', 'text' => $text, 'tone' => $tone];
    }

    /** @return array{type: string, text: string} */
    private function heading(string $text): array
    {
        return ['type' => 'heading', 'text' => $text];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $items
     * @return array{type: string, items: list<array{label: string, value: string}>}
     */
    private function keyValues(array $items): array
    {
        return ['type' => 'keyvalues', 'items' => array_map(fn (array $item): array => ['label' => $item[0], 'value' => $item[1]], $items)];
    }

    /**
     * @param  array<array<string, mixed>|string>  $items  sentences (the framework types a translation loosely)
     * @return array{type: string, title: string|null, items: list<array<string, mixed>|string>}
     */
    private function list(array $items, ?string $title = null): array
    {
        // A real list, whatever keys came in: a renderer — and JSON — must see an array, not an object.
        return ['type' => 'list', 'title' => $title, 'items' => array_values($items)];
    }

    /**
     * @param  list<array{string, string, string}>  $columns  key, label, align (`left`/`right`)
     * @param  array<array<array<string, mixed>|string>>  $rows  cells are sentences (the framework types a translation loosely)
     * @return array{type: string, columns: list<array{key: string, label: string, align: string}>, rows: list<list<array<string, mixed>|string>>}
     */
    private function table(array $columns, array $rows): array
    {
        return [
            'type' => 'table',
            'columns' => array_map(fn (array $column): array => ['key' => $column[0], 'label' => $column[1], 'align' => $column[2]], $columns),
            // Real lists at both levels: a row is positional, and a renderer indexes it by column.
            'rows' => array_values(array_map(fn (array $row): array => array_values($row), $rows)),
        ];
    }

    // ================================================================= 1. comparison

    /**
     * @param  array<string, mixed>  $c
     * @return list<array<string, mixed>>
     */
    private function comparisonBlocks(array $c, bool $students): array
    {
        if ($c['state'] !== 'ready') {
            return [$this->paragraph((string) $c['reason'])];
        }

        $blocks = [];
        $from = $c['from'];
        $to = $c['to'];

        $point = fn (array $p): string => $p['basis']['label'].' · '.DocumentFormat::countOf($p['students_with_result'], $p['students_total']).' '.__('com resultado')
            .($p['partial_coverage'] > 0 ? ' · '.$p['partial_coverage'].' '.__('com cobertura parcial') : '');

        $blocks[] = $this->keyValues([[(string) $from['label'], $point($from)], [(string) $to['label'], $point($to)]]);

        if ($c['basis_note'] !== null) {
            $blocks[] = $this->paragraph((string) $c['basis_note'], 'muted');
        }

        foreach ($c['cautions'] as $caution) {
            $blocks[] = $this->paragraph((string) $caution['text'], 'caution');
        }

        if ($c['comparability']['reasons'] !== []) {
            $blocks[] = $this->paragraph(__('Estes dois momentos não são totalmente comparáveis:'), 'caution');
            $blocks[] = $this->list(array_map(fn (array $reason): string => (string) $reason['text'], $c['comparability']['reasons']));
        }

        // The class.
        $class = $c['class'];
        $paired = $class['paired'];
        $blocks[] = $this->heading(__('A turma'));

        $rows = [[
            __('Evolução da turma (mesmos alunos)'),
            $paired['change'] !== null
                ? DocumentFormat::percent($paired['from_mean']).' → '.DocumentFormat::percent($paired['to_mean']).' ('.DocumentFormat::points($paired['change']['points']).') · '.DocumentFormat::students($paired['students'])
                : __('Sem comparação: os critérios mudaram entre os dois momentos'),
        ], [
            __('Média em «:label»', ['label' => $from['label']]),
            DocumentFormat::percent($class['moments']['from']['mean']).' · '.DocumentFormat::students($class['moments']['from']['students']).' '.__('com resultado'),
        ], [
            __('Média em «:label»', ['label' => $to['label']]),
            DocumentFormat::percent($class['moments']['to']['mean']).' · '.DocumentFormat::students($class['moments']['to']['students']).' '.__('com resultado'),
        ]];

        if ($class['counts'] !== null) {
            $of = DocumentFormat::students($class['counts']['compared']).' '.__('comparados');
            $rows[] = [__('Progrediram'), "{$class['counts']['up']} de {$of}"];
            $rows[] = [__('Mantiveram-se'), "{$class['counts']['flat']} de {$of}"];
            $rows[] = [__('Regrediram'), "{$class['counts']['down']} de {$of}"];
        }

        $blocks[] = $this->keyValues($rows);

        $missing = $class['not_compared'];
        $notCompared = [];

        if ($missing['missing_from'] > 0) {
            $notCompared[] = DocumentFormat::students($missing['missing_from']).' '.__('sem resultado no primeiro momento');
        }

        if ($missing['missing_to'] > 0) {
            $notCompared[] = DocumentFormat::students($missing['missing_to']).' '.__('sem resultado no segundo momento');
        }

        if ($missing['missing_both'] > 0) {
            $notCompared[] = DocumentFormat::students($missing['missing_both']).' '.__('sem resultado em nenhum dos momentos');
        }

        if ($notCompared !== []) {
            $blocks[] = $this->paragraph(__('Sem comparação:').' '.implode('; ', $notCompared).'. '.__('Um resultado em falta não conta como zero.'), 'muted');
        }

        // The domains.
        if ($c['domains'] !== []) {
            $blocks[] = $this->heading(__('Por domínio'));
            $blocks[] = $this->table(
                [['domain', __('Domínio'), 'left'], ['from', (string) $from['label'], 'right'], ['to', (string) $to['label'], 'right'], ['change', __('Variação'), 'right'], ['counts', __('Prog. / Man. / Reg.'), 'right'], ['students', __('Alunos'), 'right']],
                array_map(fn (array $domain): array => [
                    (string) $domain['name'],
                    DocumentFormat::percent($domain['paired']['from_mean']),
                    DocumentFormat::percent($domain['paired']['to_mean']),
                    $domain['paired']['change'] !== null ? DocumentFormat::points($domain['paired']['change']['points']) : DocumentFormat::DASH,
                    $domain['counts'] !== null ? "{$domain['counts']['up']} / {$domain['counts']['flat']} / {$domain['counts']['down']}" : DocumentFormat::DASH,
                    (string) $domain['paired']['students'],
                ], $c['domains']),
            );
        }

        // The levels.
        $blocks[] = $this->heading(__('Mudanças de nível'));
        $levels = $c['levels'];

        if ($levels['comparable'] === false) {
            $blocks[] = $this->paragraph(__('Os níveis não se comparam entre estes dois momentos.'), 'caution');
            $blocks[] = $this->list(array_map(fn (array $reason): string => (string) $reason['text'], $levels['reasons']));
        } else {
            foreach ([__('Nível do resultado') => $levels['result'], __('Nível atribuído') => $levels['assigned']] as $title => $summary) {
                $line = "{$summary['counts']['up']} ".__('subiram').", {$summary['counts']['same']} ".__('mantiveram').", {$summary['counts']['down']} ".__('desceram').' '.__('(de :students)', ['students' => DocumentFormat::students($summary['counts']['compared'])]);

                if ($summary['crossed']['to_positive'] || $summary['crossed']['to_negative']) {
                    $line .= '; '.$summary['crossed']['to_positive'].' '.__('passaram a nível positivo').', '.$summary['crossed']['to_negative'].' '.__('passaram a nível negativo');
                }

                if ($summary['counts']['not_compared'] > 0) {
                    $line .= '; '.DocumentFormat::students($summary['counts']['not_compared']).' '.__('sem nível num dos momentos');
                }

                $blocks[] = $this->paragraph("{$title}: {$line}.");

                $shown = array_values(array_filter($summary['distribution'], fn (array $level): bool => $level['from'] > 0 || $level['to'] > 0));

                if ($shown !== []) {
                    $blocks[] = $this->table(
                        [['level', __('Nível'), 'left'], ['from', (string) $from['label'], 'right'], ['to', (string) $to['label'], 'right']],
                        array_map(fn (array $level): array => [$level['label'].($level['is_negative'] ? ' ('.__('negativo').')' : ''), (string) $level['from'], (string) $level['to']], $shown),
                    );
                }
            }
        }

        // The students.
        if ($students) {
            $blocks[] = $this->heading(__('Cada aluno'));
            $blocks[] = $this->table(
                [['student', __('Aluno'), 'left'], ['from', (string) $from['label'], 'right'], ['to', (string) $to['label'], 'right'], ['movement', __('Variação'), 'left'], ['level', __('Nível atribuído'), 'left']],
                array_map(function (array $student): array {
                    $figure = fn (?array $f): string => $f === null || $f['value'] === null ? DocumentFormat::DASH : DocumentFormat::percent($f['value']).(($f['band']['label'] ?? null) ? ' · '.$f['band']['label'] : '');
                    $movement = $student['movement'];

                    return [
                        $this->studentName($student),
                        $figure($student['from']),
                        $figure($student['to']),
                        DocumentFormat::movementWord($movement['state']).($movement['points'] !== null ? ' ('.DocumentFormat::points($movement['points']).')' : '').($movement['reason'] !== null ? ' — '.DocumentFormat::movementReason($movement['reason']) : ''),
                        $this->assignedText($student),
                    ];
                }, $c['students']),
            );
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $student
     */
    private function assignedText(array $student): string
    {
        $assigned = $student['assigned'];

        if ($assigned['from'] === null && $assigned['to'] === null && $assigned['from_value'] === null && $assigned['to_value'] === null) {
            return DocumentFormat::DASH;
        }

        if ($assigned['change']['state'] === 'unavailable' && $assigned['change']['reason'] === 'missing') {
            return __('Sem nível atribuído num dos momentos.');
        }

        return DocumentFormat::levelChange($assigned['change']);
    }

    /**
     * @param  array<string, mixed>  $student
     */
    private function studentName(array $student): string
    {
        return $student['class_number'] !== null ? __('N.º').' '.$student['class_number'].' · '.$student['name'] : (string) $student['name'];
    }

    // ================================================================= 2. strengths

    /**
     * @param  array<string, mixed>  $s
     * @return list<array<string, mixed>>
     */
    private function strengthsBlocks(array $s, bool $students): array
    {
        if ($s['state'] !== 'ready') {
            return [$this->paragraph((string) $s['reason'])];
        }

        $blocks = [];
        $moment = $s['moment'];

        $blocks[] = $this->keyValues([[(string) $moment['label'], $moment['basis']['label'].' · '.DocumentFormat::countOf($moment['students_with_result'], $moment['students_total']).' '.__('com resultado')
            .($moment['partial_coverage'] > 0 ? ' · '.$moment['partial_coverage'].' '.__('com cobertura parcial') : '')]]);
        $blocks[] = $this->paragraph((string) $s['basis_note'], 'muted');

        foreach ($s['cautions'] as $caution) {
            $blocks[] = $this->paragraph((string) $caution['text'], 'caution');
        }

        if (! $s['negative_line']['known'] && $s['negative_line']['text'] !== null) {
            $blocks[] = $this->paragraph((string) $s['negative_line']['text'], 'caution');
        } elseif ($s['negative_line']['negative_levels'] !== []) {
            $blocks[] = $this->paragraph(__('Abaixo do nível positivo, nesta escala:').' '.implode(', ', $s['negative_line']['negative_levels']).'.', 'muted');
        }

        if ($s['result'] !== null) {
            $result = $s['result'];
            $items = [
                [__('Resultado global da turma'), DocumentFormat::percent($result['mean']).' · '.DocumentFormat::countOf($result['students']['with_value'], $result['students']['of']).' '.__('com resultado')],
            ];

            if ($result['below_positive'] !== null) {
                $items[] = [__('Resultado global abaixo do nível positivo'), DocumentFormat::countOf($result['below_positive']['count'], $result['below_positive']['of']).' ('.DocumentFormat::percent($result['below_positive']['share']).')'];
            }

            $blocks[] = $this->keyValues($items);
        }

        $blocks[] = $this->heading(__('Por domínio'));
        $blocks[] = $this->table(
            [['domain', __('Domínio'), 'left'], ['mean', __('Média'), 'right'], ['with', __('Alunos com valor'), 'right'], ['below', __('Abaixo do nível positivo'), 'right'], ['coverage', __('Cobertura'), 'left']],
            array_map(function (array $row) use ($s): array {
                $mark = match (true) {
                    ($s['ranking']['highest']['ulid'] ?? null) === $row['ulid'] => ' ('.__('média mais alta').')',
                    ($s['ranking']['lowest']['ulid'] ?? null) === $row['ulid'] => ' ('.__('média mais baixa').')',
                    default => '',
                };
                $coverage = ['none' => __('Sem resultados'), 'few' => __('Poucos alunos'), 'incomplete' => __('Incompleta'), 'complete' => __('Completa')][$row['coverage']['state']];

                return [
                    (string) $row['name'].$mark,
                    DocumentFormat::percent($row['mean']),
                    DocumentFormat::countOf($row['students']['with_value'], $row['students']['of']),
                    $row['below_positive'] !== null ? DocumentFormat::countOf($row['below_positive']['count'], $row['below_positive']['of']).' ('.DocumentFormat::percent($row['below_positive']['share']).')' : DocumentFormat::DASH,
                    $coverage.($row['coverage']['partial'] > 0 ? ' · '.__('parcial em :count', ['count' => $row['coverage']['partial']]) : ''),
                ];
            }, $s['domains']),
        );

        $ranking = $s['ranking'];

        if ($ranking['highest_tie'] !== []) {
            $blocks[] = $this->paragraph(__('Empate na média mais alta:').' '.implode(' e ', $ranking['highest_tie']).'. '.__('Nenhum é destacado.'), 'muted');
        }

        if ($ranking['lowest_tie'] !== []) {
            $blocks[] = $this->paragraph(__('Empate na média mais baixa:').' '.implode(' e ', $ranking['lowest_tie']).'. '.__('Nenhum é destacado.'), 'muted');
        }

        if ($ranking['state'] === 'all_equal') {
            $blocks[] = $this->paragraph(__('Todos os domínios com resultados têm a mesma média: nenhum é destacado.'), 'muted');
        }

        if ($ranking['state'] === 'insufficient') {
            $blocks[] = $this->paragraph(__('Só há média em menos de dois domínios: não há mais alta nem mais baixa para destacar.'), 'muted');
        }

        $blocks[] = $this->heading(__('Pontos fortes e resultados a observar'));
        $blocks[] = $this->list($s['highlights']['strengths'] === [] ? [__('Nada a destacar com os dados deste momento.')] : array_map(fn (array $item): string => (string) $item['text'], $s['highlights']['strengths']), __('Pontos fortes'));
        $blocks[] = $this->list($s['highlights']['attention'] === [] ? [__('Nada a destacar com os dados deste momento.')] : array_map(fn (array $item): string => (string) $item['text'], $s['highlights']['attention']), __('Resultados a observar'));

        $blocks[] = $this->heading(__('Alunos abaixo do nível positivo em mais de um domínio'));
        $below = $s['students_below'];

        if (! $below['known']) {
            $blocks[] = $this->paragraph(__('Sem linha entre positivo e negativo neste momento: não se contam alunos abaixo do nível positivo.'), 'muted');
        } else {
            $blocks[] = $this->paragraph(__('Entre :students com valor em pelo menos um domínio: :none em nenhum domínio abaixo do nível positivo, :one num domínio, :many em dois ou mais.', [
                'students' => DocumentFormat::students($below['of']), 'none' => $below['none'], 'one' => $below['one'], 'many' => $below['two_or_more'],
            ]));
            $blocks[] = $this->paragraph(__('Um resultado abaixo do nível positivo descreve uma nota; não é um diagnóstico.'), 'muted');

            if ($students && $below['students'] !== []) {
                $blocks[] = $this->list(array_map(fn (array $student): string => $this->studentName($student).' — '.count($student['domains']).' de '.$student['domains_with_value'].' '.__('domínios com valor').': '.implode('; ', array_map(
                    fn (array $domain): string => $domain['name'].' '.DocumentFormat::percent($domain['value']).' · '.($domain['band']['label'] ?? $domain['band']['code'] ?? ''),
                    $student['domains'],
                )), $below['students']));
            }
        }

        return $blocks;
    }

    // ================================================================= 3. trends

    /**
     * @param  array<string, mixed>  $t
     * @return list<array<string, mixed>>
     */
    private function trendsBlocks(array $t, bool $students): array
    {
        if ($t['state'] !== 'ready') {
            return [$this->paragraph((string) $t['reason'])];
        }

        $blocks = [];

        $blocks[] = $this->keyValues([
            [__('Tendência'), __('pelo menos 3 momentos comparáveis com valor: progressão consistente, regressão consistente, estável ou evolução variável, a partir dos passos entre momentos seguidos.')],
            [__('Abaixo do nível positivo persistente'), __('2 momentos seguidos, incluindo o mais recente. É outra pergunta: não é uma tendência.')],
        ]);

        $blocks[] = $this->paragraph(__('Momentos da linha:').' '.implode(' → ', array_map(fn (array $point): string => $point['comparable'] ? $point['label'] : "({$point['label']}: fora da linha)", $t['points']))
            .'. '.$t['availability']['comparable_points'].' '.__('comparáveis, ancorados em «:label».', ['label' => $t['reference']['label']]));

        if ($t['availability']['reason'] !== null) {
            $blocks[] = $this->paragraph((string) $t['availability']['reason'], 'caution');
        }

        if ($t['left_out'] !== []) {
            $blocks[] = $this->list(array_map(fn (array $left): string => $left['label'].': '.implode(' ', array_column($left['reasons'], 'text')), $t['left_out']), __('Momentos fora da linha'));
        }

        foreach ($t['cautions'] as $caution) {
            $blocks[] = $this->paragraph((string) $caution['text'], 'caution');
        }

        // The class and the domains.
        $blocks[] = $this->heading(__('A turma e cada domínio'));

        $counts = function (array $counts): array {
            $trend = [];

            foreach (['consistent_up', 'stable', 'variable', 'consistent_down', 'insufficient'] as $state) {
                if ($counts['trend'][$state] > 0) {
                    $trend[] = $counts['trend'][$state].' '.mb_strtolower(DocumentFormat::trendWord($state));
                }
            }

            return [
                implode(' · ', $trend),
                $counts['with_latest'] === 0
                    ? __('Sem valores no momento mais recente')
                    : $counts['persistent']['yes'].' de '.DocumentFormat::students($counts['with_latest']).' '.__('com valor no momento mais recente'),
            ];
        };

        $line = fn (string $name, array $reading, array $countsBlock): array => [
            $name,
            DocumentFormat::trendWord($reading['trend']['state']).' — '.DocumentFormat::trendSteps($reading['trend']).'. '
                .($reading['cohort'] === 0 ? __('Nenhum aluno com valor em todos os momentos.') : __('Média dos :students com valor em todos os momentos.', ['students' => DocumentFormat::students($reading['cohort'])])),
            DocumentFormat::values($reading['means']),
            ...$counts($countsBlock),
        ];

        $rows = [];

        if ($t['class']['global'] !== null && $t['counts']['global'] !== null) {
            $rows[] = $line(__('Resultado global'), $t['class']['global'], $t['counts']['global']);
        }

        foreach ($t['class']['domains'] as $index => $domain) {
            $rows[] = $line((string) $domain['name'], $domain, $t['counts']['domains'][$index]);
        }

        $blocks[] = $this->table(
            [['domain', __('Domínio'), 'left'], ['trend', __('Tendência da turma'), 'left'], ['means', __('Médias dos mesmos alunos'), 'left'], ['by_trend', __('Alunos por tendência'), 'left'], ['persistent', __('Abaixo do nível positivo persistente'), 'left']],
            $rows,
        );

        // The students.
        if ($students) {
            $blocks[] = $this->heading(__('Cada aluno'));
            $blocks[] = $this->table(
                [['student', __('Aluno'), 'left'], ['values', __('Resultado global ao longo do ano'), 'left'], ['trend', __('Tendência'), 'left'], ['persistent', __('Abaixo do nível positivo persistente'), 'left']],
                array_map(function (array $row): array {
                    $reading = $row['global'];
                    $persistent = DocumentFormat::persistent($reading['persistent']);

                    if ($reading['persistent']['points'] !== []) {
                        $persistent .= ' '.implode('; ', array_map(
                            fn (array $point): string => $point['label'].': '.DocumentFormat::percent($point['value']).(($point['band']['label'] ?? null) ? ' · '.$point['band']['label'] : ''),
                            $reading['persistent']['points'],
                        ));
                    }

                    return [
                        $this->studentName($row),
                        DocumentFormat::values($reading['values']),
                        DocumentFormat::trendWord($reading['trend']['state']).' — '.DocumentFormat::trendSteps($reading['trend']),
                        trim((string) $persistent) === '' ? DocumentFormat::DASH : trim((string) $persistent),
                    ];
                }, $t['students']),
            );
        }

        return $blocks;
    }
}
