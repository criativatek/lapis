<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Domain\Assessment\CalculationOutcome;
use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\Enrollment;
use App\Models\Scale;
use App\Models\SchoolClass;
use Illuminate\Support\Collection;

/**
 * O RESULTADO QUE FUNDAMENTA A PROPOSTA FORMAL DE UMA UNIDADE — e o único sítio
 * que responde a essa pergunta.
 *
 * A REGRA, POR INTEIRO. Uma unidade formal a meio do ano propõe a partir de si
 * própria: o 1.º semestre é o que aconteceu no 1.º semestre, e a proposta que o
 * acompanha é a banda desse resultado. A ÚLTIMA unidade formal não: quando o
 * professor fecha o ano, o que se lhe propõe é a conclusão do ANO — a avaliação
 * contínua final, média ponderada dos resultados formais de todas as unidades —
 * e não o retrato isolado do último semestre.
 *
 *      1.º semestre   →  proposta da banda do 1.º semestre
 *      2.º semestre   →  proposta da banda de (1.º × peso + 2.º × peso)
 *
 *      P1, P2         →  proposta da banda da própria unidade
 *      P3             →  proposta da banda de (P1 + P2 + P3, ponderados)
 *
 * O RESULTADO ESTANQUE DA ÚLTIMA UNIDADE NÃO DESAPARECE, e não é isso que aqui
 * se decide: continua a ser calculado pelo motor, continua a ser o que a Pauta
 * mostra na coluna «Quant.», e continua a ser com ele que se lê evolução de um
 * semestre para o outro. O que muda é apenas de que número nasce a PROPOSTA que
 * acompanha a decisão formal do ano.
 *
 * PORQUE É UM SÍTIO SÓ. Quem escreve a proposta (`ProposeClassifications`) e
 * quem a valida antes de a deixar confirmar (`ConfirmClassification`) têm de
 * partir do mesmo número. Enquanto cada um chamava `ClassResultsCalculator`
 * por sua conta, isso era verdade por coincidência; com a regra acima passaria
 * a ser falso no dia em que só um deles a aplicasse — e o sintoma seria uma
 * proposta que o serviço recusa confirmar por «desatualizada», sem nada estar
 * desatualizado. Por isso os dois entram por aqui, e a forma devolvida é
 * exatamente a de `ClassResultsCalculator::forScope`.
 *
 * NADA AQUI INVENTA ARITMÉTICA. A média é a de `ContinuousAssessment`, que é o
 * motor canónico dessa leitura e continua intocado; o resultado de cada unidade
 * é o de `ClassResultsCalculator`, que também. Esta classe só escolhe qual dos
 * dois fundamenta a proposta, e junta o resultado numa forma que quem já lia o
 * calculador reconhece sem mudar.
 */
class FormalProposalBasis
{
    public function __construct(
        protected ClassResultsCalculator $calculator,
        protected ContinuousAssessment $continuous,
        protected ScaleProposalResolver $proposals,
    ) {}

    /**
     * As linhas de que a proposta desta unidade nasce, na forma que
     * `ClassResultsCalculator::forScope` sempre devolveu.
     *
     * @return list<array{enrollment: Enrollment, outcome: CalculationOutcome}>
     */
    public function forScope(
        SchoolClass $class,
        AcademicPeriod $period,
        ClassificationScope $scope = ClassificationScope::Period,
    ): array {
        $rows = $this->calculator->forScope($class, $period, $scope);

        // O ÂMBITO ACUMULADO JÁ É UMA LEITURA DO ANO — reprocessa os elementos
        // brutos do ano inteiro — e não tem por isso nada a ganhar em ser
        // substituído por uma média de médias. Só o âmbito de PERÍODO, e só na
        // unidade que fecha o ano, muda de fundamento.
        if ($scope !== ClassificationScope::Period || ! $this->closesTheYear($class, $period)) {
            return $rows;
        }

        return $this->onContinuousAverage($class, $period, $rows);
    }

    /**
     * Esta unidade é a que fecha o ano desta turma?
     *
     * PELO SERVIDOR, e pela mesma função que a decisão final por domínio já usa
     * (§43): a unidade final é derivada da configuração do ano letivo, nunca
     * aceite de quem faz o pedido. Duas respostas diferentes à mesma pergunta
     * poriam a proposta a mudar de fundamento consoante o caminho por onde se
     * chegasse até ela.
     */
    protected function closesTheYear(SchoolClass $class, AcademicPeriod $period): bool
    {
        $final = ContinuousAssessment::finalUnitOf($class);

        return $final !== null && (int) $final->getKey() === (int) $period->getKey();
    }

    /**
     * As mesmas linhas, com a proposta reassente na média contínua do ano.
     *
     * O QUE SE SUBSTITUI E O QUE FICA. Substitui-se o valor de que a proposta
     * nasce — `normalizedValue`, `proposedValue` e a banda. Fica tudo o resto do
     * que o motor apurou para esta unidade: os domínios, o estado do resultado,
     * o aviso de cobertura e a contagem de elementos que contribuíram. Um aluno
     * sem cobertura no último semestre continua a ter o seu aviso, porque isso é
     * uma verdade sobre os dados dele e não sobre a origem da proposta.
     *
     * UMA UNIDADE SEM RESULTADO NÃO É UM ZERO, e a média contínua já respeita
     * isso: quem só tem um semestre feito tem a média desse semestre, não uma
     * média penalizada pelo que não existia para si (§11.4, §13.3).
     *
     * @param  list<array{enrollment: Enrollment, outcome: CalculationOutcome}>  $rows
     * @return list<array{enrollment: Enrollment, outcome: CalculationOutcome}>
     */
    protected function onContinuousAverage(SchoolClass $class, AcademicPeriod $period, array $rows): array
    {
        $periods = $this->formalUnitsOf($class);

        // Sem mais nenhuma unidade além desta, a média do ano É esta unidade —
        // e recalculá-la daria o mesmo número por um caminho mais caro.
        if ($periods->count() < 2) {
            return $rows;
        }

        $continuous = $this->continuous->for($class, $periods, $this->formalResultsOf($class, $periods));
        $students = $continuous['students'];

        [$scale, $roundingMode, $roundingScale] = $this->scaleOf($class);

        $rebased = [];

        foreach ($rows as $row) {
            $enrollmentId = (int) $row['enrollment']->getKey();
            $average = $students[$enrollmentId]['normalized_value'] ?? null;

            // Sem média não há nada com que substituir: fica o que o motor
            // apurou para a unidade, tal e qual.
            if ($average === null) {
                $rebased[] = $row;

                continue;
            }

            $rebased[] = [
                'enrollment' => $row['enrollment'],
                'outcome' => $this->outcomeOn($row['outcome'], $average, $scale, $roundingMode, $roundingScale, $students[$enrollmentId]),
            ];
        }

        return $rebased;
    }

    /**
     * O outcome da unidade, reassente num valor que não é o dela.
     *
     * A EXPLICAÇÃO É REESCRITA, e tem de ser. É ela que o snapshot congela no
     * momento em que o professor confirma (§13.5), e é dela que sai a resposta a
     * «de onde veio este número» meses depois. Deixar lá a explicação do
     * semestre isolado ao lado de um valor que é a média do ano seria guardar
     * uma justificação que não justifica o que está escrito a seu lado.
     *
     * @param  array<string, mixed>  $continuousForStudent
     * @param  'ceil'|'floor'|'half_down'|'half_even'|'half_up'|'none'  $roundingMode
     */
    protected function outcomeOn(
        CalculationOutcome $outcome,
        string $average,
        ?Scale $scale,
        string $roundingMode,
        int $roundingScale,
        array $continuousForStudent,
    ): CalculationOutcome {
        $level = $this->proposals->bandFor($scale, $average);

        return new CalculationOutcome(
            domains: $outcome->domains,
            normalizedValue: $average,
            proposedValue: Bc::round(Bc::of($average), $roundingScale, $roundingMode),
            resultState: $outcome->resultState,
            coverageWarning: $outcome->coverageWarning,
            contributingCount: $outcome->contributingCount,
            scaleLevelId: $level?->id,
            explanation: [
                'basis' => 'continuous_assessment',
                // Dito por palavras, e não só pela chave acima: quem abrir a
                // fotografia daqui a um ano lê a frase, não o nome do campo.
                'summary' => 'A proposta desta unidade é a avaliação contínua final do ano — a média ponderada dos resultados formais de cada unidade —, e não o resultado isolado desta unidade.',
                'continuous' => [
                    'normalized_value' => $average,
                    'counted_units' => $continuousForStudent['counted_units'] ?? null,
                    'units' => array_map(
                        static fn (array $unit): array => [
                            'period_id' => $unit['period_id'],
                            'label' => $unit['label'],
                            'weight_percent' => $unit['weight_percent'],
                            'normalized_value' => $unit['normalized_value'],
                            'counted' => $unit['counted'],
                        ],
                        $continuousForStudent['units'] ?? [],
                    ),
                ],
                // O retrato isolado da unidade não se perde: continua aqui,
                // nomeado como o que é, para que a leitura de evolução continue
                // possível a partir da própria fotografia.
                'standalone_unit' => [
                    'normalized_value' => $outcome->normalizedValue,
                    'proposed_value' => $outcome->proposedValue,
                    'scale_level_id' => $outcome->scaleLevelId,
                    'explanation' => $outcome->explanation,
                ],
            ],
        );
    }

    /**
     * As unidades formais do ano, por ordem — a mesma coleção que o Quadro
     * Síntese usa, e pela mesma consulta.
     *
     * @return Collection<int, AcademicPeriod>
     */
    protected function formalUnitsOf(SchoolClass $class): Collection
    {
        return AcademicPeriod::query()
            ->where('academic_year_id', $class->academic_year_id)
            ->orderBy('sequence')
            ->get();
    }

    /**
     * O resultado formal de cada unidade, por aluno — o que a avaliação contínua
     * precisa de receber já calculado.
     *
     * PELO MOTOR, e não pela Pauta. `BuildEvaluationSheet` produziria os mesmos
     * números por cima de uma leitura muito maior (classificações, decisões por
     * domínio, autoavaliações, cobertura), e nada disso entra numa média.
     *
     * @param  Collection<int, AcademicPeriod>  $periods
     * @return array<int, array<int, string|null>>
     */
    protected function formalResultsOf(SchoolClass $class, Collection $periods): array
    {
        $formal = [];

        foreach ($periods as $unit) {
            $row = [];

            foreach ($this->calculator->forScope($class, $unit, ClassificationScope::Period) as $line) {
                $row[(int) $line['enrollment']->getKey()] = $line['outcome']->normalizedValue;
            }

            $formal[(int) $unit->getKey()] = $row;
        }

        return $formal;
    }

    /**
     * A escala e a regra de arredondamento do perfil que vigora nesta turma.
     *
     * @return array{0: ?Scale, 1: 'ceil'|'floor'|'half_down'|'half_even'|'half_up'|'none', 2: int}
     */
    protected function scaleOf(SchoolClass $class): array
    {
        $version = $class->profileVersion;

        if ($version === null) {
            return [null, 'half_up', 0];
        }

        // O MESMO `match` DE `ContinuousAssessment::configuration()`, e não um
        // `?? 'half_up'`: um modo desconhecido na coluna tem de cair no mesmo
        // valor nos dois sítios, ou a média e a proposta que dela nasce
        // arredondariam de maneiras diferentes.
        $mode = match ($version->rounding_mode) {
            'half_up', 'half_down', 'half_even', 'ceil', 'floor', 'none' => $version->rounding_mode,
            default => 'half_up',
        };

        return [
            $version->scale()->with('levels')->first(),
            $mode,
            (int) ($version->rounding_scale ?? 0),
        ];
    }
}
