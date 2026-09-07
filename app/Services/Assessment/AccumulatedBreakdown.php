<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Domain\Assessment\CalculationEngine;
use App\Domain\Assessment\CalculationOutcome;
use App\Domain\Assessment\DomainOutcome;
use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Support\Assessment\ReadingVocabulary;
use Illuminate\Support\Collection;

/**
 * DE ONDE VEM AQUELE NÚMERO — o desempenho acumulado, decomposto até ao
 * elemento que o produziu.
 *
 * O PROBLEMA QUE ISTO RESOLVE, dito com o caso que o levantou. Um aluno teve
 * 68,0 % de Educação Literária no 1.º semestre e 25,3 % no 2.º, e o desempenho
 * acumulado diz 60 %. Um professor que leia as três células vê um número que
 * não é a média de dois e não tem como saber porquê. A resposta está nos
 * pontos: 85 em 125 no primeiro semestre, 7,33 em 29 no segundo, 92,33 em 154
 * ao todo. Não é uma média dos semestres — é UMA fração, feita dos elementos
 * todos do ano, e por isso cada semestre pesa nela o que as suas cotações
 * pesam. Um tooltip a dizer «é outro cálculo» não é uma explicação; a conta é.
 *
 * NÃO CALCULA NADA DE NOVO, E É ESSA A REGRA. Todos os números aqui vêm de
 * `ClassResultsCalculator` — o acumulado de `forAccumulated`, o resultado de
 * cada unidade de `forPeriod`, o detalhe de cada elemento de `forInstruments`.
 * O que esta classe faz é ORGANIZAR o que o motor já disse e escrever a
 * proporção entre as parcelas. Se ela calculasse a divisão por sua conta,
 * passaria a haver duas respostas para a mesma pergunta, e um dia divergiam —
 * que é exatamente o que a explicação existe para tornar impossível (§17).
 *
 * O PESO EFETIVO NÃO É UM PESO CONFIGURADO. Ninguém escreveu «o 1.º semestre
 * vale 81 %» em lado nenhum: 81 % é a fatia do denominador que as cotações
 * desse semestre ocupam, e muda sozinha à medida que o ano avança. É por isso
 * que se chama EFETIVO e nunca é apresentado como configuração. Os pesos
 * formais das unidades — `period_weight_percent` — pertencem à avaliação
 * contínua e não têm efeito nenhum sobre este número.
 *
 * DUAS DECOMPOSIÇÕES, PORQUE HÁ DUAS PERGUNTAS DIFERENTES:
 *
 *   POR DOMÍNIO   O acumulado de um domínio é uma fração de pontos, e por isso
 *                 decompõe-se em pontos: obtidos e cotação, por unidade e por
 *                 elemento. As contribuições somam exatamente o resultado.
 *
 *   GLOBAL        O acumulado global NÃO é uma fração de pontos — é a média dos
 *                 domínios ponderada pelos pesos do perfil, com os domínios sem
 *                 valor a saírem da conta em vez de valerem zero (§13.4).
 *                 Decompô-lo em pontos seria mentir sobre o que ele é, por isso
 *                 decompõe-se em domínios, peso e contribuição.
 */
class AccumulatedBreakdown
{
    public function __construct(
        protected ClassResultsCalculator $calculator,
        protected ScaleProposalResolver $proposals,
    ) {}

    /**
     * A decomposição do desempenho acumulado de um aluno numa unidade temporal.
     *
     * @param  int|null  $domainId  o domínio a explicar; `null` explica o número global
     * @return array<string, mixed>
     */
    public function for(SchoolClass $class, AcademicPeriod $period, Enrollment $enrollment, ?int $domainId = null): array
    {
        $version = $class->profileVersion;
        $units = $this->unitsInScope($class, $period);

        $accumulated = $this->outcomeOf($this->calculator->forAccumulated($class, $period), $enrollment);

        $scale = $version?->scale()->with('levels')->first();
        $roundingMode = $this->roundingMode($version?->rounding_mode);
        $roundingScale = $version === null ? 0 : (int) $version->rounding_scale;

        $domain = $domainId === null ? null : Domain::find($domainId);

        $shared = [
            'period' => [
                'id' => (int) $period->getKey(),
                'ulid' => (string) $period->ulid,
                'label' => (string) $period->label,
                'kind_label' => $period->kind->label(),
            ],
            'student' => [
                'enrollment_ulid' => (string) $enrollment->ulid,
                'class_number' => $enrollment->class_number,
            ],
            'reading' => [
                'name' => ReadingVocabulary::ACCUMULATED,
                'long_name' => ReadingVocabulary::ACCUMULATED_LONG,
                'explanation' => ReadingVocabulary::ACCUMULATED_EXPLANATION,
                'not_an_average' => ReadingVocabulary::ACCUMULATED_NOT_AN_AVERAGE,
                'versus_continuous' => ReadingVocabulary::ACCUMULATED_VERSUS_CONTINUOUS,
            ],
            // A REGRA DE ARREDONDAMENTO DITA POR INTEIRO, e a fase que
            // REALMENTE correu — o motor arredonda uma só vez, na proposta
            // (§9 regra 2). É o que separa 59,954545 % de «60».
            'rounding' => [
                'mode' => $roundingMode,
                'mode_label' => $this->roundingModeLabel($roundingMode),
                'scale' => $roundingScale,
                'stage' => CalculationEngine::ROUNDING_STAGE_APPLIED,
                'stage_label' => 'Uma única vez, na proposta final',
            ],
        ];

        if ($domain === null) {
            return $shared + ['scope' => 'overall'] + $this->overall($accumulated, $class, $roundingMode, $roundingScale, $scale);
        }

        return $shared + ['scope' => 'domain'] + $this->domain(
            $class,
            $period,
            $enrollment,
            $domain,
            $units,
            $accumulated,
            $roundingMode,
            $roundingScale,
            $scale,
        );
    }

    /**
     * A MESMA DECOMPOSIÇÃO PARA A TURMA INTEIRA, sem os elementos.
     *
     * O ECRÃ E O FICHEIRO PEDEM COISAS DIFERENTES. O ecrã pergunta por uma
     * célula e quer tudo sobre ela, elementos incluídos; o ficheiro leva a
     * turma toda e quer a conta de cada uma. Fazer o ficheiro chamar `for()`
     * trinta vezes cinco daria cento e cinquenta passagens do motor sobre a
     * turma inteira para produzir o que duas passagens produzem: uma acumulada
     * e uma por unidade, ambas já por aluno e por domínio (§26).
     *
     * OS ELEMENTOS FICAM DE FORA de propósito, e não por economia: a folha
     * «Elementos de Avaliação» já os leva, aluno a aluno. Repeti-los aqui
     * multiplicados pelos domínios daria um ficheiro em que a mesma linha
     * aparece cinco vezes.
     *
     * @return array<int, array<int, array<string, mixed>>> enrollment id => domain id => a conta
     */
    public function forClassByDomain(SchoolClass $class, AcademicPeriod $period): array
    {
        $units = $this->unitsInScope($class, $period);
        $accumulated = $this->byEnrollment($this->calculator->forAccumulated($class, $period));

        $byUnit = [];
        foreach ($units as $unit) {
            $byUnit[(int) $unit->getKey()] = $this->byEnrollment($this->calculator->forPeriod($class, $unit));
        }

        $rows = [];

        foreach ($accumulated as $enrollmentId => $outcome) {
            foreach ($outcome->domains as $domainOutcome) {
                $domainId = $domainOutcome->domainId;
                $totalPossible = $domainOutcome->pointsPossible;

                $unitRows = [];

                foreach ($units as $unit) {
                    $own = $this->domainOutcomeOf($byUnit[(int) $unit->getKey()][$enrollmentId] ?? null, $domainId);

                    $unitRows[] = [
                        'period_id' => (int) $unit->getKey(),
                        'label' => (string) $unit->label,
                        'kind_label' => $unit->kind->label(),
                        'points_earned' => $own?->pointsEarned,
                        'points_possible' => $own?->pointsPossible,
                        'normalized_value' => $own?->normalizedValue,
                        'effective_weight_percent' => $this->share($own?->pointsPossible, $totalPossible),
                    ];
                }

                $rows[$enrollmentId][$domainId] = [
                    'units' => $unitRows,
                    'points_earned' => $domainOutcome->pointsEarned,
                    'points_possible' => $totalPossible,
                    'normalized_value' => $domainOutcome->normalizedValue,
                    'coverage_warning' => $domainOutcome->coverageWarning,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{enrollment: Enrollment, outcome: CalculationOutcome}>  $results
     * @return array<int, CalculationOutcome>
     */
    protected function byEnrollment(array $results): array
    {
        $byEnrollment = [];

        foreach ($results as $result) {
            $byEnrollment[(int) $result['enrollment']->getKey()] = $result['outcome'];
        }

        return $byEnrollment;
    }

    /**
     * O acumulado de UM domínio: pontos por unidade, pontos por elemento, e a
     * fração única que eles formam.
     *
     * @param  Collection<int, AcademicPeriod>  $units
     * @param  'ceil'|'floor'|'half_down'|'half_even'|'half_up'|'none'  $roundingMode
     * @return array<string, mixed>
     */
    protected function domain(
        SchoolClass $class,
        AcademicPeriod $period,
        Enrollment $enrollment,
        Domain $domain,
        Collection $units,
        ?CalculationOutcome $accumulated,
        string $roundingMode,
        int $roundingScale,
        mixed $scale,
    ): array {
        $domainId = (int) $domain->getKey();
        $total = $this->domainOutcomeOf($accumulated, $domainId);
        $totalPossible = $total?->pointsPossible;

        $unitRows = [];

        foreach ($units as $unit) {
            $own = $this->domainOutcomeOf(
                $this->outcomeOf($this->calculator->forPeriod($class, $unit), $enrollment),
                $domainId,
            );

            $unitRows[] = [
                'period_id' => (int) $unit->getKey(),
                'label' => (string) $unit->label,
                'kind_label' => $unit->kind->label(),
                'points_earned' => $own?->pointsEarned,
                'points_possible' => $own?->pointsPossible,
                'normalized_value' => $own?->normalizedValue,
                // A FATIA DO DENOMINADOR QUE ESTA UNIDADE OCUPA. Não é peso
                // configurado nenhum — é o que as cotações desta unidade
                // representam no total, e é a razão de o acumulado não ser uma
                // média dos semestres.
                'effective_weight_percent' => $this->share($own?->pointsPossible, $totalPossible),
            ];
        }

        [$elements, $excluded] = $this->elementsOf($class, $period, $enrollment, $domainId, $units, $totalPossible);

        return [
            'domain' => [
                'domain_id' => $domainId,
                'name' => (string) $domain->name,
            ],
            'units' => $unitRows,
            'total' => [
                'points_earned' => $total?->pointsEarned,
                'points_possible' => $total?->pointsPossible,
                'normalized_value' => $total?->normalizedValue,
                'proposed_value' => $total?->normalizedValue === null
                    ? null
                    : Bc::round(Bc::of($total->normalizedValue), $roundingScale, $roundingMode),
                'level' => $this->levelPayload($this->proposals->bandFor($scale, $total?->normalizedValue)),
                'coverage_warning' => (bool) $total?->coverageWarning,
            ],
            'elements' => $elements,
            'excluded' => $excluded,
            'domains' => [],
            'weight_total_applied' => null,
        ];
    }

    /**
     * O acumulado GLOBAL: os domínios, os seus pesos, e o que cada um pôs no
     * resultado.
     *
     * UM DOMÍNIO SEM VALOR SAI DA CONTA, e o peso dos restantes renormaliza — um
     * domínio em falta nunca age como um zero (§13.4). É por isso que a linha
     * «peso total aplicado» existe: sem ela, as contribuições pareceriam não
     * fechar.
     *
     * @return array<string, mixed>
     */
    protected function overall(
        ?CalculationOutcome $accumulated,
        SchoolClass $class,
        string $roundingMode,
        int $roundingScale,
        mixed $scale,
    ): array {
        $names = $this->domainNames($class);
        $weightTotal = $accumulated === null
            ? '0'
            : (string) ($accumulated->explanation['weight_total_applied'] ?? '0');
        $dropped = $accumulated === null ? [] : ($accumulated->explanation['dropped_domains'] ?? []);

        $rows = [];

        foreach ($accumulated === null ? [] : $accumulated->domains as $domainOutcome) {
            $isDropped = in_array($domainOutcome->domainId, $dropped, true);

            $rows[] = [
                'domain_id' => $domainOutcome->domainId,
                'name' => $names[$domainOutcome->domainId] ?? '(domínio removido)',
                'weight_percent' => $domainOutcome->weightPercent,
                'normalized_value' => $domainOutcome->normalizedValue,
                'points_earned' => $domainOutcome->pointsEarned,
                'points_possible' => $domainOutcome->pointsPossible,
                'dropped' => $isDropped || $domainOutcome->normalizedValue === null,
                'contribution' => $domainOutcome->normalizedValue === null || Bc::isZero(Bc::of($weightTotal))
                    ? null
                    : Bc::truncate(
                        Bc::div(
                            Bc::mul(Bc::of($domainOutcome->normalizedValue), Bc::of($domainOutcome->weightPercent)),
                            Bc::of($weightTotal),
                        ),
                        6,
                    ),
            ];
        }

        return [
            'domain' => null,
            'units' => [],
            'total' => [
                'points_earned' => null,
                'points_possible' => null,
                'normalized_value' => $accumulated?->normalizedValue,
                'proposed_value' => $accumulated?->proposedValue,
                'level' => $this->levelPayload($this->proposals->bandFor($scale, $accumulated?->normalizedValue)),
                'coverage_warning' => (bool) $accumulated?->coverageWarning,
            ],
            'elements' => [],
            'excluded' => [],
            'domains' => $rows,
            'weight_total_applied' => $weightTotal,
        ];
    }

    /**
     * Os elementos que sustentam o acumulado de um domínio, e os que ficaram de
     * fora com o motivo por que ficaram.
     *
     * CADA LINHA VEM DA EXPLICAÇÃO DO MOTOR, elemento a elemento — a mesma
     * estrutura que fica congelada num snapshot. O que se acrescenta é a
     * identidade do instrumento e da unidade a que ele pertence, que o motor
     * não conhece porque não conhece datas.
     *
     * @param  Collection<int, AcademicPeriod>  $units
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    protected function elementsOf(
        SchoolClass $class,
        AcademicPeriod $period,
        Enrollment $enrollment,
        int $domainId,
        Collection $units,
        ?string $totalPossible,
    ): array {
        $instruments = $this->calculator->instrumentsInScope($class, $period, ClassificationScope::Accumulated);

        if ($instruments->isEmpty()) {
            return [[], []];
        }

        $unitLabels = [];
        foreach ($units as $unit) {
            $unitLabels[(int) $unit->getKey()] = (string) $unit->label;
        }

        $outcomes = $this->calculator->forInstruments($class, $enrollment, $instruments);

        $elements = [];
        $excluded = [];

        foreach ($instruments as $instrument) {
            $explanation = $this->domainExplanationOf($outcomes[(int) $instrument->getKey()] ?? null, $domainId);

            if ($explanation === null) {
                continue;
            }

            foreach ($explanation['included'] as $item) {
                $allocation = (string) $item['allocation_percent'];
                $weightedEarned = Bc::mul(Bc::of((string) $item['earned']), Bc::div(Bc::of($allocation), '100'));

                $elements[] = $this->elementRow($instrument, $unitLabels) + [
                    'item_code' => (string) $item['item'],
                    'state' => (string) $item['state'],
                    'state_label' => $this->stateLabel((string) $item['state']),
                    'points_earned' => (string) $item['earned'],
                    'points_possible' => (string) $item['possible'],
                    'allocation_percent' => $allocation,
                    'is_bonus' => (bool) $item['is_bonus'],
                    // O QUE ESTE ELEMENTO PÔS NO RESULTADO, em pontos
                    // percentuais. A soma das contribuições reproduz o
                    // acumulado — é essa soma que torna o número reconstruível.
                    'contribution' => $this->share($weightedEarned, $totalPossible),
                    'weighted_points_earned' => Bc::truncate($weightedEarned, 4),
                ];
            }

            foreach ($explanation['excluded'] as $item) {
                $excluded[] = $this->elementRow($instrument, $unitLabels) + [
                    'item_code' => (string) $item['item'],
                    'reason' => (string) $item['reason'],
                    'reason_label' => $this->exclusionLabel((string) $item['reason']),
                    'raises_coverage_warning' => (bool) $item['raises_coverage_warning'],
                ];
            }
        }

        return [$elements, $excluded];
    }

    /**
     * @param  array<int, string>  $unitLabels
     * @return array<string, mixed>
     */
    protected function elementRow(Instrument $instrument, array $unitLabels): array
    {
        return [
            'instrument_id' => (int) $instrument->getKey(),
            'instrument_title' => (string) $instrument->title,
            'instrument_status_label' => $instrument->status->label(),
            'applied_on' => $instrument->applied_on->toDateString(),
            'period_id' => (int) $instrument->academic_period_id,
            'period_label' => $unitLabels[(int) $instrument->academic_period_id] ?? '—',
        ];
    }

    /**
     * As unidades temporais que alimentam este acumulado, pela mesma pergunta
     * que o motor faz — nunca por «até que sequência».
     *
     * @return Collection<int, AcademicPeriod>
     */
    protected function unitsInScope(SchoolClass $class, AcademicPeriod $period): Collection
    {
        $ids = $this->calculator->periodIdsInScope($class, $period, ClassificationScope::Accumulated);

        return AcademicPeriod::query()
            ->whereIn('id', $ids)
            ->orderBy('sequence')
            ->get();
    }

    /**
     * A fatia que uma parcela ocupa no denominador, em percentagem.
     *
     * Sem denominador não há fatia, e `null` é a resposta verdadeira — nunca 0,
     * que diria «não contribuiu» quando o que se passa é «não há conta».
     */
    protected function share(?string $part, ?string $total): ?string
    {
        if ($part === null || $total === null || Bc::isZero(Bc::of($total))) {
            return null;
        }

        return Bc::truncate(Bc::mul(Bc::div(Bc::of($part), Bc::of($total)), '100'), 6);
    }

    /**
     * @param  list<array{enrollment: Enrollment, outcome: CalculationOutcome}>  $results
     */
    protected function outcomeOf(array $results, Enrollment $enrollment): ?CalculationOutcome
    {
        foreach ($results as $result) {
            if ($result['enrollment']->getKey() === $enrollment->getKey()) {
                return $result['outcome'];
            }
        }

        return null;
    }

    protected function domainOutcomeOf(?CalculationOutcome $outcome, int $domainId): ?DomainOutcome
    {
        if ($outcome === null) {
            return null;
        }

        foreach ($outcome->domains as $domain) {
            if ($domain->domainId === $domainId) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * @return array{included: list<array<string, mixed>>, excluded: list<array<string, mixed>>}|null
     */
    protected function domainExplanationOf(?CalculationOutcome $outcome, int $domainId): ?array
    {
        if ($outcome === null) {
            return null;
        }

        foreach ($outcome->explanation['domains'] ?? [] as $domain) {
            if ((int) $domain['domain_id'] === $domainId) {
                return ['included' => $domain['included'], 'excluded' => $domain['excluded']];
            }
        }

        return null;
    }

    /** @return array<int, string> */
    protected function domainNames(SchoolClass $class): array
    {
        $version = $class->profileVersion;

        if ($version === null) {
            return [];
        }

        return Domain::query()
            ->whereIn('id', $version->domains()->pluck('domain_id'))
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * O estado de um elemento em palavras, pelo enum que os define — e não por
     * uma lista escrita aqui, que seria uma segunda tabela de estados.
     */
    protected function stateLabel(string $state): string
    {
        return ResultState::tryFrom($state)?->label() ?? $state;
    }

    /**
     * Porque é que um elemento não entrou. Os motivos são estados canónicos,
     * mais o único que não é um estado: o elemento não se aplicava a esta
     * matrícula (§11.4).
     */
    protected function exclusionLabel(string $reason): string
    {
        if ($reason === 'not_applicable_to_enrollment') {
            return 'Fora do período de matrícula do aluno';
        }

        return $this->stateLabel($reason);
    }

    /** @return 'ceil'|'floor'|'half_down'|'half_even'|'half_up'|'none' */
    protected function roundingMode(?string $mode): string
    {
        return match ($mode) {
            'half_up', 'half_down', 'half_even', 'ceil', 'floor', 'none' => $mode,
            default => 'half_up',
        };
    }

    protected function roundingModeLabel(string $mode): string
    {
        return match ($mode) {
            'half_up' => 'meio para cima (half_up)',
            'half_down' => 'meio para baixo (half_down)',
            'half_even' => 'meio para o par (half_even)',
            'ceil' => 'sempre para cima (ceil)',
            'floor' => 'sempre para baixo (floor)',
            default => 'sem arredondamento',
        };
    }

    /** @return array<string, mixed>|null */
    protected function levelPayload(mixed $level): ?array
    {
        if ($level === null) {
            return null;
        }

        return [
            'scale_level_id' => (int) $level->id,
            'code' => (string) $level->code,
            'label' => (string) $level->label,
            'sequence' => (int) $level->sequence,
            'is_negative' => (bool) $level->is_negative,
        ];
    }
}
