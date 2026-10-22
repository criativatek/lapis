<?php

namespace App\Services\Assessment\Advanced;

use App\Support\Entitlements\Entitlements;

/**
 * A class's trajectories over the year: the trend of the class, of each domain and of each student,
 * and who is persistently below the positive level — in two readings that are never mixed.
 *
 * TWO QUESTIONS, TWO RULES (`TrajectoryReading`).
 *  - a TREND needs at least three comparable moments with a value: consistent progression, consistent
 *    regression, stability, or variable evolution — said from the steps between values, with the
 *    steps shown;
 *  - a PERSISTENT NEGATIVE RESULT needs two: below the positive level at the most recent moment and
 *    at the one before it. It is a fact about consecutive marks, not a trend and not a finding.
 *
 * THE LINE IS ONLY THE MOMENTS THAT MAY BE PUT IN IT (`TimelineSeries`): those demonstrably under the
 * same criteria and the same scale as the most recent one. The others are listed as left out, with the
 * reason, and they break a run — nothing is read across a change of rule. A student without a value at
 * a moment has a GAP there: named, never filled, never a zero.
 *
 * THE CLASS IS READ LIKE WITH LIKE. The trend of the class and of a domain is the trend of the mean of
 * the SAME students at every moment of the line (those with a value at all of them): a mean over
 * whoever happened to have a value would change when a student is absent from one moment, and that is
 * not the class evolving. The mean over everyone, with how many it rests on, is shown beside it.
 *
 * COUNTS WITH DENOMINATORS, AND NO VERDICT. «3 de 25 alunos» — and a trajectory is a description of
 * numbers: no diagnosis, no cause, no forecast, no recommendation.
 *
 * ASSEMBLED, NEVER COMPUTED. Every value is the timeline's; every step is the rule Resultados has;
 * every band is the scale's own at that moment. This class selects, orders, counts and words.
 *
 * PRO. The service refuses an organization without `advanced_analytics`, like the timeline it reads.
 */
class BuildClassTrends
{
    /** Below this many students in the cohort, the class trend carries a caution (as the timeline's). */
    public const FEW_STUDENTS = BuildAssessmentTimeline::FEW_STUDENTS;

    public function __construct(protected Entitlements $entitlements) {}

    /**
     * @param  array<string, mixed>  $timeline  as `BuildAssessmentTimeline::for()` wrote it
     * @return array<string, mixed>
     *
     * @throws AdvancedAnalyticsUnavailable
     */
    public function for(array $timeline): array
    {
        if (! $this->entitlements->allows(BuildAssessmentTimeline::ANALYTICS_MODULE)) {
            throw new AdvancedAnalyticsUnavailable;
        }

        $series = TimelineSeries::of($timeline);

        if ($series === null) {
            return $this->unavailable($timeline['points'] === []
                ? (string) $timeline['availability']['reason']
                : __('Ainda não há resultados em nenhum momento: não há trajetórias para ler.'));
        }

        $points = $series['points'];
        $comparable = $series['comparable_points'];
        $students = $timeline['students'];

        // Each student's trajectories: the overall result and each domain.
        $rows = [];
        foreach ($students as $student) {
            $domains = [];

            foreach ($timeline['domains'] as $domain) {
                $domains[$domain['ulid']] = TrajectoryReading::read(TimelineSeries::entries(
                    $points,
                    fn (string $key): ?array => $student['cells'][$key]['domains'][$domain['ulid']] ?? null,
                ));
            }

            $rows[] = [
                'enrollment_ulid' => $student['enrollment_ulid'],
                'name' => $student['name'],
                'class_number' => $student['class_number'],
                'global' => TrajectoryReading::read(TimelineSeries::entries($points, fn (string $key): ?array => $student['cells'][$key]['result'] ?? null)),
                'domains' => $domains,
            ];
        }

        usort($rows, fn (array $first, array $second): int => [$first['class_number'] ?? PHP_INT_MAX, $first['name']] <=> [$second['class_number'] ?? PHP_INT_MAX, $second['name']]);

        $classGlobal = $this->classReading($points, $students, fn (array $cells): ?array => $cells['result'] ?? null);

        $classDomains = [];
        $counts = [];
        foreach ($timeline['domains'] as $domain) {
            $classDomains[] = ['ulid' => $domain['ulid'], 'name' => $domain['name'], ...$this->classReading($points, $students, fn (array $cells): ?array => $cells['domains'][$domain['ulid']] ?? null)];
            $counts[] = ['ulid' => $domain['ulid'], 'name' => $domain['name'], ...$this->counts(array_map(fn (array $row): array => $row['domains'][$domain['ulid']], $rows))];
        }

        $reference = $series['reference'];

        return [
            'state' => 'ready',
            'reason' => null,
            'availability' => TimelineSeries::availability($comparable),
            'reference' => ['key' => $reference['key'], 'label' => $reference['label']],
            'points' => $points,
            'left_out' => $series['left_out'],
            'class' => ['global' => $classGlobal, 'domains' => $classDomains],
            'counts' => ['students' => count($students), 'global' => $this->counts(array_map(fn (array $row): array => $row['global'], $rows)), 'domains' => $counts],
            'students' => $rows,
            'cautions' => $this->cautions($reference, $classGlobal, $comparable),
            'explanations' => $this->explanations(),
        ];
    }

    /**
     * The class's own trajectory at one figure: the mean of the same students at every moment of the line.
     *
     * @param  list<array<string, mixed>>  $points
     * @param  list<array<string, mixed>>  $students
     * @param  callable(array<string, mixed>): ?array<string, mixed>  $figureOf
     * @return array<string, mixed>
     */
    private function classReading(array $points, array $students, callable $figureOf): array
    {
        $comparableKeys = array_column(array_filter($points, fn (array $point): bool => $point['comparable']), 'key');

        // The cohort: students with a value at EVERY comparable moment.
        $cohort = [];
        foreach ($students as $student) {
            $complete = $comparableKeys !== [];

            foreach ($comparableKeys as $key) {
                if (($figureOf($student['cells'][$key] ?? [])['value'] ?? null) === null) {
                    $complete = false;
                    break;
                }
            }

            if ($complete) {
                $cohort[] = $student;
            }
        }

        $cohortEntries = [];
        $all = [];
        foreach ($points as $point) {
            $key = $point['key'];

            $everyone = [];
            foreach ($students as $student) {
                $value = $figureOf($student['cells'][$key] ?? [])['value'] ?? null;

                if ($value !== null) {
                    $everyone[] = (string) $value;
                }
            }

            // The cohort has a value at every COMPARABLE moment, and only those are averaged.
            $cohortValues = [];
            if ($point['comparable']) {
                foreach ($cohort as $student) {
                    $cohortValues[] = (string) $figureOf($student['cells'][$key])['value'];
                }
            }

            $all[] = ['students' => count($everyone), 'mean' => TimelineStatistics::mean($everyone)];
            $cohortEntries[] = [
                'key' => $key,
                'label' => $point['label'],
                'comparable' => $point['comparable'],
                'value' => $point['comparable'] ? TimelineStatistics::mean($cohortValues) : null,
                'band' => null,
            ];
        }

        return [
            'cohort' => count($cohort),
            'means' => array_column($cohortEntries, 'value'),
            'everyone' => $all,
            'trend' => TrajectoryReading::trend($cohortEntries),
        ];
    }

    /**
     * How the students are spread across the trends and across persistence — each count with its total.
     *
     * @param  list<array<string, mixed>>  $readings  one reading per student
     * @return array<string, mixed>
     */
    private function counts(array $readings): array
    {
        $trend = ['consistent_up' => 0, 'consistent_down' => 0, 'stable' => 0, 'variable' => 0, 'insufficient' => 0];
        $persistent = ['yes' => 0, 'no' => 0, 'no_recent' => 0, 'no_value' => 0, 'unknown' => 0, 'insufficient' => 0];
        $withLatest = 0;

        foreach ($readings as $reading) {
            $trend[$reading['trend']['state']]++;
            $persistent[$reading['persistent']['state']]++;
            $withLatest += $reading['values'] !== [] && $reading['values'][count($reading['values']) - 1] !== null ? 1 : 0;
        }

        return [
            'of' => count($readings),
            // Those with a value at the most recent moment: the only ones that can be «still» negative.
            'with_latest' => $withLatest,
            'trend' => $trend,
            'persistent' => $persistent,
        ];
    }

    /**
     * @param  array<string, mixed>  $reference
     * @param  array<string, mixed>  $classGlobal
     * @return list<array{code: string, text: string}>
     */
    private function cautions(array $reference, array $classGlobal, int $comparable): array
    {
        $cautions = [];

        if ($reference['few_students']) {
            $cautions[] = [
                'code' => 'few_students',
                'text' => __('O momento mais recente tem resultados de apenas :count aluno(s): leia as contagens com cautela.', ['count' => $reference['students_with_result']]),
            ];
        }

        if ($comparable >= TrajectoryReading::MINIMUM_FOR_TREND && $classGlobal['cohort'] < self::FEW_STUDENTS) {
            $cautions[] = [
                'code' => 'few_in_cohort',
                'text' => $classGlobal['cohort'] === 0
                    ? __('Nenhum aluno tem resultado em todos os momentos da linha: não há tendência da turma para ler.')
                    : __('A tendência da turma assenta em :count aluno(s) com resultado em todos os momentos: leia-a com cautela.', ['count' => $classGlobal['cohort']]),
            ];
        }

        return $cautions;
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(string $reason): array
    {
        return [
            'state' => 'unavailable',
            'reason' => $reason,
            'availability' => ['comparable_points' => 0, 'trends' => false, 'persistence' => false, 'reason' => $reason],
            'reference' => null,
            'points' => [],
            'left_out' => [],
            'class' => ['global' => null, 'domains' => []],
            'counts' => ['students' => 0, 'global' => null, 'domains' => []],
            'students' => [],
            'cautions' => [],
            'explanations' => $this->explanations(),
        ];
    }

    /**
     * @return list<array{key: string, title: string, text: string}>
     */
    private function explanations(): array
    {
        return [
            ['key' => 'trend', 'title' => __('O que é uma tendência'), 'text' => __('Precisa de pelo menos três momentos comparáveis com valor. Olha-se para cada passo entre momentos seguidos, na casa decimal mostrada: se todos subiram, «progressão consistente»; se todos desceram, «regressão consistente»; se todos são iguais, «estável»; em qualquer outro caso, «evolução variável». Com menos de três momentos não há tendência: há uma comparação.')],
            ['key' => 'persistent', 'title' => __('O que é um resultado abaixo do nível positivo persistente'), 'text' => __('O resultado está abaixo do nível positivo no momento mais recente e no anterior (dois momentos seguidos, no mínimo). Não é uma tendência, não prevê o futuro e não é um diagnóstico de dificuldades de aprendizagem: descreve notas, com os valores e os momentos que a sustentam.')],
            ['key' => 'line', 'title' => __('Que momentos entram'), 'text' => __('Só os momentos que, comprovadamente, têm os mesmos critérios e a mesma escala do mais recente. Os outros ficam de fora, com o motivo, e interrompem uma sequência: não se lê através de uma mudança de regra.')],
            ['key' => 'gaps', 'title' => __('Lacunas'), 'text' => __('Um momento sem valor é uma lacuna: aparece como tal, nunca como zero. Interrompe uma sequência de resultados abaixo do nível positivo, porque não se sabe o que aconteceu nesse momento.')],
            ['key' => 'class', 'title' => __('A tendência da turma'), 'text' => __('É a tendência da média dos mesmos alunos em todos os momentos da linha (os que têm valor em todos). Assim a média não muda só porque um aluno falta num momento. A média de todos os que têm valor aparece ao lado, com o seu total.')],
            ['key' => 'basis', 'title' => __('De onde vêm os dados'), 'text' => __('Um período é calculado com os dados de hoje; um momento guardado é a fotografia do que foi guardado e não muda. Nada aqui sugere causas, previsões ou medidas.')],
        ];
    }
}
