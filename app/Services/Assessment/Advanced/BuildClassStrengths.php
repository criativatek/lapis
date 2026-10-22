<?php

namespace App\Services\Assessment\Advanced;

use App\Domain\Assessment\Bc;
use App\Services\Assessment\BuildResultsProgression;
use App\Support\Entitlements\Entitlements;

/**
 * A class's domains at ONE moment: where it stands higher, where it stands lower, and who is below the
 * positive level — said in counts, with the denominators, and nothing else.
 *
 * ONE MOMENT, NOT A COMPARISON. The reading takes a single point of the timeline (a period read
 * today, or a photograph kept on a date) and describes it with ITS OWN scale and ITS OWN criteria: a
 * level is negative where the scale of that moment draws the line, and a photograph kept before the
 * scale travelled with it says so instead of borrowing today's. It needs no second moment.
 *
 * ASSEMBLED, NEVER COMPUTED. Every figure is the timeline's (and so the progression's or the frozen
 * payload's); the mean is the same sum-and-divide, rounded the same way, as `BuildClassStatistics`;
 * the band — and whether it is below the positive line — is the scale's own. This class counts,
 * orders and words; it has no rule of its own about what a result means.
 *
 * COUNTS AND DENOMINATORS, ALWAYS. «8 de 27 alunos» — never «8» and never «30 %» alone. A domain
 * evaluated in few students says so, and a domain with no result is listed, not dropped.
 *
 * A TIE IS NOT A WINNER. When two domains share the highest (or the lowest) mean at the precision
 * shown, neither is highlighted: the reading names the tie and stops. The same for the proportion
 * below the positive level. A domain is not «the strongest» because of an order the data does not have.
 *
 * NOT A DIAGNOSIS, NOT A RECOMMENDATION. «Abaixo do nível positivo» describes a mark on a scale. It
 * says nothing about why, and nothing about what the child has: no cause, no prediction, no measure is
 * suggested here. Strengths and «a observar» are descriptions of the numbers shown next to them, each
 * carrying its evidence; a highlight that the data does not support is not produced.
 *
 * PRO. The service refuses an organization without `advanced_analytics`, like the timeline it reads.
 */
class BuildClassStrengths
{
    /** Below this many students with a value, a domain carries a caution (as the timeline's and the charts'). */
    public const FEW_STUDENTS = BuildAssessmentTimeline::FEW_STUDENTS;

    public function __construct(protected Entitlements $entitlements) {}

    /**
     * @param  array<string, mixed>  $timeline  as `BuildAssessmentTimeline::for()` wrote it
     * @param  string|null  $momentKey  a point key (`period:…` / `moment:…`), or null for the latest with results
     * @return array<string, mixed>
     *
     * @throws AdvancedAnalyticsUnavailable
     */
    public function for(array $timeline, ?string $momentKey = null): array
    {
        if (! $this->entitlements->allows(BuildAssessmentTimeline::ANALYTICS_MODULE)) {
            throw new AdvancedAnalyticsUnavailable;
        }

        $withResults = array_values(array_filter($timeline['points'], fn (array $point): bool => $point['has_results']));
        $options = array_map(fn (array $point): array => $this->pointInfo($point), $withResults);

        if ($withResults === []) {
            return $this->unavailable($timeline, $options, $timeline['points'] === []
                ? (string) $timeline['availability']['reason']
                : __('Ainda não há resultados em nenhum momento: não há nada para analisar.'));
        }

        $byKey = [];
        foreach ($withResults as $point) {
            $byKey[$point['key']] = $point;
        }

        $defaulted = $momentKey === null || ! isset($byKey[$momentKey]);
        $point = $defaulted ? $withResults[count($withResults) - 1] : $byKey[$momentKey];

        $total = count($timeline['students']);
        $cellsOf = fn (array $student): ?array => $student['cells'][$point['key']] ?? null;

        $line = $this->negativeLine($timeline, $point, $cellsOf);
        $domains = $this->domainRows($timeline, $cellsOf, $total, $line['known']);
        $result = $this->resultRow($timeline, $cellsOf, $total, $line['known']);
        $students = $this->studentsBelow($timeline, $cellsOf, $line['known']);
        $ranking = $this->ranking($domains);

        return [
            'state' => 'ready',
            'reason' => null,
            'options' => $options,
            'selection' => ['moment' => $point['key'], 'defaulted' => $defaulted],
            'moment' => $this->pointInfo($point),
            'basis_note' => $this->basisNote($point),
            'cautions' => $this->cautions($point),
            'scale' => $this->scaleInfo($timeline, $point, $line),
            'negative_line' => $line,
            'result' => $result,
            'domains' => $domains,
            'ranking' => $ranking,
            'students_below' => $students,
            'highlights' => $this->highlights($domains, $ranking, $students, $total),
            'explanations' => $this->explanations(),
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
     * @param  array<string, mixed>  $point
     */
    private function basisNote(array $point): string
    {
        return $point['basis']['kind'] === 'frozen'
            ? __('Este momento é uma fotografia guardada: nenhum número se altera com correções posteriores.')
            : __('Este momento é calculado com os dados de hoje: se uma nota for corrigida, estes números podem mudar.');
    }

    /**
     * @param  array<string, mixed>  $point
     * @return list<array{code: string, text: string}>
     */
    private function cautions(array $point): array
    {
        return $point['few_students'] ? [[
            'code' => 'few_students',
            'text' => __('Este momento tem resultados de apenas :count aluno(s): leia as contagens com cautela.', ['count' => $point['students_with_result']]),
        ]] : [];
    }

    // ================================================================= the line between positive and negative

    /**
     * Whether THIS moment's figures can be told positive or negative — and where the line runs.
     *
     * A band that carries `is_negative` is on a scale that drew the line. A figure with a value and no
     * band (a scale of values, or a photograph kept before the scale travelled with it) cannot be told:
     * counting it as «not negative» would be the very guess this reading refuses to make.
     *
     * @param  array<string, mixed>  $timeline
     * @param  array<string, mixed>  $point
     * @param  callable(array<string, mixed>): ?array<string, mixed>  $cellsOf
     * @return array{known: bool, reason: string|null, text: string|null, negative_levels: list<string>}
     */
    private function negativeLine(array $timeline, array $point, callable $cellsOf): array
    {
        $unknown = false;
        $negative = [];

        foreach ($timeline['students'] as $student) {
            $cell = $cellsOf($student);

            foreach ([$cell['result'] ?? null, ...array_values($cell['domains'] ?? [])] as $figure) {
                if (($figure['value'] ?? null) === null) {
                    continue;
                }

                $band = $figure['band'] ?? null;

                if ($band === null || ($band['is_negative'] ?? null) === null) {
                    $unknown = true;

                    continue;
                }

                if ($band['is_negative'] === true) {
                    $negative[(string) ($band['label'] ?? $band['code'])] = (int) ($band['sequence'] ?? 0);
                }
            }
        }

        asort($negative);

        if ($unknown) {
            $reason = ($point['level_signature'] ?? null) === null ? 'scale_unknown' : 'no_levels';

            return [
                'known' => false,
                'reason' => $reason,
                'text' => $reason === 'scale_unknown'
                    ? __('Este momento foi guardado antes de a escala viajar com ele: não se sabe onde ficava a linha entre positivo e negativo, por isso não se contam alunos abaixo do nível positivo.')
                    : __('A escala deste momento não tem níveis: não há linha entre positivo e negativo, por isso não se contam alunos abaixo do nível positivo.'),
                'negative_levels' => array_keys($negative),
            ];
        }

        return ['known' => true, 'reason' => null, 'text' => null, 'negative_levels' => array_keys($negative)];
    }

    /**
     * The scale of the moment, in the words it was kept with.
     *
     * @param  array<string, mixed>  $timeline
     * @param  array<string, mixed>  $point
     * @param  array{known: bool, reason: string|null, text: string|null, negative_levels: list<string>}  $line
     * @return array<string, mixed>
     */
    private function scaleInfo(array $timeline, array $point, array $line): array
    {
        $current = TimelineCriteria::levels($timeline['scale']);
        $sameAsCurrent = ($point['level_signature'] ?? null) !== null && $point['level_signature'] === $current;

        return [
            'name' => $sameAsCurrent ? ($timeline['scale']['name'] ?? null) : null,
            'levels' => $sameAsCurrent ? ($timeline['scale']['levels'] ?? []) : [],
            'same_as_current' => $sameAsCurrent,
            'known' => ($point['level_signature'] ?? null) !== null,
            'negative_levels' => $line['negative_levels'],
        ];
    }

    // ================================================================= the domains

    /**
     * @param  array<string, mixed>  $timeline
     * @param  callable(array<string, mixed>): ?array<string, mixed>  $cellsOf
     * @return list<array<string, mixed>>
     */
    private function domainRows(array $timeline, callable $cellsOf, int $total, bool $lineKnown): array
    {
        $rows = [];

        foreach ($timeline['domains'] as $domain) {
            $values = [];
            $below = 0;
            $partial = 0;

            foreach ($timeline['students'] as $student) {
                $figure = $cellsOf($student)['domains'][$domain['ulid']] ?? null;

                if (($figure['value'] ?? null) === null) {
                    continue;
                }

                $values[] = (string) $figure['value'];
                $partial += ($figure['coverage_warning'] ?? false) ? 1 : 0;
                $below += ($figure['band']['is_negative'] ?? null) === true ? 1 : 0;
            }

            $rows[] = $this->row($domain['ulid'], $domain['name'], $values, $below, $partial, $total, $lineKnown);
        }

        return $rows;
    }

    /**
     * The class's overall result at the moment, in the same terms.
     *
     * @param  array<string, mixed>  $timeline
     * @param  callable(array<string, mixed>): ?array<string, mixed>  $cellsOf
     * @return array<string, mixed>
     */
    private function resultRow(array $timeline, callable $cellsOf, int $total, bool $lineKnown): array
    {
        $values = [];
        $below = 0;
        $partial = 0;

        foreach ($timeline['students'] as $student) {
            $figure = $cellsOf($student)['result'] ?? null;

            if (($figure['value'] ?? null) === null) {
                continue;
            }

            $values[] = (string) $figure['value'];
            $partial += ($figure['coverage_warning'] ?? false) ? 1 : 0;
            $below += ($figure['band']['is_negative'] ?? null) === true ? 1 : 0;
        }

        return $this->row(null, null, $values, $below, $partial, $total, $lineKnown);
    }

    /**
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    private function row(?string $ulid, ?string $name, array $values, int $below, int $partial, int $total, bool $lineKnown): array
    {
        $with = count($values);

        return [
            'ulid' => $ulid,
            'name' => $name,
            // Said as «com valor», «sem valor» and «poucos alunos» — the facts of coverage, no threshold chosen here.
            'students' => ['with_value' => $with, 'of' => $total],
            'mean' => $this->mean($values),
            'below_positive' => $lineKnown && $with > 0
                ? ['count' => $below, 'of' => $with, 'share' => Bc::round(Bc::mul(Bc::div((string) $below, (string) $with), '100'), BuildResultsProgression::PRECISION, 'half_up')]
                : null,
            'coverage' => [
                'state' => match (true) {
                    $with === 0 => 'none',
                    $with < self::FEW_STUDENTS => 'few',
                    $with < $total || $partial > 0 => 'incomplete',
                    default => 'complete',
                },
                'partial' => $partial,
            ],
        ];
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

        $sum = '0';
        foreach ($values as $value) {
            $sum = Bc::add($sum, Bc::of($value));
        }

        return Bc::round(Bc::div($sum, (string) count($values)), BuildResultsProgression::PRECISION, 'half_up');
    }

    // ================================================================= the students below the positive level

    /**
     * Who is below the positive level in two or more domains, and how many are in none, one or more.
     *
     * @param  array<string, mixed>  $timeline
     * @param  callable(array<string, mixed>): ?array<string, mixed>  $cellsOf
     * @return array<string, mixed>
     */
    private function studentsBelow(array $timeline, callable $cellsOf, bool $lineKnown): array
    {
        if (! $lineKnown) {
            return ['known' => false, 'of' => 0, 'none' => 0, 'one' => 0, 'two_or_more' => 0, 'students' => []];
        }

        $of = 0;
        $counts = ['none' => 0, 'one' => 0, 'two_or_more' => 0];
        $list = [];
        $names = [];
        foreach ($timeline['domains'] as $domain) {
            $names[$domain['ulid']] = $domain['name'];
        }

        foreach ($timeline['students'] as $student) {
            $cell = $cellsOf($student);
            $with = 0;
            $negative = [];

            foreach ($timeline['domains'] as $domain) {
                $figure = $cell['domains'][$domain['ulid']] ?? null;

                if (($figure['value'] ?? null) === null) {
                    continue;
                }

                $with++;

                if (($figure['band']['is_negative'] ?? null) === true) {
                    $negative[] = ['ulid' => $domain['ulid'], 'name' => $names[$domain['ulid']], 'value' => (string) $figure['value'], 'band' => $figure['band']];
                }
            }

            if ($with === 0) {
                continue;
            }

            $of++;
            $counts[match (true) {
                count($negative) >= 2 => 'two_or_more',
                count($negative) === 1 => 'one',
                default => 'none',
            }]++;

            if (count($negative) >= 2) {
                $list[] = [
                    'enrollment_ulid' => $student['enrollment_ulid'],
                    'name' => $student['name'],
                    'class_number' => $student['class_number'],
                    'domains_with_value' => $with,
                    'domains' => $negative,
                ];
            }
        }

        usort($list, fn (array $first, array $second): int => [$first['class_number'] ?? PHP_INT_MAX, $first['name']] <=> [$second['class_number'] ?? PHP_INT_MAX, $second['name']]);

        return ['known' => true, 'of' => $of, ...$counts, 'students' => $list];
    }

    // ================================================================= highest and lowest

    /**
     * The domains with the highest and the lowest mean — none highlighted on a tie.
     *
     * @param  list<array<string, mixed>>  $domains
     * @return array<string, mixed>
     */
    private function ranking(array $domains): array
    {
        $ranked = array_values(array_filter($domains, fn (array $domain): bool => $domain['mean'] !== null));

        if (count($ranked) < 2) {
            return ['state' => 'insufficient', 'highest_mean' => null, 'lowest_mean' => null, 'highest' => null, 'highest_tie' => [], 'lowest' => null, 'lowest_tie' => []];
        }

        $max = null;
        $min = null;

        foreach ($ranked as $domain) {
            $mean = Bc::of((string) $domain['mean']);

            if ($max === null || Bc::compare($mean, $max) > 0) {
                $max = $mean;
            }

            if ($min === null || Bc::compare($mean, $min) < 0) {
                $min = $mean;
            }
        }

        if (Bc::compare($max, $min) === 0) {
            return ['state' => 'all_equal', 'highest_mean' => $max, 'lowest_mean' => $min, 'highest' => null, 'highest_tie' => [], 'lowest' => null, 'lowest_tie' => []];
        }

        $top = array_values(array_filter($ranked, fn (array $domain): bool => Bc::compare(Bc::of((string) $domain['mean']), $max) === 0));
        $bottom = array_values(array_filter($ranked, fn (array $domain): bool => Bc::compare(Bc::of((string) $domain['mean']), $min) === 0));

        return [
            'state' => 'ok',
            'highest_mean' => $max,
            'lowest_mean' => $min,
            'highest' => count($top) === 1 ? $this->brief($top[0]) : null,
            'highest_tie' => count($top) > 1 ? array_column($top, 'name') : [],
            'lowest' => count($bottom) === 1 ? $this->brief($bottom[0]) : null,
            'lowest_tie' => count($bottom) > 1 ? array_column($bottom, 'name') : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $domain
     * @return array<string, mixed>
     */
    private function brief(array $domain): array
    {
        return ['ulid' => $domain['ulid'], 'name' => $domain['name'], 'mean' => $domain['mean'], 'students' => $domain['students']['with_value'], 'few_students' => $domain['coverage']['state'] === 'few'];
    }

    // ================================================================= the highlights

    /**
     * What stands out, each with the numbers it rests on. Facts only: where the data does not give a
     * single answer (a tie, one domain, a scale without a line) nothing is highlighted.
     *
     * @param  list<array<string, mixed>>  $domains
     * @param  array<string, mixed>  $ranking
     * @param  array<string, mixed>  $students
     * @return array{strengths: list<array<string, mixed>>, attention: list<array<string, mixed>>}
     */
    private function highlights(array $domains, array $ranking, array $students, int $total): array
    {
        $strengths = [];
        $attention = [];

        if ($ranking['highest'] !== null) {
            $top = $ranking['highest'];
            $strengths[] = $this->item('highest_mean', $top['ulid'], $top['students'], $total, __('«:domain» tem a média mais alta dos domínios: :mean (a mais baixa é :other); :students aluno(s) com valor.', ['domain' => $top['name'], 'mean' => $this->percent($top['mean']), 'other' => $this->percent($ranking['lowest_mean']), 'students' => $top['students']]));
        }

        foreach ($domains as $domain) {
            $below = $domain['below_positive'];

            if ($below !== null && $below['count'] === 0) {
                $strengths[] = $this->item('none_below', $domain['ulid'], 0, $below['of'], __('Nenhum aluno abaixo do nível positivo em «:domain» (0 de :of).', ['domain' => $domain['name'], 'of' => $below['of']]));
            }
        }

        if ($ranking['lowest'] !== null) {
            $low = $ranking['lowest'];
            $attention[] = $this->item('lowest_mean', $low['ulid'], $low['students'], $total, __('«:domain» tem a média mais baixa dos domínios: :mean (a mais alta é :other); :students aluno(s) com valor.', ['domain' => $low['name'], 'mean' => $this->percent($low['mean']), 'other' => $this->percent($ranking['highest_mean']), 'students' => $low['students']]));
        }

        $withLine = array_values(array_filter($domains, fn (array $domain): bool => $domain['below_positive'] !== null));
        $share = $this->uniqueHighestShare($withLine);

        if ($share !== null) {
            $below = $share['below_positive'];
            $attention[] = $this->item('highest_share_below', $share['ulid'], $below['count'], $below['of'], __('«:domain»: :count de :of aluno(s) abaixo do nível positivo (:share), a maior proporção entre os domínios.', ['domain' => $share['name'], 'count' => $below['count'], 'of' => $below['of'], 'share' => $this->percent($below['share'])]));
        }

        if ($students['known'] && $students['two_or_more'] > 0) {
            $attention[] = $this->item('below_in_two_or_more', null, $students['two_or_more'], $students['of'], __(':count de :of aluno(s) com resultado abaixo do nível positivo em dois ou mais domínios.', ['count' => $students['two_or_more'], 'of' => $students['of']]));
        }

        foreach ($domains as $domain) {
            $coverage = $domain['coverage'];
            $with = $domain['students']['with_value'];

            $text = match (true) {
                $coverage['state'] === 'none' => __('Sem resultados em «:domain» neste momento.', ['domain' => $domain['name']]),
                $coverage['state'] === 'few' => __('«:domain» tem valor em apenas :with de :of aluno(s): leia-o com cautela.', ['domain' => $domain['name'], 'with' => $with, 'of' => $domain['students']['of']]),
                $coverage['partial'] > 0 => __('«:domain»: cobertura parcial em :partial de :with aluno(s) com valor.', ['domain' => $domain['name'], 'partial' => $coverage['partial'], 'with' => $with]),
                $coverage['state'] === 'incomplete' => __('«:domain» tem valor em :with de :of aluno(s).', ['domain' => $domain['name'], 'with' => $with, 'of' => $domain['students']['of']]),
                default => null,
            };

            if ($text !== null) {
                $attention[] = $this->item('coverage', $domain['ulid'], $with, $domain['students']['of'], $text);
            }
        }

        return ['strengths' => $strengths, 'attention' => $attention];
    }

    /**
     * The domain with the highest proportion below the positive level — only when it is unique and
     * there is more than one domain to be highest among.
     *
     * @param  list<array<string, mixed>>  $withLine
     * @return array<string, mixed>|null
     */
    private function uniqueHighestShare(array $withLine): ?array
    {
        if (count($withLine) < 2) {
            return null;
        }

        $best = null;
        $tied = false;

        foreach ($withLine as $domain) {
            $count = $domain['below_positive']['count'];
            $of = $domain['below_positive']['of'];

            if ($best === null) {
                $best = $domain;
                $tied = false;

                continue;
            }

            // count/of compared without division: a/b > c/d  ⇔  a·d > c·b.
            $cross = $count * $best['below_positive']['of'] <=> $best['below_positive']['count'] * $of;

            if ($cross > 0) {
                $best = $domain;
                $tied = false;
            } elseif ($cross === 0) {
                $tied = true;
            }
        }

        return $tied || $best['below_positive']['count'] === 0 ? null : $best;
    }

    /**
     * @param  array<string, mixed>|string  $text  a translated sentence (the framework types `__()` loosely)
     * @return array{key: string, text: array<string, mixed>|string, evidence: array{domain: string|null, count: int, of: int}}
     */
    private function item(string $key, ?string $domain, int $count, int $of, array|string $text): array
    {
        return ['key' => $key, 'text' => $text, 'evidence' => ['domain' => $domain, 'count' => $count, 'of' => $of]];
    }

    private function percent(?string $value): string
    {
        return $value === null ? '—' : str_replace('.', ',', number_format((float) $value, 1, '.', '')).'%';
    }

    // ================================================================= unavailable, and the words

    /**
     * @param  array<string, mixed>  $timeline
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>
     */
    private function unavailable(array $timeline, array $options, string $reason): array
    {
        return [
            'state' => 'unavailable',
            'reason' => $reason,
            'options' => $options,
            'selection' => ['moment' => null, 'defaulted' => true],
            'moment' => null,
            'basis_note' => null,
            'cautions' => [],
            'scale' => null,
            'negative_line' => ['known' => false, 'reason' => null, 'text' => null, 'negative_levels' => []],
            'result' => null,
            'domains' => [],
            'ranking' => ['state' => 'insufficient', 'highest_mean' => null, 'lowest_mean' => null, 'highest' => null, 'highest_tie' => [], 'lowest' => null, 'lowest_tie' => []],
            'students_below' => ['known' => false, 'of' => 0, 'none' => 0, 'one' => 0, 'two_or_more' => 0, 'students' => []],
            'highlights' => ['strengths' => [], 'attention' => []],
            'explanations' => $this->explanations(),
        ];
    }

    /**
     * @return list<array{key: string, title: string, text: string}>
     */
    private function explanations(): array
    {
        return [
            ['key' => 'moment', 'title' => __('Que momento se analisa'), 'text' => __('Um único momento à sua escolha, com a escala e os critérios que tinha nesse momento. Não precisa de um segundo momento para comparar.')],
            ['key' => 'below', 'title' => __('O que é «abaixo do nível positivo»'), 'text' => __('Os níveis que a escala do momento marca como negativos. Descreve uma nota numa escala: não diz porquê e não é um diagnóstico de dificuldades de aprendizagem.')],
            ['key' => 'counts', 'title' => __('Contagens e proporções'), 'text' => __('Cada número vem com o total em que assenta: «8 de 27 alunos». Um domínio sem resultados aparece na lista; um domínio com poucos alunos diz-o.')],
            ['key' => 'ranking', 'title' => __('Médias mais altas e mais baixas'), 'text' => __('Compara-se a média de cada domínio, na casa decimal mostrada. Se dois domínios empatam, nenhum é destacado. As médias podem assentar em números diferentes de alunos, por isso cada uma traz o seu total.')],
            ['key' => 'highlights', 'title' => __('Pontos fortes e resultados a observar'), 'text' => __('São descrições dos números que aparecem ao lado, cada uma com a sua evidência. Não indicam causas nem previsões e não propõem medidas: a decisão pedagógica é do professor.')],
            ['key' => 'basis', 'title' => __('De onde vêm os dados'), 'text' => __('Um período é calculado com os dados de hoje; um momento guardado é a fotografia do que foi guardado e não muda.')],
        ];
    }
}
