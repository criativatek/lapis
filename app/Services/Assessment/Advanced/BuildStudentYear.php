<?php

namespace App\Services\Assessment\Advanced;

use App\Support\Entitlements\Entitlements;

/**
 * One student's year laid along time: «Ao longo do ano» (Pro).
 *
 * THE SAME COMPARISON AS THE CLASS'S, FOR ONE ENROLMENT. Every figure comes from
 * `BuildAssessmentTimeline`; every movement and every change of level is the one
 * `BuildAssessmentComparison` writes for a student (`studentRow()` — the very function the class
 * page uses, so the class and the child cannot disagree about who went up); the choice of the two
 * moments is `BuildAssessmentComparison::select()`. What this class adds is the student's own view:
 * only the moments THIS student has a result in, the student's domains across all of them, and the
 * simple series a factual chart is drawn from.
 *
 * WHAT THE PAGE ALREADY SHOWS IS NOT REPEATED. «Como evoluiu» shows the continuous or period reading
 * between the latest two moments, and «Em que domínios» the accumulated result by domain with its
 * latest variation. This reading adds what they do not say: ANY two comparable moments, chosen by the
 * teacher; every domain across ALL the moments; the change of LEVEL and the crossing of the scale's
 * line; and, above all, where the figures come from and why a comparison is — or is not — allowed.
 *
 * ANOTHER READING, SAID SO. The figures here are each period's own (the standalone result Resultados
 * shows), while the page's «leitura contínua» joins the periods. The two answer different questions
 * and the page says which is which, so a teacher is never left comparing 70,2 % with 68,9 % without
 * knowing why.
 *
 * NO ARTIFICIAL COMPARISON, AND NO DIAGNOSIS. Under criteria not shown to be the same, the figures
 * stand side by side and nothing is subtracted; a result missing at one end is «sem comparação»,
 * never zero. A fall, or a negative level, is a fact about two figures — not a finding about the
 * child: this reading does not look for persistence (that is the D4's, with its own rule) and
 * attaches no cause.
 *
 * THE CLASS DOES NOT TRAVEL. Only this student's cells leave this class; the counts of how many
 * classmates have results — which the class page shows to the class's teacher — are not put on the
 * student's page.
 *
 * PRO. The service refuses an organization without `advanced_analytics`, like the timeline it reads.
 */
class BuildStudentYear
{
    public function __construct(
        protected BuildAssessmentComparison $comparison,
        protected Entitlements $entitlements,
    ) {}

    /**
     * @param  array<string, mixed>  $timeline  as `BuildAssessmentTimeline::for()` wrote it
     * @return array<string, mixed>
     *
     * @throws AdvancedAnalyticsUnavailable
     */
    public function for(array $timeline, string $enrollmentUlid, ?string $fromKey = null, ?string $toKey = null): array
    {
        if (! $this->entitlements->allows(BuildAssessmentTimeline::ANALYTICS_MODULE)) {
            throw new AdvancedAnalyticsUnavailable;
        }

        $student = null;
        foreach ($timeline['students'] as $candidate) {
            if ($candidate['enrollment_ulid'] === $enrollmentUlid) {
                $student = $candidate;
                break;
            }
        }

        if ($student === null) {
            return $this->unavailable($timeline, [], __('Este aluno não consta da avaliação desta turma.'));
        }

        // Only the moments this student has a result in.
        $mine = array_values(array_filter(
            $timeline['points'],
            fn (array $point): bool => ($student['cells'][$point['key']]['result']['value'] ?? null) !== null,
        ));
        $options = array_map(fn (array $point): array => $this->point($point, $student), $mine);

        if (count($mine) < BuildAssessmentTimeline::MINIMUM_FOR_COMPARISON) {
            return $this->unavailable($timeline, $options, match (count($mine)) {
                0 => __('Este aluno ainda não tem resultado em nenhum momento: não há nada para comparar.'),
                default => __('Este aluno só tem resultado em 1 momento: a comparação precisa de pelo menos 2.'),
            }, $student);
        }

        [$from, $to, $selection] = $this->comparison->select($mine, $fromKey, $toKey);

        $comparability = TimelineComparability::between($this->comparison->signatures($from), $this->comparison->signatures($to));
        $row = $this->comparison->studentRow($student, $timeline['domains'], $from['key'], $to['key'], $comparability, withDomainLevels: true);

        $domains = [];
        foreach ($timeline['domains'] as $domain) {
            $domains[] = [
                'ulid' => $domain['ulid'],
                'name' => $domain['name'],
                'weight_percent' => $domain['weight_percent'],
                ...$row['domains'][$domain['ulid']],
            ];
        }

        return [
            'state' => 'ready',
            'reason' => null,
            'scale' => $timeline['scale'],
            'options' => $options,
            'selection' => $selection,
            'from' => $this->point($from, $student),
            'to' => $this->point($to, $student),
            'comparability' => $comparability,
            'basis_note' => $this->comparison->basisNote($from, $to),
            'cautions' => $this->cautions($from, $to, $student),
            'notices' => $this->comparison->noticesOf($timeline, [$from, $to]),
            'result' => [
                'from' => $row['from'],
                'to' => $row['to'],
                'movement' => $row['movement'],
                'level' => $row['result_level'],
            ],
            'assigned' => $row['assigned'],
            'domains' => $domains,
            'series' => $this->series($timeline, $mine, $to, $student),
            'trends' => $this->trends($timeline, $student),
            'explanations' => $this->explanations(),
        ];
    }

    /**
     * A moment as THIS student meets it: its name, where it comes from and the student's own
     * coverage — never how many classmates have a result.
     *
     * @param  array<string, mixed>  $point
     * @param  array<string, mixed>  $student
     * @return array<string, mixed>
     */
    private function point(array $point, array $student): array
    {
        return [
            'key' => $point['key'],
            'kind' => $point['kind'],
            'label' => $point['label'],
            'basis' => $point['basis'],
            'ordinal' => $point['ordinal'],
            'partial_coverage' => ($student['cells'][$point['key']]['result']['coverage_warning'] ?? false) === true,
        ];
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @param  array<string, mixed>  $student
     * @return list<array{code: string, text: string}>
     */
    private function cautions(array $from, array $to, array $student): array
    {
        $cautions = [];

        foreach ([$from, $to] as $point) {
            if (($student['cells'][$point['key']]['result']['coverage_warning'] ?? false) === true) {
                $cautions[] = [
                    'code' => 'partial_coverage',
                    'text' => __('O resultado de «:label» assenta em avaliação parcial: nem tudo o que estava previsto estava avaliado nesse momento.', ['label' => $point['label']]),
                ];
            }
        }

        return $cautions;
    }

    /**
     * What a factual chart is drawn from: the student's result and each domain across the moments that
     * share the criteria of the latest one. A line across a change of weights would draw a difference
     * of rule as if it were a difference of the student, so the others are left out — and said.
     *
     * @param  array<string, mixed>  $timeline
     * @param  list<array<string, mixed>>  $mine  the moments the student has a result in
     * @param  array<string, mixed>  $to  the moment the comparison ends at
     * @param  array<string, mixed>  $student
     * @return array<string, mixed>
     */
    private function series(array $timeline, array $mine, array $to, array $student): array
    {
        $signature = $to['percentage_signature'] ?? null;
        $group = $signature === null
            ? []
            : array_values(array_filter($mine, fn (array $point): bool => ($point['percentage_signature'] ?? null) === $signature));

        $points = array_map(fn (array $point): array => [
            'key' => $point['key'],
            'kind' => $point['kind'],
            'label' => $point['label'],
            'basis_kind' => $point['basis']['kind'],
        ], $group);

        $domains = [];
        foreach ($timeline['domains'] as $domain) {
            $values = array_map(fn (array $point): ?string => $student['cells'][$point['key']]['domains'][$domain['ulid']]['value'] ?? null, $group);

            if (count(array_filter($values, fn (?string $value): bool => $value !== null)) > 0) {
                $domains[] = ['ulid' => $domain['ulid'], 'name' => $domain['name'], 'values' => $values];
            }
        }

        $result = array_map(fn (array $point): ?string => $student['cells'][$point['key']]['result']['value'] ?? null, $group);
        $left = count($mine) - count($group);

        return [
            'enough' => count($group) >= BuildAssessmentTimeline::MINIMUM_FOR_COMPARISON,
            'points' => $points,
            'result' => $result,
            'domains' => $domains,
            'left_out' => $left,
            'note' => $left > 0
                ? __(':count momento(s) ficam fora do gráfico por terem critérios de avaliação diferentes (ou desconhecidos) dos do último momento.', ['count' => $left])
                : null,
        ];
    }

    /**
     * This student's trajectories along the moments that may be put in one line: the overall result and
     * each domain, each read for its trend (three points) and for being persistently below the positive
     * level (two, the latest included) — the same two rules, from the same function, as the class's.
     *
     * @param  array<string, mixed>  $timeline
     * @param  array<string, mixed>  $student
     * @return array<string, mixed>|null null when no moment has results
     */
    private function trends(array $timeline, array $student): ?array
    {
        $series = TimelineSeries::of($timeline);

        if ($series === null) {
            return null;
        }

        $domains = [];
        foreach ($timeline['domains'] as $domain) {
            $domains[] = [
                'ulid' => $domain['ulid'],
                'name' => $domain['name'],
                ...TrajectoryReading::read(TimelineSeries::entries(
                    $series['points'],
                    fn (string $key): ?array => $student['cells'][$key]['domains'][$domain['ulid']] ?? null,
                )),
            ];
        }

        return [
            'availability' => TimelineSeries::availability($series['comparable_points']),
            'reference' => ['key' => $series['reference']['key'], 'label' => $series['reference']['label']],
            'points' => $series['points'],
            'left_out' => $series['left_out'],
            'result' => TrajectoryReading::read(TimelineSeries::entries($series['points'], fn (string $key): ?array => $student['cells'][$key]['result'] ?? null)),
            'domains' => $domains,
        ];
    }

    /**
     * @param  array<string, mixed>  $timeline
     * @param  list<array<string, mixed>>  $options
     * @param  array<string, mixed>|null  $student
     * @return array<string, mixed>
     */
    private function unavailable(array $timeline, array $options, string $reason, ?array $student = null): array
    {
        return [
            'state' => 'unavailable',
            'reason' => $reason,
            'scale' => $timeline['scale'],
            'options' => $options,
            'selection' => ['from' => null, 'to' => null, 'defaulted' => true, 'swapped' => false],
            'from' => null,
            'to' => null,
            'comparability' => null,
            'basis_note' => null,
            'cautions' => [],
            'notices' => $timeline['notices'],
            'result' => null,
            'assigned' => null,
            'domains' => [],
            'series' => ['enough' => false, 'points' => [], 'result' => [], 'domains' => [], 'left_out' => 0, 'note' => null],
            'trends' => $student === null ? null : $this->trends($timeline, $student),
            'explanations' => $this->explanations(),
        ];
    }

    /**
     * @return list<array{key: string, title: string, text: string}>
     */
    private function explanations(): array
    {
        return [
            ['key' => 'reading', 'title' => __('Que resultado é este'), 'text' => __('Cada momento mostra o resultado do próprio período, o mesmo de Resultados. A «leitura contínua» da página junta os períodos; por isso os números podem ser diferentes e nenhum está errado.')],
            ['key' => 'movement', 'title' => __('Subiu, manteve-se, desceu'), 'text' => __('Compara-se o resultado nos dois momentos, na casa decimal em que é mostrado: é a mesma regra de Resultados, sem margem escolhida à parte. A variação vem em pontos percentuais (p.p.).')],
            ['key' => 'levels', 'title' => __('Mudanças de nível'), 'text' => __('Um nível «subiu» quando está mais acima na escala; «passou a positivo» ou «a negativo» quando atravessa a linha que a escola definiu. Só se comparam níveis quando a escala é demonstravelmente a mesma nos dois momentos.')],
            ['key' => 'comparable', 'title' => __('Quando não se compara'), 'text' => __('Se os domínios, os pesos ou a escala mudaram entre os dois momentos, os números aparecem lado a lado e não se subtraem. Um resultado em falta não é zero: é «sem comparação».')],
            ['key' => 'basis', 'title' => __('De onde vêm os dados'), 'text' => __('Um período é calculado com os dados de hoje; um momento guardado é a fotografia do que foi guardado e não muda. Uma descida ou um nível negativo descrevem dois números, não explicam causas nem fazem um diagnóstico.')],
        ];
    }
}
