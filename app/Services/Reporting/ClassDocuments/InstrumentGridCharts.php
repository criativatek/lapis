<?php

namespace App\Services\Reporting\ClassDocuments;

use App\Domain\Assessment\Bc;
use App\Models\Enrollment;
use App\Models\InstrumentItem;
use App\Models\ResultState;
use App\Models\StudentItemScore;

/**
 * The factual charts of a CONCLUDED instrument's grid, as data — bars with their
 * text already composed and their width already measured. The preview draws them
 * with boxes and the PDF with table cells; neither measures, rounds or words
 * anything, so the two cannot disagree.
 *
 * THREE CHARTS, ALL DESCRIPTIVE, NONE A RANKING.
 *
 *  - Distribution: how many students fall in each LEVEL of the scale, in the
 *    scale's own order — or, when the scale has no bands, in the ten classes of
 *    ten points. It is `ResultsAnalyzer`'s own `qualitative`/`quantitative` block,
 *    the very figures Resultados shows; this class only words them.
 *  - Mean by domain: `ResultsAnalyzer`'s mean of each dimension on an ABSOLUTE axis
 *    from 0 to 100 — a short bar is a low mean, never a rescaled one — with the
 *    number of students it is a mean of. Domains only when there are two or more;
 *    with one, its figure IS the global.
 *  - Mean by question: the average of the points earned over the points possible,
 *    among the students who were assessed on it. THE ONE FIGURE THAT IS NEW here —
 *    and it is not a result: the engine never reads it. It is a plain mean of the
 *    assessed cells in decimal arithmetic. An absence, an exemption or a mark still
 *    to give is not a zero: it does not enter the mean and is counted beside it as
 *    COVERAGE («20 de 24 avaliados · 2 ausentes»), so a question few students were
 *    assessed on says so.
 *
 * WHAT IS NOT A BAR. A student without a result is not a bar at zero: the missing
 * are counted in a line of their own. A question with nobody assessed, or without
 * points to measure against, has no bar at all (`width` is null), only its coverage.
 * With nobody to draw, the chart says so instead of drawing empty frames.
 *
 * NO NAMES, NO IDENTIFIERS, NO PER-STUDENT BAR. Every figure is an aggregate; below
 * five students a caution says the figure rests on few.
 */
final class InstrumentGridCharts
{
    /** Below this many students a figure carries a caution. */
    private const FEW = 5;

    /** The labels of the students a distribution does not place, by the analyzer's key. */
    private const MISSING = [
        'pending' => ['por avaliar', 'por avaliar'],
        'under_review' => ['em revisão', 'em revisão'],
        'absent' => ['ausente', 'ausentes'],
        'absent_justified' => ['ausência justificada', 'ausências justificadas'],
        'exempt' => ['dispensado', 'dispensados'],
        'not_applicable' => ['não aplicável', 'não aplicáveis'],
        'annulled' => ['anulado', 'anulados'],
    ];

    /**
     * @param  list<array{key: string, label: string, analysis: array<string, mixed>}>  $dimensions  BuildResultsAnalysis::dimensionsAnalysis()
     * @param  list<InstrumentItem>  $items
     * @param  list<Enrollment>  $roster  the students the instrument applies to
     * @param  array<int, array<int, StudentItemScore>>  $scores  by enrolment, then by item
     * @return array<string, mixed>
     */
    public function build(array $dimensions, ?string $threshold, array $items, array $roster, array $scores): array
    {
        return [
            'distribution' => $this->distribution($dimensions[0]['analysis'] ?? null),
            'means' => $this->means($dimensions, $threshold),
            'questions' => $this->questions($items, $roster, $scores),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $analysis  the global dimension
     * @return array<string, mixed>
     */
    protected function distribution(?array $analysis): array
    {
        $classified = (int) ($analysis['classified'] ?? 0);
        $levels = (bool) ($analysis['qualitative']['available'] ?? false);

        $chart = [
            'title' => __('Distribuição dos resultados'),
            'basis' => $levels ? __('Por nível da escala') : __('Por intervalos de 10 pontos percentuais'),
            'n' => $classified,
            'n_label' => $this->studentsLabel($classified, __('com resultado')),
            'bars' => [],
            'missing' => [],
            'missing_note' => null,
            'caution' => $this->caution($classified),
            'empty_message' => __('Sem resultados: nenhum aluno tem ainda um resultado calculado.'),
        ];

        if ($analysis === null || $classified === 0) {
            return [...$chart, 'missing' => $this->missing($analysis), 'missing_note' => $this->missingNote($analysis)];
        }

        $source = $levels
            ? array_map(fn (array $category): array => [
                'label' => (string) $category['label'],
                'count' => (int) $category['count'],
                'percent' => $category['percent'],
                'negative' => (bool) $category['is_negative'],
            ], $analysis['qualitative']['categories'])
            : array_map(fn (array $class): array => [
                'label' => (string) $class['label'],
                'count' => (int) $class['count'],
                'percent' => $class['percent'],
                'negative' => (bool) $class['below_threshold'],
            ], $analysis['quantitative']['classes']);

        foreach ($source as $bar) {
            $percent = (string) ($bar['percent'] ?? '0.0');

            $chart['bars'][] = [
                'label' => $bar['label'],
                'value_text' => $bar['count'].' · '.$this->decimal($percent).' %',
                'detail' => null,
                'width' => $this->width($percent),
                'negative' => $bar['negative'],
            ];
        }

        return [...$chart, 'missing' => $this->missing($analysis), 'missing_note' => $this->missingNote($analysis)];
    }

    /**
     * The students without a result, as counts: never a bar.
     *
     * @param  array<string, mixed>|null  $analysis
     * @return list<array{label: string, count: int}>
     */
    protected function missing(?array $analysis): array
    {
        $missing = [];

        foreach (self::MISSING as $key => [$one, $many]) {
            $count = (int) ($analysis['missing'][$key] ?? 0);

            if ($count > 0) {
                $missing[] = ['label' => $count === 1 ? $one : $many, 'count' => $count];
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>|null  $analysis
     */
    protected function missingNote(?array $analysis): ?string
    {
        $missing = $this->missing($analysis);

        if ($missing === []) {
            return null;
        }

        return __('Sem resultado:').' '.implode(' · ', array_map(fn (array $item): string => $item['count'].' '.$item['label'], $missing));
    }

    /**
     * @param  list<array{key: string, label: string, analysis: array<string, mixed>}>  $dimensions
     * @return array<string, mixed>
     */
    protected function means(array $dimensions, ?string $threshold): array
    {
        $domains = array_values(array_filter($dimensions, fn (array $dimension): bool => $dimension['key'] !== 'global'));
        // With a single domain its mean IS the global one: drawn once.
        $drawn = count($domains) >= 2 ? $dimensions : array_slice($dimensions, 0, 1);

        $bars = [];
        $smallest = null;
        $anyMean = false;

        foreach ($drawn as $dimension) {
            $analysis = $dimension['analysis'];
            $classified = (int) ($analysis['classified'] ?? 0);
            $mean = $analysis['mean'] ?? null;

            if ($classified > 0) {
                $smallest = $smallest === null ? $classified : min($smallest, $classified);
            }

            if ($mean === null) {
                $bars[] = [
                    'label' => $dimension['key'] === 'global' ? __('Global') : $dimension['label'],
                    'value_text' => '—',
                    'detail' => __('sem resultados'),
                    'width' => null,
                    'negative' => false,
                ];

                continue;
            }

            $anyMean = true;
            $bars[] = [
                'label' => $dimension['key'] === 'global' ? __('Global') : $dimension['label'],
                'value_text' => $this->decimal((string) $mean).' %',
                'detail' => $this->studentsLabel($classified, __('com resultado')),
                'width' => $this->width((string) $mean),
                'negative' => $threshold !== null && Bc::compare(Bc::of((string) $mean), Bc::of($threshold)) < 0,
            ];
        }

        return [
            'title' => __('Média da turma'),
            'basis' => count($drawn) > 1 ? __('Global e por domínio') : __('Global'),
            'axis' => __('Escala absoluta de 0 a 100 %.'),
            'bars' => $anyMean ? $bars : [],
            'caution' => $smallest !== null && $smallest < self::FEW
                ? __('Poucos alunos com resultado (n = :n): leia com cautela.', ['n' => $smallest])
                : null,
            'empty_message' => __('Sem resultados: nenhum aluno tem ainda um resultado calculado.'),
        ];
    }

    /**
     * @param  list<InstrumentItem>  $items
     * @param  list<Enrollment>  $roster
     * @param  array<int, array<int, StudentItemScore>>  $scores
     * @return array<string, mixed>
     */
    protected function questions(array $items, array $roster, array $scores): array
    {
        $covered = count($roster);
        $bars = [];
        $anyMean = false;

        foreach ($items as $item) {
            $assessed = 0;
            $sum = '0';
            $counts = ['absent' => 0, 'exempt' => 0, 'other' => 0, 'pending' => 0];

            foreach ($roster as $enrollment) {
                $score = $scores[(int) $enrollment->getKey()][(int) $item->getKey()] ?? null;
                $state = $score !== null ? $score->result_state : ResultState::Pending;

                if ($state === ResultState::Assessed && $score !== null && $score->points_earned !== null) {
                    $assessed++;
                    $sum = Bc::add($sum, Bc::of((string) $score->points_earned));

                    continue;
                }

                match ($state) {
                    ResultState::Absent, ResultState::AbsentJustified => $counts['absent']++,
                    ResultState::Exempt => $counts['exempt']++,
                    ResultState::Pending => $counts['pending']++,
                    // Not applicable, annulled, under review — and a cell marked
                    // assessed with no points to measure.
                    default => $counts['other']++,
                };
            }

            $possible = (string) $item->points_possible;
            $measurable = $assessed > 0 && ! Bc::isZero(Bc::of($possible));
            $mean = $measurable
                ? Bc::round(Bc::mul(Bc::div($sum, Bc::mul((string) $assessed, Bc::of($possible))), '100'), 1, 'half_up')
                : null;

            $anyMean = $anyMean || $mean !== null;
            $bars[] = [
                'label' => $item->code,
                'value_text' => $mean === null ? '—' : $this->decimal($mean).' %',
                'detail' => $this->coverage($assessed, $covered, $counts, Bc::isZero(Bc::of($possible))),
                'width' => $mean === null ? null : $this->width($mean),
                'negative' => false,
            ];
        }

        return [
            'title' => __('Média por questão'),
            'basis' => __('Pontos obtidos sobre a cotação, entre os alunos avaliados em cada questão'),
            'axis' => __('Escala absoluta de 0 a 100 % da cotação; uma questão de bónus pode passar de 100 %.'),
            'bars' => $anyMean ? $bars : [],
            'caution' => $covered > 0 && $covered < self::FEW
                ? __('Poucos alunos (n = :n): leia com cautela.', ['n' => $covered])
                : null,
            'note' => __('Ausências, dispensas e questões por avaliar não contam como zero: ficam fora da média e aparecem na cobertura.'),
            'empty_message' => __('Sem resultados: nenhuma questão tem ainda pontuações avaliadas.'),
        ];
    }

    /**
     * «20 de 24 avaliados · 2 ausentes · 1 por avaliar».
     *
     * @param  array{absent: int, exempt: int, other: int, pending: int}  $counts
     */
    protected function coverage(int $assessed, int $covered, array $counts, bool $withoutQuota): string
    {
        $parts = [$assessed === 1
            ? __(':assessed de :covered avaliado', ['assessed' => $assessed, 'covered' => $covered])
            : __(':assessed de :covered avaliados', ['assessed' => $assessed, 'covered' => $covered])];

        foreach ([
            'absent' => ['ausente', 'ausentes'],
            'exempt' => ['dispensado', 'dispensados'],
            'pending' => ['por avaliar', 'por avaliar'],
            'other' => ['outro estado', 'outros estados'],
        ] as $key => [$one, $many]) {
            if ($counts[$key] > 0) {
                $parts[] = $counts[$key].' '.($counts[$key] === 1 ? $one : $many);
            }
        }

        if ($withoutQuota) {
            $parts[] = __('sem cotação');
        }

        return implode(' · ', $parts);
    }

    /** «n = 24 alunos com resultado». */
    protected function studentsLabel(int $count, string $what): string
    {
        return 'n = '.$count.' '.($count === 1 ? __('aluno') : __('alunos')).' '.$what;
    }

    protected function caution(int $classified): ?string
    {
        if ($classified === 0 || $classified >= self::FEW) {
            return null;
        }

        return __('Poucos alunos com resultado (n = :n): leia com cautela.', ['n' => $classified]);
    }

    /** «72.4» is «72,4». */
    protected function decimal(string $value): string
    {
        return str_replace('.', ',', $value);
    }

    /**
     * A bar's length, 0 to 100 on an absolute axis, to one decimal: a value past
     * either end is held at it (a bonus can pass 100 %), never rescaled.
     */
    protected function width(string $value): string
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
