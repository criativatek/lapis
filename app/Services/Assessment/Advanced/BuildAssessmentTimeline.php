<?php

namespace App\Services\Assessment\Advanced;

use App\Models\ClassificationScope;
use App\Models\Domain;
use App\Models\EvaluationSheetExport;
use App\Models\ProfileVersionDomain;
use App\Models\SchoolClass;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ScaleProposalResolver;
use App\Support\Assessment\ScaleSummary;
use App\Support\Entitlements\Entitlements;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A class's assessment laid along time: the same figures the Pauta, Resultados and
 * Estatística already show, in ONE shape that the advanced analysis (comparison, patterns,
 * trends) reads — and nothing else.
 *
 * ASSEMBLED, NEVER COMPUTED. Every figure here was decided somewhere else. A period's
 * results are `BuildResultsProgression`'s (and so the calculator's); a kept moment's are
 * its frozen payload's; a band is `ScaleProposalResolver`'s; the movement between two
 * figures is `BuildResultsProgression::movement()`. This class selects, aligns and labels:
 * it has no arithmetic of its own, and a reading built on it cannot disagree with the
 * screen the teacher can open to check it.
 *
 * TWO KINDS OF POINT, AND THE BASIS IS ALWAYS SAID.
 *
 *  - a PERIOD is read LIVE: the class's periods, recomputed with today's instruments and
 *    today's profile — if a mark is corrected in October, October moves. The point says so
 *    («Calculado com os dados de hoje»);
 *  - a KEPT MOMENT is read FROZEN, from the payload of a photograph of the Pauta, and never
 *    from the present — what the teacher kept is what they kept. The point says when.
 *
 * Both are standalone readings (the period's own evidence): movement across accumulated
 * figures would report the history inside the number as if it were movement (§9, §10).
 * A kept moment of accumulated scope is left out, and says so.
 *
 * COMPARABLE ONLY WHEN DEMONSTRATED. Each point carries the signature of the scale and the
 * signature of the criteria it was made under (`TimelineCriteria`); `TimelineComparability`
 * decides, from them, what may be put beside what — and says why when it may not. A kept
 * moment older than the frozen scale has no signature and is never assumed comparable.
 *
 * ENOUGH DATA, SAID PLAINLY. `availability` says whether there are two comparable moments
 * with results (a comparison) and three (a trend), and when there are not, in words — never
 * an empty screen without a reason. Few students (under five) are flagged per point.
 *
 * WHAT NEVER TRAVELS. Numeric identifiers (enrolments, domains, periods, levels), the free
 * text of a classification, the self-assessment: the timeline is told by ULIDs and positions.
 * Names are the roll's, for a reading that is the class teacher's to see.
 *
 * PRO. Built only for an organization that holds `advanced_analytics`: the service asks, and
 * refuses with `AdvancedAnalyticsUnavailable`. The route's module gate and the controllers
 * are the gates a teacher meets; this is the one that cannot be forgotten.
 */
class BuildAssessmentTimeline
{
    /** The same capability the Pro readings of Estatística and Evolução do Aluno are behind. */
    public const ANALYTICS_MODULE = 'advanced_analytics';

    /** Below this many students with a result, a figure carries a caution (as the charts do). */
    public const FEW_STUDENTS = 5;

    /** Comparison needs two moments with results; a trend needs three (decisão 5). */
    public const MINIMUM_FOR_COMPARISON = 2;

    public const MINIMUM_FOR_TREND = 3;

    public function __construct(
        protected BuildResultsProgression $progression,
        protected ScaleProposalResolver $proposals,
        protected Entitlements $entitlements,
    ) {}

    /**
     * @param  array<string, mixed>|null  $progression  a progression this caller already built for the SAME class, today (no cutoff); null builds one
     * @return array<string, mixed>
     *
     * @throws AdvancedAnalyticsUnavailable when the organization does not hold `advanced_analytics`
     */
    public function for(SchoolClass $class, ?array $progression = null, bool $withKeptMoments = true): array
    {
        if (! $this->entitlements->allows(self::ANALYTICS_MODULE)) {
            throw new AdvancedAnalyticsUnavailable;
        }

        $progression = $this->progressionFor($class, $progression);
        $scale = $class->profileVersion?->scale()->with('levels')->first();

        if ($scale === null || $progression['periods'] === []) {
            return $this->empty($class, $progression, $scale === null ? 'no_profile' : 'no_periods');
        }

        $scaleSummary = ScaleSummary::of($scale);
        $weights = $this->weightsOf($class);
        $domains = $this->domains($progression, $weights);

        $students = [];
        foreach ($progression['students'] as $student) {
            $students[(int) $student['enrollment_id']] = [
                'enrollment_ulid' => (string) $student['enrollment_ulid'],
                'name' => (string) $student['name'],
                'class_number' => $student['class_number'],
                'cells' => [],
            ];
        }

        $levelSignature = TimelineCriteria::levels($scaleSummary);
        $percentageSignature = TimelineCriteria::percentages($weights);

        $periods = [];
        foreach ($progression['periods'] as $period) {
            $periods[(int) $period['id']] = $period;
        }

        // Every point, with where it sorts: its period, then kept moments by date, then the live period.
        $points = [];

        foreach ($progression['periods'] as $period) {
            $key = 'period:'.$period['ulid'];
            $points[] = [
                'sort' => [(int) $period['sequence'], 1, ''],
                'point' => [
                    'key' => $key,
                    'kind' => 'period',
                    'label' => (string) $period['label'],
                    'period_ulid' => (string) $period['ulid'],
                    'closes_the_year' => (bool) $period['closes_the_year'],
                    'basis' => ['kind' => 'live', 'label' => __('Calculado com os dados de hoje'), 'as_of' => null],
                    'level_signature' => $levelSignature,
                    'percentage_signature' => $percentageSignature,
                    'notices' => [],
                ],
                'cells' => $this->livePeriodCells($progression, (int) $period['id'], $domains, $scale),
            ];
        }

        $classNotices = [];

        if ($withKeptMoments) {
            [$moments, $classNotices] = $this->keptMoments($class, $periods, $domains, $students);

            foreach ($moments as $moment) {
                $points[] = $moment;
            }
        }

        usort($points, fn (array $first, array $second): int => $first['sort'] <=> $second['sort']);

        $ordered = [];
        foreach ($points as $index => $entry) {
            foreach ($entry['cells'] as $enrollmentId => $cell) {
                $students[$enrollmentId]['cells'][$entry['point']['key']] = $cell;
            }

            $ordered[] = [...$entry['point'], 'ordinal' => $index + 1, ...$this->quality($entry['cells'], count($students))];
        }

        return [
            'class_ulid' => (string) $class->ulid,
            'scale' => $scaleSummary,
            'domains' => array_values($domains),
            'points' => $ordered,
            'students' => array_values($students),
            'availability' => $this->availability($ordered),
            'notices' => $classNotices,
            'context' => $progression['context'],
        ];
    }

    /**
     * The progression this build reads: the caller's, if it is the right one, or a fresh one.
     *
     * @param  array<string, mixed>|null  $supplied
     * @return array<string, mixed>
     */
    protected function progressionFor(SchoolClass $class, ?array $supplied): array
    {
        if ($supplied === null) {
            return $this->progression->for($class);
        }

        // A progression built «até 15 de novembro» under a timeline that says «hoje» would be a
        // photograph presented as the present: refused, loudly, as Estatística refuses it.
        if (($supplied['context'] ?? null) !== BuildResultsProgression::contextFor($class)) {
            throw new InvalidArgumentException('The supplied results progression was built for a different class or cutoff.');
        }

        return $supplied;
    }

    /**
     * The profile's domains and their weights, in one query.
     *
     * @return list<array{domain_id: int, weight_percent: string}>
     */
    protected function weightsOf(SchoolClass $class): array
    {
        if ($class->assessment_profile_version_id === null) {
            return [];
        }

        $weights = [];

        foreach (ProfileVersionDomain::query()
            ->where('assessment_profile_version_id', $class->assessment_profile_version_id)
            ->get(['domain_id', 'weight_percent']) as $domain) {
            $weights[] = ['domain_id' => (int) $domain->domain_id, 'weight_percent' => (string) $domain->weight_percent];
        }

        return $weights;
    }

    /**
     * The domains of the class, by id, told by ulid: the ones the periods touched, with the weight
     * the profile gives them.
     *
     * @param  array<string, mixed>  $progression
     * @param  list<array{domain_id: int, weight_percent: string}>  $weights
     * @return array<int, array{ulid: string, name: string, weight_percent: string|null}>
     */
    protected function domains(array $progression, array $weights): array
    {
        $weightOf = [];
        foreach ($weights as $weight) {
            $weightOf[$weight['domain_id']] = $weight['weight_percent'];
        }

        $domains = [];
        foreach ($progression['domains'] as $domain) {
            $domains[(int) $domain['id']] = [
                'ulid' => (string) $domain['ulid'],
                'name' => (string) $domain['name'],
                'weight_percent' => $weightOf[(int) $domain['id']] ?? null,
            ];
        }

        return $domains;
    }

    /**
     * One live period's cell for every student.
     *
     * @param  array<string, mixed>  $progression
     * @param  array<int, array{ulid: string, name: string, weight_percent: string|null}>  $domains
     * @return array<int, array<string, mixed>>
     */
    protected function livePeriodCells(array $progression, int $periodId, array $domains, mixed $scale): array
    {
        $cells = [];

        foreach ($progression['students'] as $student) {
            $row = null;
            foreach ($student['periods'] as $candidate) {
                if ((int) $candidate['period_id'] === $periodId) {
                    $row = $candidate;
                    break;
                }
            }

            if ($row === null) {
                continue;
            }

            $byDomain = [];
            foreach ($row['domains'] as $domainRow) {
                $domain = $domains[(int) $domainRow['domain_id']] ?? null;

                if ($domain !== null) {
                    $byDomain[$domain['ulid']] = $this->figure($domainRow['weighted_average'], $this->bandOf($scale, $domainRow['weighted_average']), (bool) $domainRow['coverage_warning']);
                }
            }

            $cells[(int) $student['enrollment_id']] = [
                'result' => $this->figure($row['weighted_average'], $this->bandOf($scale, $row['weighted_average']), (bool) $row['coverage_warning']),
                'domains' => $byDomain,
                'classification' => $this->liveClassification($row['classification'] ?? null, $scale),
                'participation' => $row['participation'] === null ? null : [
                    'state' => (string) $row['participation']['state'],
                    'reason' => (string) $row['participation']['reason'],
                ],
            ];
        }

        return $cells;
    }

    /**
     * @param  array<string, mixed>|null  $row
     * @return array<string, mixed>|null
     */
    protected function liveClassification(?array $row, mixed $scale): ?array
    {
        if ($row === null) {
            return null;
        }

        $final = $row['final'] ?? null;
        $decidedElsewhere = ($row['final_value_scale'] ?? null) !== null
            || ($final !== null && (int) $final['scale_id'] !== (int) $scale->getKey());

        return [
            'status' => (string) $row['status'],
            'assigned' => $this->level($final),
            'assigned_value' => $row['final_value'] === null ? null : (string) $row['final_value'],
            'proposed' => $this->level($row['proposed'] ?? null),
            'level_comparable' => ! $decidedElsewhere,
        ];
    }

    /**
     * The kept moments of the class, as frozen points, and what could not be used.
     *
     * @param  array<int, array<string, mixed>>  $periods  by id
     * @param  array<int, array{ulid: string, name: string, weight_percent: string|null}>  $domains
     * @param  array<int, array<string, mixed>>  $students  by enrolment id
     * @return array{list<array<string, mixed>>, list<array{code: string, text: string, moment: string}>}
     */
    protected function keptMoments(SchoolClass $class, array $periods, array &$domains, array $students): array
    {
        $exports = EvaluationSheetExport::query()
            ->where('class_id', $class->getKey())
            ->where('adapter', 'snapshot')
            ->whereIn('academic_period_id', array_keys($periods))
            ->orderBy('exported_at')
            ->get();

        if ($exports->isEmpty()) {
            return [[], []];
        }

        // The domains a photograph names that no live period touched are named once, by ulid.
        $payloadDomainIds = [];
        foreach ($exports as $export) {
            foreach ($export->payload['domains'] ?? [] as $domain) {
                $payloadDomainIds[(int) ($domain['domain_id'] ?? 0)] = true;
            }
        }
        $ulids = Domain::query()->whereIn('id', array_keys($payloadDomainIds))->pluck('ulid', 'id');

        $moments = [];
        $notices = [];

        foreach ($exports as $export) {
            if (! $export->isIntact()) {
                // A payload that no longer matches its own seal is not evidence of anything.
                $notices[] = ['code' => 'moment_integrity', 'text' => __('Um momento guardado foi ignorado: já não corresponde ao registo original.'), 'moment' => (string) $export->moment_label];

                continue;
            }

            if ($export->scope !== ClassificationScope::Period) {
                $notices[] = ['code' => 'moment_not_standalone', 'text' => __('Um momento guardado com resultado acumulado foi ignorado: o movimento lê-se sobre o resultado de cada período.'), 'moment' => (string) $export->moment_label];

                continue;
            }

            /** @var array<string, mixed> $payload */
            $payload = $export->payload;
            $period = $periods[(int) $export->academic_period_id];
            $scale = is_array($payload['scale'] ?? null) ? $payload['scale'] : null;
            $levelSignature = TimelineCriteria::levels($scale);
            $percentageSignature = TimelineCriteria::percentages($payload['domains'] ?? []);

            $payloadDomains = [];
            foreach ($payload['domains'] ?? [] as $domain) {
                $id = (int) ($domain['domain_id'] ?? 0);
                $ulid = $ulids[$id] ?? null;

                if ($ulid === null) {
                    continue;
                }

                $payloadDomains[$id] = $ulid;

                if (! array_key_exists($id, $domains)) {
                    $domains[$id] = ['ulid' => (string) $ulid, 'name' => (string) ($domain['name'] ?? ''), 'weight_percent' => isset($domain['weight_percent']) ? (string) $domain['weight_percent'] : null];
                }
            }

            $cells = [];
            foreach ($payload['students'] ?? [] as $row) {
                $enrollmentId = (int) ($row['enrollment_id'] ?? 0);

                // Only the students of the class today: a student who left is no longer on the roll.
                if (! array_key_exists($enrollmentId, $students)) {
                    continue;
                }

                $cells[$enrollmentId] = $this->frozenCell($row, $scale, $payloadDomains);
            }

            $pointNotices = [];
            if ($scale === null) {
                $pointNotices[] = ['code' => TimelineComparability::SCALE_UNKNOWN, 'text' => __('Guardado antes de a escala viajar com o momento: os níveis só se comparam se se demonstrar que a escala é a mesma, e aqui não se demonstra.')];
            }

            $exportedAt = Carbon::parse($export->exported_at)->timezone((string) config('app.timezone'));

            $moments[] = [
                'sort' => [(int) $period['sequence'], 0, ($export->effective_at?->toDateString() ?? '').'|'.$export->exported_at->format('Y-m-d H:i:s')],
                'point' => [
                    'key' => 'moment:'.$export->ulid,
                    'kind' => 'kept_moment',
                    'label' => (string) ($payload['moment']['label'] ?? $export->moment_label),
                    'period_ulid' => (string) $period['ulid'],
                    'closes_the_year' => false,
                    'basis' => [
                        'kind' => 'frozen',
                        'label' => __('Guardado a :date', ['date' => $exportedAt->format('d/m/Y')]),
                        'as_of' => $export->effective_at?->toDateString(),
                    ],
                    'level_signature' => $levelSignature,
                    'percentage_signature' => $percentageSignature,
                    'notices' => $pointNotices,
                ],
                'cells' => $cells,
            ];
        }

        return [$moments, $notices];
    }

    /**
     * A student's cell, read out of a frozen payload row.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $scale
     * @param  array<int, string>  $payloadDomains  domain id => ulid
     * @return array<string, mixed>
     */
    protected function frozenCell(array $row, ?array $scale, array $payloadDomains): array
    {
        $byDomain = [];
        foreach ($row['domains'] ?? [] as $domainRow) {
            $ulid = $payloadDomains[(int) ($domainRow['domain_id'] ?? 0)] ?? null;

            if ($ulid !== null) {
                $byDomain[$ulid] = $this->figure(
                    $this->numeric($domainRow['normalized_value'] ?? null),
                    $this->frozenLevel($domainRow['scale_level_code'] ?? null, $domainRow['scale_level_label'] ?? null, $scale),
                    (bool) ($domainRow['has_coverage_warning'] ?? false),
                );
            }
        }

        $overall = $row['overall'] ?? [];
        $classification = $row['classification'] ?? null;

        return [
            'result' => $this->figure(
                $this->numeric($overall['normalized_value'] ?? null),
                $this->frozenLevel($overall['scale_level_code'] ?? null, $overall['scale_level_label'] ?? null, $scale),
                (bool) ($overall['has_coverage_warning'] ?? false),
            ),
            'domains' => $byDomain,
            'classification' => is_array($classification) ? [
                'status' => isset($classification['status']) ? (string) $classification['status'] : null,
                'assigned' => $this->frozenLevel($classification['final_scale_level_code'] ?? null, $classification['final_scale_level_label'] ?? null, $scale),
                'assigned_value' => ($classification['final_value'] ?? null) === null ? null : (string) $classification['final_value'],
                'proposed' => $this->frozenLevel($classification['proposed_scale_level_code'] ?? null, $classification['proposed_scale_level_label'] ?? null, $scale),
                'level_comparable' => ($classification['final_value_scale'] ?? null) === null,
            ] : null,
            // A photograph does not carry the roll's participation: not invented.
            'participation' => null,
        ];
    }

    /**
     * A level of a frozen payload, placed on the frozen scale when the payload has one.
     *
     * @param  array<string, mixed>|null  $scale
     * @return array{code: string|null, label: string|null, sequence: int|null, is_negative: bool|null}|null
     */
    protected function frozenLevel(mixed $code, mixed $label, ?array $scale): ?array
    {
        $code = $code === null || $code === '' ? null : (string) $code;
        $label = $label === null || $label === '' ? null : (string) $label;

        if ($code === null && $label === null) {
            return null;
        }

        foreach ($scale['levels'] ?? [] as $level) {
            if (($code !== null && (string) $level['code'] === $code) || ($code === null && (string) $level['label'] === $label)) {
                return [
                    'code' => (string) $level['code'],
                    'label' => (string) $level['label'],
                    'sequence' => (int) $level['sequence'],
                    'is_negative' => (bool) $level['is_negative'],
                ];
            }
        }

        // No scale in the photograph (or the level is not in it): named, never ranked.
        return ['code' => $code, 'label' => $label, 'sequence' => null, 'is_negative' => null];
    }

    /**
     * @return array{code: string, label: string, sequence: int, is_negative: bool}|null
     */
    protected function level(mixed $level): ?array
    {
        if (! is_array($level)) {
            return null;
        }

        return [
            'code' => (string) $level['code'],
            'label' => (string) $level['label'],
            'sequence' => (int) $level['sequence'],
            'is_negative' => (bool) $level['is_negative'],
        ];
    }

    /**
     * The band a figure falls in, told by the scale's own resolver.
     *
     * @return array{code: string, label: string, sequence: int, is_negative: bool}|null
     */
    protected function bandOf(mixed $scale, ?string $value): ?array
    {
        $band = $this->proposals->bandFor($scale, $value);

        return $band === null ? null : [
            'code' => (string) $band->code,
            'label' => (string) $band->label,
            'sequence' => (int) $band->sequence,
            'is_negative' => (bool) $band->is_negative,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $band
     * @return array{value: string|null, band: array<string, mixed>|null, coverage_warning: bool}
     */
    protected function figure(?string $value, ?array $band, bool $coverageWarning): array
    {
        return ['value' => $value, 'band' => $band, 'coverage_warning' => $coverageWarning];
    }

    protected function numeric(mixed $value): ?string
    {
        return $value !== null && is_numeric($value) ? (string) $value : null;
    }

    /**
     * What a point rests on: how many students have a result, and whether that is few.
     *
     * @param  array<int, array<string, mixed>>  $cells
     * @return array{students_total: int, students_with_result: int, partial_coverage: int, has_results: bool, few_students: bool}
     */
    protected function quality(array $cells, int $studentsTotal): array
    {
        $with = 0;
        $partial = 0;

        foreach ($cells as $cell) {
            if ($cell['result']['value'] !== null) {
                $with++;
                $partial += $cell['result']['coverage_warning'] ? 1 : 0;
            }
        }

        return [
            'students_total' => $studentsTotal,
            'students_with_result' => $with,
            'partial_coverage' => $partial,
            'has_results' => $with > 0,
            'few_students' => $with > 0 && $with < self::FEW_STUDENTS,
        ];
    }

    /**
     * Whether a comparison (two moments) or a trend (three) is possible — and why not, when not.
     *
     * Counted over the moments WITH RESULTS that share the same criteria: three moments of which
     * two are under other weights are not a trend of three.
     *
     * @param  list<array<string, mixed>>  $points
     * @return array<string, mixed>
     */
    protected function availability(array $points): array
    {
        $withResults = array_values(array_filter($points, fn (array $point): bool => $point['has_results']));

        // The largest group of moments under the same criteria (percentages) with results.
        $groups = [];
        foreach ($withResults as $point) {
            $signature = $point['percentage_signature'] ?? '?'.$point['key'];
            $groups[$signature] = ($groups[$signature] ?? 0) + 1;
        }
        $comparable = $groups === [] ? 0 : max($groups);

        $comparison = $comparable >= self::MINIMUM_FOR_COMPARISON;
        $trends = $comparable >= self::MINIMUM_FOR_TREND;

        $reason = match (true) {
            count($withResults) === 0 => __('Ainda não há resultados em nenhum momento: não há nada para comparar.'),
            $comparable < self::MINIMUM_FOR_COMPARISON && count($withResults) >= self::MINIMUM_FOR_COMPARISON => __('Há :count momentos com resultados, mas não sob os mesmos critérios de avaliação: não se demonstra que sejam comparáveis.', ['count' => count($withResults)]),
            $comparable < self::MINIMUM_FOR_COMPARISON => __('Só existe :count momento com resultados: a comparação precisa de pelo menos 2.', ['count' => count($withResults)]),
            $comparable < self::MINIMUM_FOR_TREND => __('Existem :count momentos comparáveis: as tendências precisam de pelo menos 3.', ['count' => $comparable]),
            default => null,
        };

        return [
            'points_with_results' => count($withResults),
            'comparable_points' => $comparable,
            'comparison' => $comparison,
            'trends' => $trends,
            'reason' => $reason,
        ];
    }

    /**
     * A class that has nothing to put on a timeline, and says why.
     *
     * @param  array<string, mixed>  $progression
     * @return array<string, mixed>
     */
    protected function empty(SchoolClass $class, array $progression, string $reason): array
    {
        return [
            'class_ulid' => (string) $class->ulid,
            'scale' => null,
            'domains' => [],
            'points' => [],
            'students' => [],
            'availability' => [
                'points_with_results' => 0,
                'comparable_points' => 0,
                'comparison' => false,
                'trends' => false,
                'reason' => $reason === 'no_profile'
                    ? __('Esta turma não tem perfil de avaliação associado, por isso não há resultados para analisar.')
                    : __('O ano letivo desta turma ainda não tem períodos: não há nada para comparar.'),
            ],
            'notices' => [['code' => $reason, 'text' => '', 'moment' => '']],
            'context' => $progression['context'],
        ];
    }
}
