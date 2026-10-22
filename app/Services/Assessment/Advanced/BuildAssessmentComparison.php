<?php

namespace App\Services\Assessment\Advanced;

use App\Domain\Assessment\Bc;
use App\Services\Assessment\BuildResultsProgression;
use App\Support\Entitlements\Entitlements;

/**
 * Two moments of a class, read side by side: «como estava» e «como está».
 *
 * A READING OF THE TIMELINE, WITH NO ARITHMETIC OF ITS OWN BEYOND ADDING AND DIVIDING. Every
 * figure comes from `BuildAssessmentTimeline` (and so from the progression and the frozen
 * photographs); every movement is `BuildResultsProgression::movement()`, the rule Resultados and
 * Estatística use; every change of level is `TimelineMovement`'s. What this class adds is the
 * class-level aggregation — the mean of a set of results (the same sum-and-divide, rounded the
 * same way, as `BuildClassStatistics`) and a count of who went up, stayed or went down.
 *
 * LIKE WITH LIKE. The class's evolution is the mean of the SAME students at both moments — those
 * who have a result in both. A mean over everyone at each end would change when a student is
 * absent from one of them, and that is not the class evolving. Each moment's own mean (and how
 * many students it rests on) is still shown, apart.
 *
 * NO ARTIFICIAL COMPARISON. When the two moments are not demonstrably under the same criteria,
 * the figures are shown side by side and NOT subtracted: no movement, no counts, and the reason
 * in words. The same for levels, on the scale. A student with a result at one end only is listed,
 * counted as «sem comparação» and never as zero.
 *
 * NOT A VERDICT. «Progrediu» is the rule the application already has — any change at the
 * precision shown — and the class page says so. This class draws no conclusion about why, and
 * attaches no judgement to a number.
 *
 * PRO. Like the timeline it reads, the service refuses an organization without
 * `advanced_analytics`.
 */
class BuildAssessmentComparison
{
    /** Below this many students in BOTH moments, the comparison carries a caution (as the timeline's). */
    public const FEW_STUDENTS = BuildAssessmentTimeline::FEW_STUDENTS;

    public function __construct(protected Entitlements $entitlements) {}

    /**
     * @param  array<string, mixed>  $timeline  as `BuildAssessmentTimeline::for()` wrote it
     * @param  string|null  $fromKey  a point key (`period:…` / `moment:…`), or null for the default
     * @return array<string, mixed>
     *
     * @throws AdvancedAnalyticsUnavailable
     */
    public function between(array $timeline, ?string $fromKey = null, ?string $toKey = null): array
    {
        if (! $this->entitlements->allows(BuildAssessmentTimeline::ANALYTICS_MODULE)) {
            throw new AdvancedAnalyticsUnavailable;
        }

        $withResults = array_values(array_filter($timeline['points'], fn (array $point): bool => $point['has_results']));
        $options = array_map(fn (array $point): array => $this->pointInfo($point), $withResults);

        if (count($withResults) < BuildAssessmentTimeline::MINIMUM_FOR_COMPARISON) {
            return $this->unavailable($timeline, $options);
        }

        [$from, $to, $selection] = $this->select($withResults, $fromKey, $toKey);

        $comparability = TimelineComparability::between($this->signatures($from), $this->signatures($to));
        $students = $this->students($timeline, $from['key'], $to['key'], $comparability);

        $paired = array_values(array_filter($students, fn (array $student): bool => $student['pair']['both']));

        $cautions = [];
        if (count($paired) < self::FEW_STUDENTS) {
            $cautions[] = [
                'code' => 'few_students',
                'text' => __('A comparação assenta em apenas :count aluno(s) com resultado nos dois momentos: leia as variações com cautela.', ['count' => count($paired)]),
            ];
        }
        foreach ([$from, $to] as $point) {
            if ($point['few_students']) {
                $cautions[] = [
                    'code' => 'few_students_point',
                    'text' => __('«:label» tem resultados de menos de :min alunos.', ['label' => $point['label'], 'min' => self::FEW_STUDENTS]),
                ];
            }
        }

        return [
            'state' => 'ready',
            'reason' => null,
            'scale' => $timeline['scale'],
            'options' => $options,
            'selection' => $selection,
            'from' => $this->pointInfo($from),
            'to' => $this->pointInfo($to),
            'comparability' => $comparability,
            'basis_note' => $this->basisNote($from, $to),
            'cautions' => $cautions,
            'notices' => $this->noticesOf($timeline, [$from, $to]),
            'class' => $this->classBlock($students, $comparability),
            'domains' => $this->domainBlocks($timeline, $students, $from['key'], $to['key'], $comparability),
            'levels' => $this->levelsBlock($timeline, $students, $comparability),
            'students' => array_map(fn (array $student): array => $this->publicStudent($student), $students),
            'explanations' => $this->explanations(),
        ];
    }

    // ================================================================= selecting the two moments

    /**
     * @param  list<array<string, mixed>>  $withResults  in timeline order
     * @return array{array<string, mixed>, array<string, mixed>, array{from: string, to: string, defaulted: bool, swapped: bool}}
     */
    private function select(array $withResults, ?string $fromKey, ?string $toKey): array
    {
        $byKey = [];
        foreach ($withResults as $point) {
            $byKey[$point['key']] = $point;
        }

        if ($fromKey !== null && $toKey !== null && $fromKey !== $toKey && isset($byKey[$fromKey], $byKey[$toKey])) {
            $from = $byKey[$fromKey];
            $to = $byKey[$toKey];
            $swapped = $from['ordinal'] > $to['ordinal'];

            if ($swapped) {
                [$from, $to] = [$to, $from];
            }

            return [$from, $to, ['from' => $from['key'], 'to' => $to['key'], 'defaulted' => false, 'swapped' => $swapped]];
        }

        // The default: the latest moment with results, against the nearest earlier one it may be
        // compared with — and, when none may, the nearest earlier one, so the page says why not.
        $to = $withResults[count($withResults) - 1];
        $earlier = array_reverse(array_slice($withResults, 0, -1));
        $from = $earlier[0];

        foreach ($earlier as $candidate) {
            if (TimelineComparability::between($this->signatures($candidate), $this->signatures($to))['percentages']) {
                $from = $candidate;
                break;
            }
        }

        return [$from, $to, ['from' => $from['key'], 'to' => $to['key'], 'defaulted' => true, 'swapped' => false]];
    }

    /**
     * The two signatures of a point, in the shape the comparability reads.
     *
     * @param  array<string, mixed>  $point
     * @return array{percentage_signature: string|null, level_signature: string|null}
     */
    private function signatures(array $point): array
    {
        return [
            'percentage_signature' => isset($point['percentage_signature']) ? (string) $point['percentage_signature'] : null,
            'level_signature' => isset($point['level_signature']) ? (string) $point['level_signature'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $point
     * @return array<string, mixed>
     */
    private function pointInfo(array $point): array
    {
        return [
            'key' => $point['key'],
            'kind' => $point['kind'],
            'label' => $point['label'],
            'basis' => $point['basis'],
            'ordinal' => $point['ordinal'],
            'students_total' => $point['students_total'],
            'students_with_result' => $point['students_with_result'],
            'partial_coverage' => $point['partial_coverage'],
            'few_students' => $point['few_students'],
        ];
    }

    /**
     * @param  array<string, mixed>  $timeline
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>
     */
    private function unavailable(array $timeline, array $options): array
    {
        return [
            'state' => 'unavailable',
            'reason' => $timeline['availability']['reason'],
            'scale' => $timeline['scale'],
            'options' => $options,
            'selection' => ['from' => null, 'to' => null, 'defaulted' => true, 'swapped' => false],
            'from' => null,
            'to' => null,
            'comparability' => null,
            'basis_note' => null,
            'cautions' => [],
            'notices' => $timeline['notices'],
            'class' => null,
            'domains' => [],
            'levels' => null,
            'students' => [],
            'explanations' => $this->explanations(),
        ];
    }

    /**
     * Said when the two moments are not read the same way: a kept photograph against today's
     * recalculation absorbs every correction made since.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    private function basisNote(array $from, array $to): string
    {
        $kinds = [$from['basis']['kind'], $to['basis']['kind']];

        if ($kinds[0] === $kinds[1] && $kinds[0] === 'frozen') {
            return __('Os dois momentos são fotografias guardadas: nenhum número se altera com correções posteriores.');
        }

        if ($kinds[0] === $kinds[1]) {
            return __('Os dois momentos são calculados com os dados de hoje: se uma nota for corrigida, os dois podem mudar.');
        }

        return __('Um dos momentos é uma fotografia guardada e o outro é calculado com os dados de hoje: a diferença inclui as correções feitas entretanto.');
    }

    /**
     * @param  array<string, mixed>  $timeline
     * @param  list<array<string, mixed>>  $points
     * @return list<array<string, mixed>>
     */
    private function noticesOf(array $timeline, array $points): array
    {
        $notices = [];

        foreach ($points as $point) {
            foreach ($point['notices'] ?? [] as $notice) {
                $notices[] = [...$notice, 'moment' => $point['label']];
            }
        }

        foreach ($timeline['notices'] as $notice) {
            $notices[] = $notice;
        }

        return $notices;
    }

    // ================================================================= students

    /**
     * Every student of the class at the two moments, with what moved.
     *
     * @param  array<string, mixed>  $timeline
     * @param  array{percentages: bool, levels: bool, reasons: list<array{code: string, text: string}>}  $comparability
     * @return list<array<string, mixed>>
     */
    private function students(array $timeline, string $fromKey, string $toKey, array $comparability): array
    {
        $rows = [];

        foreach ($timeline['students'] as $student) {
            $before = $student['cells'][$fromKey] ?? null;
            $after = $student['cells'][$toKey] ?? null;

            $beforeResult = $before['result'] ?? null;
            $afterResult = $after['result'] ?? null;
            $hasBefore = ($beforeResult['value'] ?? null) !== null;
            $hasAfter = ($afterResult['value'] ?? null) !== null;

            $domains = [];
            foreach ($timeline['domains'] as $domain) {
                $domainBefore = $before['domains'][$domain['ulid']] ?? null;
                $domainAfter = $after['domains'][$domain['ulid']] ?? null;
                $movement = TimelineMovement::between($domainBefore, $domainAfter, $comparability);

                $domains[$domain['ulid']] = [
                    'from' => $domainBefore['value'] ?? null,
                    'to' => $domainAfter['value'] ?? null,
                    'movement' => $this->movementOf($domainBefore['value'] ?? null, $domainAfter['value'] ?? null, $movement),
                ];
            }

            $resultMovement = TimelineMovement::between($beforeResult, $afterResult, $comparability);

            $rows[] = [
                'enrollment_ulid' => $student['enrollment_ulid'],
                'name' => $student['name'],
                'class_number' => $student['class_number'],
                'from' => $beforeResult,
                'to' => $afterResult,
                'movement' => $this->movementOf($beforeResult['value'] ?? null, $afterResult['value'] ?? null, $resultMovement),
                'result_level' => $resultMovement['level'],
                'assigned' => $this->assigned($before['classification'] ?? null, $after['classification'] ?? null, $comparability),
                'domains' => $domains,
                'pair' => ['both' => $hasBefore && $hasAfter, 'from' => $hasBefore, 'to' => $hasAfter],
            ];
        }

        usort($rows, fn (array $first, array $second): int => [$first['class_number'] ?? PHP_INT_MAX, $first['name']] <=> [$second['class_number'] ?? PHP_INT_MAX, $second['name']]);

        return $rows;
    }

    /**
     * A movement of a percentage, or the reason there is none.
     *
     * @param  array{comparable: bool, percentages: array<string, string>|null, level: array<string, mixed>}  $movement
     * @return array{state: string, points: string|null, reason: string|null}
     */
    private function movementOf(?string $before, ?string $after, array $movement): array
    {
        if ($before === null || $after === null) {
            return ['state' => 'unavailable', 'points' => null, 'reason' => match (true) {
                $before === null && $after === null => 'missing_both',
                $before === null => 'missing_from',
                default => 'missing_to',
            }];
        }

        if (! $movement['comparable'] || $movement['percentages'] === null) {
            return ['state' => 'unavailable', 'points' => null, 'reason' => 'not_comparable'];
        }

        return ['state' => $movement['percentages']['direction'], 'points' => $movement['percentages']['points'], 'reason' => null];
    }

    /**
     * What the teacher assigned, at each end, and how it changed.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array{percentages: bool, levels: bool, reasons: list<array{code: string, text: string}>}  $comparability
     * @return array<string, mixed>
     */
    private function assigned(?array $before, ?array $after, array $comparability): array
    {
        return [
            'from' => $before['assigned'] ?? null,
            'to' => $after['assigned'] ?? null,
            'from_value' => $before['assigned_value'] ?? null,
            'to_value' => $after['assigned_value'] ?? null,
            'change' => TimelineMovement::classification($before, $after, $comparability),
        ];
    }

    /**
     * What leaves the server for a student: not the working fields.
     *
     * @param  array<string, mixed>  $student
     * @return array<string, mixed>
     */
    private function publicStudent(array $student): array
    {
        unset($student['pair']);

        return $student;
    }

    // ================================================================= the class

    /**
     * @param  list<array<string, mixed>>  $students
     * @param  array{percentages: bool, levels: bool, reasons: list<array{code: string, text: string}>}  $comparability
     * @return array<string, mixed>
     */
    private function classBlock(array $students, array $comparability): array
    {
        $fromValues = [];
        $toValues = [];
        $pairedFrom = [];
        $pairedTo = [];
        $partial = ['from' => 0, 'to' => 0];

        foreach ($students as $student) {
            if ($student['pair']['from']) {
                $fromValues[] = $student['from']['value'];
                $partial['from'] += $student['from']['coverage_warning'] ? 1 : 0;
            }
            if ($student['pair']['to']) {
                $toValues[] = $student['to']['value'];
                $partial['to'] += $student['to']['coverage_warning'] ? 1 : 0;
            }
            if ($student['pair']['both']) {
                $pairedFrom[] = $student['from']['value'];
                $pairedTo[] = $student['to']['value'];
            }
        }

        $pairedFromMean = $this->mean($pairedFrom);
        $pairedToMean = $this->mean($pairedTo);

        return [
            'moments' => [
                'from' => ['students' => count($fromValues), 'mean' => $this->mean($fromValues), 'partial_coverage' => $partial['from']],
                'to' => ['students' => count($toValues), 'mean' => $this->mean($toValues), 'partial_coverage' => $partial['to']],
            ],
            'paired' => [
                'students' => count($pairedFrom),
                'from_mean' => $pairedFromMean,
                'to_mean' => $pairedToMean,
                'change' => $comparability['percentages'] ? BuildResultsProgression::movement($pairedFromMean, $pairedToMean) : null,
            ],
            'counts' => $comparability['percentages'] ? $this->counts($students, fn (array $student): array => $student['movement']) : null,
            'not_compared' => $this->notCompared($students),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $students
     * @param  callable(array<string, mixed>): array<string, mixed>  $movement
     * @return array{up: int, flat: int, down: int, compared: int}
     */
    private function counts(array $students, callable $movement): array
    {
        $counts = ['up' => 0, 'flat' => 0, 'down' => 0];

        foreach ($students as $student) {
            $state = $movement($student)['state'];

            if (isset($counts[$state])) {
                $counts[$state]++;
            }
        }

        return [...$counts, 'compared' => array_sum($counts)];
    }

    /**
     * Students that cannot be compared, and why: a result at one end only is not a zero.
     *
     * @param  list<array<string, mixed>>  $students
     * @return array{missing_from: int, missing_to: int, missing_both: int}
     */
    private function notCompared(array $students): array
    {
        $counts = ['missing_from' => 0, 'missing_to' => 0, 'missing_both' => 0];

        foreach ($students as $student) {
            $reason = $student['movement']['reason'];

            if (isset($counts[$reason])) {
                $counts[$reason]++;
            }
        }

        return $counts;
    }

    /**
     * The same sum-and-divide, rounded the same way, as `BuildClassStatistics::mean()`.
     *
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

        return Bc::round(Bc::div($total, (string) count($values)), BuildResultsProgression::PRECISION, 'half_up');
    }

    // ================================================================= the domains

    /**
     * @param  array<string, mixed>  $timeline
     * @param  list<array<string, mixed>>  $students
     * @param  array{percentages: bool, levels: bool, reasons: list<array{code: string, text: string}>}  $comparability
     * @return list<array<string, mixed>>
     */
    private function domainBlocks(array $timeline, array $students, string $fromKey, string $toKey, array $comparability): array
    {
        $blocks = [];

        foreach ($timeline['domains'] as $domain) {
            $fromValues = [];
            $toValues = [];
            $pairedFrom = [];
            $pairedTo = [];

            foreach ($students as $student) {
                $cell = $student['domains'][$domain['ulid']];

                if ($cell['from'] !== null) {
                    $fromValues[] = $cell['from'];
                }
                if ($cell['to'] !== null) {
                    $toValues[] = $cell['to'];
                }
                if ($cell['from'] !== null && $cell['to'] !== null) {
                    $pairedFrom[] = $cell['from'];
                    $pairedTo[] = $cell['to'];
                }
            }

            $pairedFromMean = $this->mean($pairedFrom);
            $pairedToMean = $this->mean($pairedTo);

            $blocks[] = [
                'ulid' => $domain['ulid'],
                'name' => $domain['name'],
                'weight_percent' => $domain['weight_percent'],
                'moments' => [
                    'from' => ['students' => count($fromValues), 'mean' => $this->mean($fromValues)],
                    'to' => ['students' => count($toValues), 'mean' => $this->mean($toValues)],
                ],
                'paired' => [
                    'students' => count($pairedFrom),
                    'from_mean' => $pairedFromMean,
                    'to_mean' => $pairedToMean,
                    'change' => $comparability['percentages'] ? BuildResultsProgression::movement($pairedFromMean, $pairedToMean) : null,
                ],
                'counts' => $comparability['percentages']
                    ? $this->counts($students, fn (array $student): array => $student['domains'][$domain['ulid']]['movement'])
                    : null,
            ];
        }

        return $blocks;
    }

    // ================================================================= the levels

    /**
     * The change of LEVEL, of the result and of what the teacher assigned — only on a scale shown
     * to be the same at both ends.
     *
     * @param  array<string, mixed>  $timeline
     * @param  list<array<string, mixed>>  $students
     * @param  array{percentages: bool, levels: bool, reasons: list<array{code: string, text: string}>}  $comparability
     * @return array<string, mixed>
     */
    private function levelsBlock(array $timeline, array $students, array $comparability): array
    {
        $reasons = array_values(array_filter($comparability['reasons'], fn (array $reason): bool => in_array($reason['code'], [TimelineComparability::SCALE_UNKNOWN, TimelineComparability::SCALE_DIFFERS], true)));

        if (! $comparability['levels']) {
            return ['comparable' => false, 'reasons' => $reasons, 'result' => null, 'assigned' => null];
        }

        return [
            'comparable' => true,
            'reasons' => [],
            'result' => $this->levelSummary($timeline, $students, fn (array $student): array => $student['result_level'], fn (array $student): array => [$student['from']['band'] ?? null, $student['to']['band'] ?? null]),
            'assigned' => $this->levelSummary($timeline, $students, fn (array $student): array => $student['assigned']['change'], fn (array $student): array => [$student['assigned']['from'], $student['assigned']['to']]),
        ];
    }

    /**
     * @param  array<string, mixed>  $timeline
     * @param  list<array<string, mixed>>  $students
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     * @param  callable(array<string, mixed>): array{0: array<string, mixed>|null, 1: array<string, mixed>|null}  $levels
     * @return array<string, mixed>
     */
    private function levelSummary(array $timeline, array $students, callable $change, callable $levels): array
    {
        $counts = ['up' => 0, 'same' => 0, 'down' => 0];
        $crossed = ['to_positive' => 0, 'to_negative' => 0];
        $unavailable = 0;

        $distribution = [];
        foreach ($timeline['scale']['levels'] ?? [] as $level) {
            $distribution[(string) $level['code']] = [
                'code' => (string) $level['code'],
                'label' => (string) $level['label'],
                'sequence' => (int) $level['sequence'],
                'is_negative' => (bool) $level['is_negative'],
                'from' => 0,
                'to' => 0,
            ];
        }

        foreach ($students as $student) {
            $verdict = $change($student);

            if (isset($counts[$verdict['state']])) {
                $counts[$verdict['state']]++;
            } else {
                $unavailable++;
            }

            if ($verdict['crossed_line'] !== null && isset($crossed[$verdict['crossed_line']])) {
                $crossed[$verdict['crossed_line']]++;
            }

            [$before, $after] = $levels($student);

            if (($before['code'] ?? null) !== null && isset($distribution[(string) $before['code']])) {
                $distribution[(string) $before['code']]['from']++;
            }
            if (($after['code'] ?? null) !== null && isset($distribution[(string) $after['code']])) {
                $distribution[(string) $after['code']]['to']++;
            }
        }

        $ordered = array_values($distribution);
        usort($ordered, fn (array $first, array $second): int => $first['sequence'] <=> $second['sequence']);

        return [
            'counts' => [...$counts, 'compared' => array_sum($counts), 'not_compared' => $unavailable],
            'crossed' => $crossed,
            'distribution' => $ordered,
        ];
    }

    // ================================================================= the words

    /**
     * «Como se calcula», in the words of the rules the application already has.
     *
     * @return list<array{key: string, title: string, text: string}>
     */
    private function explanations(): array
    {
        return [
            ['key' => 'movement', 'title' => __('O que é «progrediu», «manteve-se» e «regrediu»'), 'text' => __('Compara-se o resultado de cada aluno nos dois momentos, na casa decimal em que é mostrado. Se subiu, progrediu; se desceu, regrediu; se é igual, manteve-se. É a mesma regra de Resultados e de Estatística — não há uma margem escolhida à parte, por isso uma variação de 0,1 já conta.')],
            ['key' => 'points', 'title' => __('Variação em pontos percentuais'), 'text' => __('A variação é a diferença entre as duas percentagens (p.p.). De 62,0 % para 70,5 % são 8,5 p.p. Não se calcula uma percentagem da percentagem.')],
            ['key' => 'class', 'title' => __('Evolução da turma'), 'text' => __('É a média dos mesmos alunos nos dois momentos — os que têm resultado em ambos. Assim a média não muda só porque um aluno falta num dos extremos. A média de cada momento, com todos os que nele têm resultado, aparece à parte.')],
            ['key' => 'domains', 'title' => __('Por domínio'), 'text' => __('O mesmo cálculo, domínio a domínio, sobre os alunos com valor nesse domínio nos dois momentos.')],
            ['key' => 'levels', 'title' => __('Mudanças de nível'), 'text' => __('Um nível «subiu» quando está mais acima na ordem da escala; «passou a positivo» ou «a negativo» quando atravessa a linha que a própria escola definiu. Só se comparam níveis quando se demonstra que a escala é a mesma nos dois momentos.')],
            ['key' => 'comparable', 'title' => __('Quando não se compara'), 'text' => __('Se os domínios, os pesos ou a escala mudaram entre os dois momentos, os números aparecem lado a lado mas não se subtraem: a diferença seria de regra, não de alunos. A página diz sempre porquê.')],
            ['key' => 'basis', 'title' => __('De onde vêm os dados'), 'text' => __('Um período é calculado com os dados de hoje; um momento guardado é a fotografia do que o professor guardou e nunca muda. A página indica qual é qual.')],
        ];
    }
}
