<?php

namespace App\Services\Reporting\ClassDocuments;

use App\Domain\Assessment\Bc;
use App\Services\Assessment\Export\EvaluationSheetCellText;
use App\Services\Assessment\Export\EvaluationSheetDocument;

/**
 * The factual charts of a class's grid, as data — bars with their text already
 * composed and their width already measured. The preview draws them with boxes and
 * the PDF with table cells; neither measures, rounds or words anything, so the two
 * cannot disagree (the pattern of `InstrumentGridCharts`).
 *
 * COUNTED FROM THE DOCUMENT, AND FROM NOTHING ELSE. Every figure is a count or a
 * mean over the rows of the very `EvaluationSheetDocument` the table is drawn
 * from — the same cells, the same text. So a chart of a kept moment is the chart
 * of that moment, from its frozen payload alone, and a chart can never disagree
 * with the column it summarises. There is no query, no clock and no call to the
 * statistics: the arithmetic is the statistics' own (decimal, one place, half up),
 * restated over what the sheet carries, and the tests hold the two equal wherever
 * they count the same students.
 *
 * THREE CHARTS, ALL DESCRIPTIVE, NONE A RANKING OR A TREND.
 *
 *  - Proposals: how many students the Lapispro PROPOSED each level of the scale to
 *    (the column «Proposta do Lapispro»).
 *  - Assigned: how many the TEACHER gave each level (the column «Nível atribuído»),
 *    only when somebody was given one, and never mixed with the proposals — they
 *    answer different questions (§3.3).
 *  - Means: the class mean of the global result and of each domain, on an ABSOLUTE
 *    axis from 0 to 100 — a short bar is a low mean, never a rescaled one — with
 *    the number of students it is a mean of.
 *
 * NO INTERPRETATION. No movement, no comparison with another moment or period, no
 * «good» or «bad» colour: that is the Pro reading, and it needs more than one
 * document.
 *
 * WHAT IS NOT A BAR. A student with no proposal, no level or no result is not a bar
 * at zero: they are counted in a line of their own. A result that no band of the
 * scale reaches is counted aside, so the bars are never left adding up to less than
 * the class without saying so. With nobody to draw the chart says so instead of
 * drawing empty frames; below five students a caution says the figure rests on few.
 *
 * THE LEVELS COME FROM THE SCALE THE SHEET CARRIES. A sheet kept before the scale
 * travelled with it has none, and the level charts say so instead of guessing an
 * order; the means need no scale and are always there.
 *
 * NO NAMES, NO IDENTIFIERS, NO PER-STUDENT BAR. Every figure is an aggregate.
 */
final class EvaluationSheetGridCharts
{
    /** Below this many students a figure carries a caution. */
    private const FEW = 5;

    /**
     * @return array{proposals: array<string, mixed>, assigned: array<string, mixed>|null, means: array<string, mixed>}
     */
    public function build(EvaluationSheetDocument $document): array
    {
        $proposals = [];
        $assigned = [];

        foreach ($document->students as $student) {
            /** @var array<string, mixed>|null $classification */
            $classification = $student['classification'] ?? null;

            $proposals[] = EvaluationSheetCellText::proposed($classification);
            $assigned[] = EvaluationSheetCellText::decided($classification)[0];
        }

        $anyAssigned = count(array_filter($assigned, fn (string $text): bool => $text !== '')) > 0;

        return [
            'proposals' => $this->levels(
                title: __('Distribuição dos níveis propostos'),
                basis: __('Proposta do Lapispro, por nível da escala'),
                texts: $proposals,
                scale: $document->scale,
                withLabel: __('com proposta'),
                missingLabel: __('sem proposta'),
                missingNote: __('Sem proposta'),
            ),
            // Only when somebody was given a level: before that there is nothing to
            // count, and a chart of «0 of 24» would be a drawing of an absence.
            'assigned' => $anyAssigned
                ? $this->levels(
                    title: __('Distribuição dos níveis atribuídos'),
                    basis: __('Decisão do professor, por nível da escala'),
                    texts: $assigned,
                    scale: $document->scale,
                    withLabel: __('com nível atribuído'),
                    missingLabel: __('sem nível atribuído'),
                    missingNote: __('Sem nível atribuído'),
                    ofAll: true,
                )
                : null,
            'means' => $this->means($document),
        ];
    }

    /**
     * One bar per level of the scale, in the scale's own order — or, on a scale of
     * values, one per value used, in the values' own order.
     *
     * @param  list<string>  $texts  one per student: the level or value as the column prints it, empty when there is none
     * @param  array{name?: string, kind?: string, levels?: list<array{code?: string, label?: string, sequence?: int, is_negative?: bool}>}|null  $scale
     * @return array<string, mixed>
     */
    private function levels(string $title, string $basis, array $texts, ?array $scale, string $withLabel, string $missingLabel, string $missingNote, bool $ofAll = false): array
    {
        $given = array_values(array_filter($texts, fn (string $text): bool => $text !== ''));
        $without = count($texts) - count($given);

        $chart = [
            'title' => $title,
            'basis' => $basis,
            'available' => true,
            'unavailable_message' => null,
            'n' => 0,
            'n_label' => $this->studentsLabel(0, $withLabel),
            'bars' => [],
            'missing' => $without > 0 ? [['label' => $missingLabel, 'count' => $without]] : [],
            'missing_note' => $without > 0 ? $missingNote.': '.$without : null,
            'unplaced_note' => null,
            'caution' => null,
            'empty_message' => __('Sem dados: nenhum aluno tem ainda um nível para contar.'),
        ];

        if ($scale === null || ($scale['kind'] ?? null) === null) {
            return [
                ...$chart,
                'available' => false,
                'unavailable_message' => __('Esta pauta foi guardada antes de a escala viajar com ela: o gráfico por nível não está disponível.'),
                'missing' => [],
                'missing_note' => null,
            ];
        }

        $counts = [];
        $unplaced = 0;

        if ($scale['kind'] === 'level') {
            $bands = [];

            foreach ($scale['levels'] ?? [] as $level) {
                $code = (string) ($level['code'] ?? '');
                $label = (string) ($level['label'] ?? '');
                $bands[] = [
                    'key' => $code !== '' ? $code : $label,
                    'code' => $code,
                    'label' => $label,
                    'negative' => (bool) ($level['is_negative'] ?? false),
                ];
            }

            $indexOf = [];

            foreach ($bands as $index => $band) {
                $indexOf[$band['code']] ??= $index;
                $indexOf[$band['label']] ??= $index;
                $counts[$index] = 0;
            }

            foreach ($given as $text) {
                if (array_key_exists($text, $indexOf)) {
                    $counts[$indexOf[$text]]++;
                } else {
                    $unplaced++;
                }
            }

            // EVERY BAND GETS A BAR, including the ones nobody is in: «nível 2: 0» is
            // an answer, and a missing row would draw a different chart for every class.
            $rows = array_map(fn (array $band, int $index): array => [
                'label' => $band['code'] !== '' && $band['code'] !== $band['label'] ? $band['code'].' · '.$band['label'] : $band['label'],
                'count' => $counts[$index],
                'negative' => $band['negative'],
            ], $bands, array_keys($bands));
        } else {
            // A scale of values: the grade IS the number, and one bar per grade used.
            $byValue = [];

            foreach ($given as $text) {
                $byValue[$text] = ($byValue[$text] ?? 0) + 1;
            }

            uksort($byValue, fn (int|string $first, int|string $second): int => is_numeric(rtrim((string) $first, '%')) && is_numeric(rtrim((string) $second, '%'))
                ? (float) rtrim((string) $first, '%') <=> (float) rtrim((string) $second, '%')
                : strcmp((string) $first, (string) $second));

            $rows = [];

            foreach ($byValue as $value => $count) {
                $rows[] = ['label' => (string) $value, 'count' => $count, 'negative' => false];
            }
        }

        $placed = array_sum(array_column($rows, 'count'));

        $chart['n'] = $placed;
        $chart['n_label'] = $ofAll
            ? $this->studentsLabel($placed, $withLabel, of: count($texts))
            : $this->studentsLabel($placed, $withLabel);
        $chart['unplaced_note'] = $this->unplacedNote($unplaced);
        $chart['caution'] = $this->caution($placed);

        if ($placed === 0) {
            return $chart;
        }

        foreach ($rows as $row) {
            $percent = $this->percentage($row['count'], $placed);

            $chart['bars'][] = [
                'label' => $row['label'],
                'value_text' => $row['count'].' · '.$this->decimal($percent).' %',
                'detail' => null,
                'width' => $this->width($percent),
                'negative' => $row['negative'],
            ];
        }

        return $chart;
    }

    /**
     * The class mean of the global result and of each domain.
     *
     * A student with no value is not a zero: they do not enter the mean (§37), and
     * the global chart counts them in a line of their own. A result above 100 % — a
     * bonus — enters the mean as it is and is counted aside, with the bar held at
     * the end of the axis.
     *
     * @return array<string, mixed>
     */
    private function means(EvaluationSheetDocument $document): array
    {
        $global = [];
        $byDomain = [];

        foreach ($document->domains as $domain) {
            $byDomain[(int) $domain['domain_id']] = [];
        }

        foreach ($document->students as $student) {
            $value = $student['overall']['normalized_value'] ?? null;

            if ($value !== null && is_numeric($value)) {
                $global[] = (string) $value;
            }

            /** @var array<array-key, array<string, mixed>> $domains */
            $domains = $student['domains'] ?? [];

            foreach ($domains as $domain) {
                $id = (int) ($domain['domain_id'] ?? 0);
                $domainValue = $domain['normalized_value'] ?? null;

                if (array_key_exists($id, $byDomain) && $domainValue !== null && is_numeric($domainValue)) {
                    $byDomain[$id][] = (string) $domainValue;
                }
            }
        }

        // With a single domain its mean IS the global one: drawn once.
        $series = [['label' => __('Global'), 'values' => $global]];

        if (count($document->domains) >= 2) {
            foreach ($document->domains as $domain) {
                $series[] = ['label' => (string) ($domain['name'] ?? ''), 'values' => $byDomain[(int) $domain['domain_id']]];
            }
        }

        $bars = [];
        $smallest = null;
        $anyMean = false;
        $beyond = 0;

        foreach ($series as $entry) {
            $count = count($entry['values']);
            $mean = $this->mean($entry['values']);

            if ($count > 0) {
                $smallest = $smallest === null ? $count : min($smallest, $count);
            }

            if ($mean === null) {
                $bars[] = [
                    'label' => $entry['label'],
                    'value_text' => '—',
                    'detail' => __('sem resultados'),
                    'width' => null,
                    'negative' => false,
                ];

                continue;
            }

            $anyMean = true;

            foreach ($entry['values'] as $value) {
                if (Bc::compare(Bc::of($value), '100') > 0) {
                    $beyond++;
                }
            }

            $bars[] = [
                'label' => $entry['label'],
                'value_text' => $this->decimal($mean).' %',
                'detail' => $this->studentsLabel($count, __('com resultado')),
                'width' => $this->width($mean),
                'negative' => false,
            ];
        }

        $withoutGlobal = count($document->students) - count($global);

        return [
            'title' => __('Média da turma'),
            'basis' => count($series) > 1 ? __('Global e por domínio') : __('Global'),
            'axis' => __('Escala absoluta de 0 a 100 %.'),
            'bars' => $anyMean ? $bars : [],
            'missing' => $withoutGlobal > 0 ? [['label' => __('sem resultado global'), 'count' => $withoutGlobal]] : [],
            'missing_note' => $withoutGlobal > 0 ? __('Sem resultado global').': '.$withoutGlobal : null,
            'unplaced_note' => $this->beyondNote($beyond),
            'caution' => $smallest !== null && $smallest < self::FEW
                ? __('Poucos alunos com resultado (n = :n): leia com cautela.', ['n' => $smallest])
                : null,
            'empty_message' => __('Sem resultados: nenhum aluno tem ainda um resultado calculado.'),
        ];
    }

    /**
     * A level the scale does not have — a decision taken on a scale that has since
     * changed, say — is a student WITH a level that is in no bar. Left unsaid, the
     * bars would add up to less than the class.
     */
    private function unplacedNote(int $count): ?string
    {
        if ($count === 0) {
            return null;
        }

        if ($count === 1) {
            return __('1 aluno com um nível que não consta da escala desta pauta não aparece em nenhuma barra.');
        }

        return __(':count alunos com um nível que não consta da escala desta pauta não aparecem em nenhuma barra.', ['count' => $count]);
    }

    private function beyondNote(int $count): ?string
    {
        if ($count === 0) {
            return null;
        }

        if ($count === 1) {
            return __('1 resultado acima de 100 % (por exemplo, por efeito de bónus) entra na média e a barra fica no fim do eixo.');
        }

        return __(':count resultados acima de 100 % (por exemplo, por efeito de bónus) entram na média e a barra fica no fim do eixo.', ['count' => $count]);
    }

    /**
     * @param  list<string>  $values
     */
    private function mean(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $total = '0';

        foreach ($values as $value) {
            $total = Bc::add($total, Bc::of($value));
        }

        // The statistics' own arithmetic: decimal, one place, half up.
        return Bc::round(Bc::div($total, (string) count($values)), 1, 'half_up');
    }

    private function percentage(int $count, int $total): string
    {
        return Bc::round(Bc::mul(Bc::div((string) $count, (string) $total), '100'), 1, 'half_up');
    }

    /** «n = 24 alunos com resultado» — or «n = 20 de 24 alunos com nível atribuído». */
    private function studentsLabel(int $count, string $what, ?int $of = null): string
    {
        $number = $of === null ? (string) $count : $count.' '.__('de').' '.$of;

        return 'n = '.$number.' '.($of === null && $count === 1 ? __('aluno') : __('alunos')).' '.$what;
    }

    private function caution(int $placed): ?string
    {
        if ($placed === 0 || $placed >= self::FEW) {
            return null;
        }

        return __('Poucos alunos (n = :n): leia com cautela.', ['n' => $placed]);
    }

    /** «72.4» is «72,4». */
    private function decimal(string $value): string
    {
        return str_replace('.', ',', $value);
    }

    /**
     * A bar's length, 0 to 100 on an absolute axis, to one decimal: a value past
     * either end is held at it, never rescaled.
     */
    private function width(string $value): string
    {
        $number = Bc::of($value);

        if (Bc::compare($number, '0') < 0) {
            return '0.0';
        }

        if (Bc::compare($number, '100') > 0) {
            return '100.0';
        }

        return Bc::truncate($number, 1);
    }
}
