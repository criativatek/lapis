<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\DomainAppreciationDecision;
use App\Models\Scale;
use App\Models\SchoolClass;
use Illuminate\Support\Collection;

/**
 * A AVALIAÇÃO CONTÍNUA — a média dos RESULTADOS FORMAIS do ano.
 *
 * A REGRA, DITA POR INTEIRO, porque é a razão de esta classe existir. Um ano
 * letivo tem unidades temporais formais — dois semestres, três períodos, os
 * módulos que a escola configurou — e cada uma delas fecha com um resultado.
 * A avaliação contínua é a média desses resultados, e de mais nada:
 *
 *      2 semestres  →  média entre o 1.º e o 2.º semestre
 *      3 períodos   →  média entre o 1.º, o 2.º e o 3.º período
 *
 * OS MOMENTOS INTERCALARES NÃO ENTRAM AQUI, E ISSO NÃO É UM DETALHE. Uma
 * intercalar é uma fotografia informativa do estado do aluno a meio do caminho;
 * serve para acompanhar, serve para ler evolução, e é guardada como fotografia
 * quando o professor a guarda. Fazer a média de «intercalar, final, intercalar,
 * final» contaria duas vezes o mesmo trajeto e daria um número que não responde
 * a pergunta nenhuma. Esta classe não tem sequer como enganar-se: só conhece
 * `AcademicPeriod`, que é a unidade formal, e os momentos intercalares não são
 * períodos — são momentos DENTRO de um período (ver `SheetMomentKind`).
 *
 * OS PESOS SÃO OS QUE ESTÃO CONFIGURADOS, NUNCA UM QUE SE ESCREVA AQUI.
 * `profile_version_periods.period_weight_percent` é onde uma escola diz que o
 * 2.º semestre pesa 60. Quando nenhuma unidade tem peso declarado, todas pesam
 * o mesmo — que é o que «média entre o 1.º e o 2.º semestre» quer dizer em
 * português. Quando ALGUMAS têm, as que não têm ficam fora: um peso em branco
 * ao lado de pesos declarados é uma configuração incompleta, e inventar-lhe um
 * valor seria decidir por quem a deixou por acabar.
 *
 * ISTO NÃO É O «ACUMULADO», E OS DOIS NÃO SE SUBSTITUEM. O acumulado
 * (`ClassificationScope::Accumulated`) reprocessa os ELEMENTOS BRUTOS do ano
 * inteiro através do motor — é uma segunda leitura do mesmo material, não uma
 * média de médias, e continua exatamente como estava. A avaliação contínua é
 * uma média das CONCLUSÕES de cada unidade formal. Respondem a perguntas
 * diferentes, dão números diferentes, e o Quadro Síntese mostra os dois lado a
 * lado precisamente para que ninguém os confunda.
 *
 * NADA AQUI CALCULA UM RESULTADO DE PERÍODO. Cada resultado formal vem de
 * `ClassResultsCalculator::forPeriod` — o motor canónico, com as regras
 * congeladas do perfil. A única aritmética desta classe é a média ponderada
 * entre resultados que já existiam, que é literalmente a regra acima.
 */
class ContinuousAssessment
{
    public function __construct(
        protected ScaleProposalResolver $proposals,
    ) {}

    /**
     * A avaliação contínua de cada aluno desta turma.
     *
     * OS RESULTADOS FORMAIS CHEGAM JÁ CALCULADOS, e é de propósito. Quem chama
     * esta classe é uma página que já leu a pauta de cada unidade formal — pedir
     * ao motor que a recalcule seria correr a mesma conta duas vezes, e é a
     * segunda vez que um dia daria um número diferente da primeira. O que aqui
     * entra são as percentagens normalizadas que o motor já produziu.
     *
     * @param  Collection<int, AcademicPeriod>  $periods  as unidades formais do ano, por ordem
     * @param  array<int, array<int, string|null>>  $formalByPeriod  period id => enrollment id => percentagem normalizada do resultado formal, ou null quando não há resultado
     * @return array{
     *     units: list<array<string, mixed>>,
     *     students: array<int, array<string, mixed>>,
     *     weights_declared: bool,
     * }
     */
    public function for(SchoolClass $class, Collection $periods, array $formalByPeriod): array
    {
        // A CONFIGURAÇÃO — pesos, unidades, escala, arredondamento — resolvida
        // no mesmo sítio de que a leitura por domínio parte, para que as duas
        // não possam partir de configurações diferentes. A frase que o ecrã
        // escreve depende de `weights_declared`: «média entre o 1.º e o 2.º
        // semestre» quando ninguém declarou pesos, «1.º × 40 % + 2.º × 60 %»
        // quando declarou — e os números sozinhos não distinguem um peso
        // declarado de 1 da igualdade por omissão.
        [$units, $scale, $roundingMode, $roundingScale, $weightsDeclared] = $this->configuration($class, $periods);

        $decisions = $this->accumulatedDecisions($periods);

        $students = [];
        $enrollmentIds = $this->enrollmentIdsIn($formalByPeriod);

        foreach ($enrollmentIds as $enrollmentId) {
            $students[$enrollmentId] = $this->forStudent(
                $enrollmentId,
                $units,
                $formalByPeriod,
                $scale,
                $roundingMode,
                $roundingScale,
                $decisions,
            );
        }

        return ['units' => $units, 'students' => $students, 'weights_declared' => $weightsDeclared];
    }

    /**
     * A MESMA MÉDIA, UMA ESCALA ABAIXO: a avaliação contínua de cada DOMÍNIO.
     *
     * A REGRA É EXATAMENTE A DE CIMA, e é por isso que esta função não a repete:
     * chama `forStudent()`, que é onde a média ponderada vive, com os resultados
     * formais de um domínio em vez dos globais. Uma segunda fórmula aqui — ainda
     * que idêntica no dia em que fosse escrita — seria uma segunda resposta à
     * mesma pergunta, e as duas divergiriam na primeira alteração que alguém
     * fizesse a só uma delas.
     *
     * O QUE ENTRA SÃO OS RESULTADOS FORMAIS DE CADA UNIDADE, domínio a domínio —
     * os mesmos que a Pauta de cada período mostra. NÃO entra o desempenho
     * acumulado, que é a outra leitura do ano e reprocessa elementos brutos; não
     * entram fotografias intercalares, que não são unidades formais e que esta
     * classe não tem sequer como ver (§10, §11).
     *
     * A DECISÃO FINAL DE UM DOMÍNIO, quando existe, vem do mesmo sítio canónico
     * que a global: uma linha de âmbito ACUMULADO na última unidade do ano —
     * `DomainAppreciationDecision` para o domínio, `Classification` para o
     * global. Nada aqui a cria nem a preenche a partir da proposta.
     *
     * @param  Collection<int, AcademicPeriod>  $periods  as unidades formais do ano, por ordem
     * @param  array<int, array<int, array<int, string|null>>>  $formalByPeriod  period id => domain id => enrollment id => percentagem normalizada do resultado formal
     * @return array{
     *     units: list<array<string, mixed>>,
     *     students: array<int, array<int, array<string, mixed>>>,
     *     weights_declared: bool,
     * }
     */
    public function forDomains(SchoolClass $class, Collection $periods, array $formalByPeriod): array
    {
        [$units, $scale, $roundingMode, $roundingScale, $weightsDeclared] = $this->configuration($class, $periods);

        $decisions = $this->accumulatedDomainDecisions($periods);

        /** @var array<int, array<int, array<string, mixed>>> $students */
        $students = [];

        foreach ($this->domainIdsIn($formalByPeriod) as $domainId) {
            // Uma vista dos resultados formais deste domínio na forma que
            // `forStudent()` espera: unidade => matrícula => valor.
            $byPeriod = [];
            foreach ($formalByPeriod as $periodId => $byDomain) {
                $byPeriod[(int) $periodId] = $byDomain[$domainId] ?? [];
            }

            foreach ($this->enrollmentIdsIn($byPeriod) as $enrollmentId) {
                $students[$enrollmentId][$domainId] = $this->forStudent(
                    $enrollmentId,
                    $units,
                    $byPeriod,
                    $scale,
                    $roundingMode,
                    $roundingScale,
                    $decisions[$domainId] ?? [],
                );
            }
        }

        return ['units' => $units, 'students' => $students, 'weights_declared' => $weightsDeclared];
    }

    /**
     * O que a média precisa de saber sobre esta turma, resolvido uma vez.
     *
     * Estava inline em `for()`; ganhou nome quando um segundo leitor apareceu —
     * a leitura por domínio —, para que as duas partam exatamente da mesma
     * configuração. Nada mudou no que se resolve nem na ordem em que se resolve.
     *
     * @param  Collection<int, AcademicPeriod>  $periods
     * @return array{0: list<array<string, mixed>>, 1: Scale|null, 2: 'ceil'|'floor'|'half_down'|'half_even'|'half_up'|'none', 3: int, 4: bool}
     */
    protected function configuration(SchoolClass $class, Collection $periods): array
    {
        $weights = $this->weightsFor($class, $periods);
        $weightsDeclared = $this->weightsAreDeclared($class, $periods);
        $version = $class->profileVersion;
        $scale = $version?->scale()->with('levels')->first();
        $roundingMode = match ($version?->rounding_mode) {
            'half_up', 'half_down', 'half_even', 'ceil', 'floor', 'none' => $version->rounding_mode,
            default => 'half_up',
        };
        $roundingScale = (int) ($version->rounding_scale ?? 0);

        $units = [];
        foreach ($periods as $period) {
            $periodId = (int) $period->getKey();

            if (! array_key_exists($periodId, $weights)) {
                continue;
            }

            $units[] = [
                'period_id' => $periodId,
                'label' => (string) $period->label,
                'kind_label' => $period->kind->label(),
                'sequence' => (int) $period->sequence,
                'weight_percent' => $weights[$periodId],
            ];
        }

        return [$units, $scale, $roundingMode, $roundingScale, $weightsDeclared];
    }

    /**
     * Os domínios sobre os quais há alguma coisa a dizer, na ordem em que as
     * unidades os trouxeram.
     *
     * @param  array<int, array<int, array<int, string|null>>>  $formalByPeriod
     * @return list<int>
     */
    protected function domainIdsIn(array $formalByPeriod): array
    {
        $ids = [];

        foreach ($formalByPeriod as $byDomain) {
            foreach (array_keys($byDomain) as $domainId) {
                $ids[(int) $domainId] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * A decisão do professor sobre o ANO, domínio a domínio, quando ela existe.
     *
     * O MESMO DESENHO DA DECISÃO GLOBAL: âmbito ACUMULADO na última unidade do
     * ano. `accumulatedDecisions()` lê-a em `classifications`; esta lê-a em
     * `domain_appreciation_decisions`, que tem a mesma coluna `scope` e o mesmo
     * conjunto de valores. Uma decisão de âmbito PERÍODO não é reaproveitada
     * como decisão do ano — uma leitura de um semestre não é uma conclusão do
     * ano, e tratá-la como tal seria inventar uma associação que ninguém fez
     * (§17).
     *
     * UMA CONSULTA para a turma inteira, seja qual for o número de alunos e de
     * domínios.
     *
     * @param  Collection<int, AcademicPeriod>  $periods
     * @return array<int, array<int, array<string, mixed>>> domain id => enrollment id => decisão
     */
    protected function accumulatedDomainDecisions(Collection $periods): array
    {
        if ($periods->isEmpty()) {
            return [];
        }

        $decisions = DomainAppreciationDecision::query()
            ->where('academic_period_id', $periods->last()->getKey())
            ->where('scope', ClassificationScope::Accumulated)
            ->with('scaleLevel')
            ->get();

        $byDomain = [];

        foreach ($decisions as $decision) {
            $byDomain[(int) $decision->domain_id][(int) $decision->enrollment_id] = [
                'status' => 'decided',
                'proposed' => null,
                'final' => $this->levelPayload($decision->scaleLevel),
                'final_value' => null,
            ];
        }

        return $byDomain;
    }

    /**
     * Se ALGUMA unidade elegível tem peso formal escrito na configuração.
     *
     * A pergunta é feita à mesma coluna que `weightsFor` lê, e sobre as mesmas
     * unidades — uma segunda leitura das duas coisas podia responder sobre um
     * conjunto diferente daquele de que a média foi feita.
     *
     * @param  Collection<int, AcademicPeriod>  $periods
     */
    protected function weightsAreDeclared(SchoolClass $class, Collection $periods): bool
    {
        $version = $class->profileVersion;

        if ($version === null) {
            return false;
        }

        $configured = $version->periods()->get()
            ->keyBy(fn ($row): int => (int) $row->academic_period_id);

        foreach ($periods as $period) {
            $row = $configured->get((int) $period->getKey());

            if ($row === null || ! $row->contributes_to_accumulated) {
                continue;
            }

            if ($row->period_weight_percent !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * A média contínua de um aluno, e as parcelas de que ela é feita.
     *
     * UMA UNIDADE SEM RESULTADO NÃO É UM ZERO e não entra no denominador — é a
     * mesma regra que o motor aplica a um elemento por realizar (§13.3). Um
     * aluno que entrou a meio do ano tem a média das unidades que viveu, não uma
     * média penalizada pelas que não existiam para ele (§11.4).
     *
     * @param  list<array<string, mixed>>  $units
     * @param  array<int, array<int, string|null>>  $formalByPeriod
     * @param  'ceil'|'floor'|'half_down'|'half_even'|'half_up'|'none'  $roundingMode
     * @param  array<int, array<string, mixed>>  $decisions
     * @return array<string, mixed>
     */
    protected function forStudent(
        int $enrollmentId,
        array $units,
        array $formalByPeriod,
        ?Scale $scale,
        string $roundingMode,
        int $roundingScale,
        array $decisions,
    ): array {
        $parts = [];
        $weightedSum = '0';
        $weightTotal = '0';

        foreach ($units as $unit) {
            $periodId = (int) $unit['period_id'];
            $value = $formalByPeriod[$periodId][$enrollmentId] ?? null;
            $level = $this->proposals->bandFor($scale, $value);

            $parts[] = [
                'period_id' => $periodId,
                'label' => $unit['label'],
                'kind_label' => $unit['kind_label'],
                'weight_percent' => $unit['weight_percent'],
                'normalized_value' => $value,
                'counted' => $value !== null,
                'proposal' => $this->proposals->resolve(
                    $scale,
                    $level?->id,
                    $value,
                    $value === null ? null : Bc::round(Bc::of($value), $roundingScale, $roundingMode),
                    $roundingMode,
                    $roundingScale,
                )->toPayload(),
                'level' => $this->levelPayload($level),
            ];

            if ($value === null) {
                continue;
            }

            $weight = Bc::of((string) $unit['weight_percent']);
            $weightedSum = Bc::add($weightedSum, Bc::mul(Bc::of($value), $weight));
            $weightTotal = Bc::add($weightTotal, $weight);
        }

        // Sem nenhuma unidade com resultado não há média — e «—» é a resposta
        // verdadeira, nunca 0 (§13.3).
        $average = Bc::isZero($weightTotal) ? null : Bc::div($weightedSum, $weightTotal);
        $averageLevel = $this->proposals->bandFor($scale, $average);

        return [
            'enrollment_id' => $enrollmentId,
            'units' => $parts,
            'counted_units' => count(array_filter($parts, fn (array $part): bool => $part['counted'])),
            'normalized_value' => $average,
            // A proposta que sai desta média, lida na escala do próprio perfil
            // pelo mesmo serviço que lê todas as outras. O nível é o que se
            // mostra; o professor continua a ser quem decide (§3.3).
            'proposal' => $this->proposals->resolve(
                $scale,
                $averageLevel?->id,
                $average,
                $average === null ? null : Bc::round(Bc::of($average), $roundingScale, $roundingMode),
                $roundingMode,
                $roundingScale,
            )->toPayload(),
            'level' => $this->levelPayload($averageLevel),
            // A decisão do ano, quando existe. Vive onde sempre viveu — numa
            // `Classification` de âmbito acumulado —, e esta classe lê-a sem
            // nunca a escrever nem a preencher a partir da proposta.
            'decision' => $decisions[$enrollmentId] ?? null,
        ];
    }

    /**
     * O peso de cada unidade formal, lido da configuração e de mais lado nenhum.
     *
     * Só entram as unidades que o perfil marca como contribuintes — a mesma
     * pergunta que o âmbito acumulado já faz, respondida pela mesma coluna, para
     * que uma unidade excluída de um o seja também do outro.
     *
     * @param  Collection<int, AcademicPeriod>  $periods
     * @return array<int, string> period id => peso
     */
    protected function weightsFor(SchoolClass $class, Collection $periods): array
    {
        $version = $class->profileVersion;

        if ($version === null) {
            return [];
        }

        $configured = $version->periods()->get()
            ->keyBy(fn ($row): int => (int) $row->academic_period_id);

        $eligible = [];
        foreach ($periods as $period) {
            $periodId = (int) $period->getKey();
            $row = $configured->get($periodId);

            if ($row !== null && ! $row->contributes_to_accumulated) {
                continue;
            }

            $eligible[$periodId] = $row?->period_weight_percent === null
                ? null
                : (string) $row->period_weight_percent;
        }

        $declared = array_filter($eligible, fn (?string $weight): bool => $weight !== null);

        // Nenhum peso declarado: todas as unidades pesam o mesmo, que é o que
        // «a média entre o 1.º e o 2.º semestre» quer dizer.
        if ($declared === []) {
            return array_map(fn (): string => '1', $eligible);
        }

        // Alguns pesos declarados: valem os que estão escritos. Uma unidade sem
        // peso ao lado de unidades com peso é configuração por acabar, e
        // atribuir-lhe um valor seria decidir no lugar de quem a deixou assim.
        return $declared;
    }

    /**
     * A decisão do professor sobre o ano, quando ela existe.
     *
     * É a `Classification` de âmbito ACUMULADO — o sítio canónico onde uma
     * conclusão de ano já se escreve. Esta classe lê-a e nada mais: não a cria,
     * não a preenche a partir da proposta, e não a considera em falta.
     *
     * @param  Collection<int, AcademicPeriod>  $periods
     * @return array<int, array<string, mixed>>
     */
    protected function accumulatedDecisions(Collection $periods): array
    {
        if ($periods->isEmpty()) {
            return [];
        }

        $last = $periods->last();

        $classifications = Classification::query()
            ->where('academic_period_id', $last->getKey())
            ->where('scope', ClassificationScope::Accumulated)
            ->whereNull('superseded_by_id')
            ->with(['proposedScaleLevel', 'finalScaleLevel'])
            ->get();

        $byEnrollment = [];

        foreach ($classifications as $classification) {
            $byEnrollment[(int) $classification->enrollment_id] = [
                'status' => $classification->status->value,
                'proposed' => $this->levelPayload($classification->proposedScaleLevel),
                'final' => $this->levelPayload($classification->finalScaleLevel),
                'final_value' => $classification->final_value,
            ];
        }

        return $byEnrollment;
    }

    /**
     * @param  array<int, array<int, string|null>>  $formalByPeriod
     * @return list<int>
     */
    protected function enrollmentIdsIn(array $formalByPeriod): array
    {
        $ids = [];

        foreach ($formalByPeriod as $rows) {
            foreach (array_keys($rows) as $enrollmentId) {
                $ids[(int) $enrollmentId] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * @return array<string, mixed>|null
     */
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
