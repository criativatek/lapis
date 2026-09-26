<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Domain\Assessment\CalculationEngine;
use App\Domain\Assessment\CalculationRule;
use App\Domain\Assessment\ScaleBand;
use App\Domain\Assessment\ScoreInput;
use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\ResultState;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\SubjectParticipation;
use Illuminate\Support\Collection;

/**
 * OS ELEMENTOS DE AVALIAÇÃO DE UMA TURMA, aluno a aluno, com o resultado que
 * cada um deu — o terceiro nível do Quadro Síntese (§11).
 *
 * O RESULTADO DE UM ELEMENTO SAI DO MESMO MOTOR QUE O RESULTADO DO PERÍODO.
 * `CalculationEngine` é chamado uma vez por (aluno, elemento) com exatamente as
 * regras congeladas do perfil — a mesma classe, a mesma aritmética, os mesmos
 * estados. Uma percentagem calculada aqui à mão dividindo pontos por pontos
 * possíveis daria outro número no primeiro item bónus, no primeiro «não
 * aplicável» e na primeira falta justificada, e a grelha passaria a discordar
 * de si própria (§17).
 *
 * TRÊS CONSULTAS PARA A TURMA INTEIRA, e não uma por aluno. Os elementos, os
 * seus itens com as alocações por domínio, e todas as notas, chegam de uma vez;
 * o resto é aritmética pura em memória. Uma turma de trinta alunos com vinte
 * elementos são seiscentas chamadas ao motor, que não tocam na base de dados
 * nenhuma vez (§24).
 *
 * O QUE NÃO FOI REALIZADO NÃO TEM CLASSIFICAÇÃO. Um elemento por corrigir, uma
 * falta, um elemento anterior ao ingresso do aluno — cada um tem o seu estado
 * dito por palavras, e nenhum deles vira um zero (§13.3, §11.4). Um elemento
 * que não conta para a classificação vem marcado como tal e continua a
 * aparecer: é trabalho que existiu, e escondê-lo do professor seria dizer que
 * não.
 */
class BuildClassElements
{
    public function __construct(
        protected CalculationEngine $engine,
        protected ScaleProposalResolver $proposals,
        protected InstrumentEligibility $eligibility = new InstrumentEligibility,
    ) {}

    /**
     * Todos os elementos do ano, por matrícula.
     *
     * @param  Collection<int, AcademicPeriod>  $periods
     * @return array{
     *     elements: list<array<string, mixed>>,
     *     by_student: array<int, list<array<string, mixed>>>,
     * }
     */
    public function for(SchoolClass $class, Collection $periods): array
    {
        $version = $class->profileVersion;

        if ($version === null || $periods->isEmpty()) {
            return ['elements' => [], 'by_student' => []];
        }

        $instruments = Instrument::query()
            ->where('class_id', $class->getKey())
            ->whereIn('academic_period_id', $periods->pluck('id'))
            ->with(['type', 'items.domainAllocations'])
            ->orderBy('applied_on')
            ->orderBy('id')
            ->get();

        if ($instruments->isEmpty()) {
            return ['elements' => [], 'by_student' => []];
        }

        // DECISÃO DO PRODUCT OWNER (regra das duas perguntas): esta lista é
        // por ELEMENTO, e um elemento é uma pergunta do tipo (A) — decidida
        // pela data DELE, nunca pelo cohort a uma data de referência única.
        // Todas as matrículas entram (`all()`, nunca `attending()`); é
        // `resultRow()` que decide, elemento a elemento, se ele se aplicava
        // àquele aluno naquele dia (`ClassCohort::wasAttendingOn()`) — para
        // que um instrumento de outubro continue a contar para um aluno cuja
        // janela de não-frequência só abriu em janeiro.
        $enrollments = ClassCohort::enrollmentsFor($class)->values();
        $windows = ClassCohort::windowsFor($enrollments->map(fn (Enrollment $enrollment): int => (int) $enrollment->getKey()));

        $scores = StudentItemScore::query()
            ->whereIn('instrument_id', $instruments->pluck('id'))
            ->get()
            ->keyBy(fn (StudentItemScore $score): string => $score->enrollment_id.':'.$score->instrument_item_id);

        [$rule, $domainWeights, $scaleBands] = $this->configuration($version);
        $scale = $version->scale()->with('levels')->first();
        $domainNames = $this->domainNames($instruments);

        $elements = [];
        foreach ($instruments as $instrument) {
            $elements[] = $this->elementRow($instrument, $domainNames);
        }

        $byStudent = [];

        foreach ($enrollments as $enrollment) {
            $rows = [];

            foreach ($instruments as $instrument) {
                $rows[] = $this->resultRow(
                    $enrollment,
                    $instrument,
                    $scores,
                    $rule,
                    $domainWeights,
                    $scaleBands,
                    $scale,
                    $windows,
                );
            }

            $byStudent[(int) $enrollment->getKey()] = $rows;
        }

        return ['elements' => $elements, 'by_student' => $byStudent];
    }

    /**
     * O que o elemento É — o mesmo para toda a turma, dito uma vez.
     *
     * @param  array<int, string>  $domainNames
     * @return array<string, mixed>
     */
    protected function elementRow(Instrument $instrument, array $domainNames): array
    {
        $domains = [];

        foreach ($instrument->items as $item) {
            foreach ($item->domainAllocations as $allocation) {
                $domainId = (int) $allocation->domain_id;
                $domains[$domainId] ??= [
                    'domain_id' => $domainId,
                    'name' => $domainNames[$domainId] ?? '(domínio removido)',
                ];
            }
        }

        return [
            'instrument_id' => (int) $instrument->getKey(),
            'ulid' => (string) $instrument->ulid,
            'title' => (string) $instrument->title,
            'type' => $instrument->type?->name,
            'purpose_label' => AssessmentSummaryQuery::purposeLabel((string) $instrument->purpose),
            'applied_on' => $instrument->applied_on->toDateString(),
            'academic_period_id' => (int) $instrument->academic_period_id,
            'status_label' => AssessmentSummaryQuery::stateLabel($instrument),
            'counts_toward_classification' => $this->eligibility->isConfiguredToCount($instrument),
            // O peso do próprio elemento, quando a escola lhe deu um. Null é
            // «não foi declarado», e não «pesa zero» — a diferença importa.
            'weight' => $instrument->weight === null ? null : (string) $instrument->weight,
            'total_points' => $instrument->total_points === null ? null : (string) $instrument->total_points,
            'domains' => array_values($domains),
        ];
    }

    /**
     * O resultado de UM aluno NUM elemento, pelo motor canónico.
     *
     * @param  Collection<string, StudentItemScore>  $scores
     * @param  array<int, string>  $domainWeights
     * @param  list<ScaleBand>  $scaleBands
     * @param  Collection<int, Collection<int, SubjectParticipation>>  $windows  o resultado de `ClassCohort::windowsFor()`
     * @return array<string, mixed>
     */
    protected function resultRow(
        Enrollment $enrollment,
        Instrument $instrument,
        Collection $scores,
        CalculationRule $rule,
        array $domainWeights,
        array $scaleBands,
        ?Scale $scale,
        Collection $windows,
    ): array {
        // A mesma regra de ingresso tardio que o motor aplica (§11.4): um
        // elemento aplicado antes de o aluno entrar, ou depois de sair, não é
        // dele — e não é um zero dele.
        //
        // MAIS UMA CLÁUSULA (decisão do product owner, regra das duas
        // perguntas): um elemento é uma pergunta do tipo (A), decidida pela
        // SUA data. Um instrumento aplicado enquanto o aluno frequentava esta
        // disciplina continua dele mesmo que uma janela de não-frequência
        // tenha aberto depois — e um instrumento aplicado depois de essa
        // janela abrir não é dele, mesmo que a matrícula continue aberta.
        // M3: as DUAS causas de inaplicabilidade são factos diferentes e
        // dizem-se com palavras diferentes (ver `stateLabel()`) — um aluno
        // fora da janela de matrícula não está a frequentar a disciplina, mas
        // um aluno fora da janela de frequência (regra das duas perguntas)
        // continua matriculado. Confundir as duas dizia «fora do período de
        // matrícula» a um aluno que, à data do instrumento, estava matriculado
        // e apenas não frequentava a disciplina.
        $withinEnrollmentWindow = $enrollment->enrolled_on->lessThanOrEqualTo($instrument->applied_on)
            && ($enrollment->left_on === null || $enrollment->left_on->greaterThanOrEqualTo($instrument->applied_on));
        $wasAttending = ClassCohort::wasAttendingOn($windows, (int) $enrollment->getKey(), $instrument->applied_on->toDateString());
        $applicable = $withinEnrollmentWindow && $wasAttending;

        $inputs = [];
        $states = [];

        foreach ($instrument->items as $item) {
            $score = $scores->get($enrollment->getKey().':'.$item->getKey());
            $state = $score !== null ? $score->result_state : ResultState::Pending;
            $states[] = $state;

            $allocations = [];
            foreach ($item->domainAllocations as $allocation) {
                $allocations[] = [
                    'domain_id' => (int) $allocation->domain_id,
                    'allocation_percent' => (string) $allocation->allocation_percent,
                ];
            }

            $inputs[] = new ScoreInput(
                instrumentId: (int) $instrument->getKey(),
                itemCode: $item->code,
                pointsPossible: (string) $item->points_possible,
                state: $state,
                pointsEarned: $score !== null ? $score->points_earned : null,
                isBonus: $item->is_bonus,
                eligible: $applicable,
                allocations: $allocations,
            );
        }

        $outcome = $this->engine->calculate($inputs, $domainWeights, $rule, $scaleBands);
        $level = $this->proposals->bandFor($scale, $outcome->normalizedValue);

        // OS PONTOS, ALÉM DA PERCENTAGEM. A percentagem diz COMO correu; os
        // pontos dizem QUANTO este elemento pesa no ano — e é o peso, e não o
        // resultado, que explica por que motivo o desempenho acumulado não é a
        // média dos semestres. Vêm somados do próprio motor, domínio a domínio,
        // e não recontados a partir das cotações dos itens: um item bónus ou um
        // item excluído dariam outro número (§17).
        $earned = '0';
        $possible = '0';

        foreach ($outcome->domains as $domainOutcome) {
            $earned = Bc::add($earned, Bc::of($domainOutcome->pointsEarned));
            $possible = Bc::add($possible, Bc::of($domainOutcome->pointsPossible));
        }

        return [
            'instrument_id' => (int) $instrument->getKey(),
            'applicable' => $applicable,
            'normalized_value' => $outcome->normalizedValue,
            'points_earned' => Bc::truncate($earned, 4),
            'points_possible' => Bc::truncate($possible, 4),
            'result_state' => $outcome->resultState,
            'state_label' => $this->stateLabel($applicable, $withinEnrollmentWindow, $states, $outcome->normalizedValue),
            'level' => $level === null ? null : [
                'scale_level_id' => (int) $level->id,
                'code' => (string) $level->code,
                'label' => (string) $level->label,
                'sequence' => (int) $level->sequence,
                'is_negative' => (bool) $level->is_negative,
            ],
        ];
    }

    /**
     * O estado deste aluno neste elemento, em palavras.
     *
     * NÃO REPETE A LEITURA DE `AssessmentSummaryQuery`, que responde a outra
     * pergunta — «em que ponto está a correção deste elemento» — e é escrita
     * para o ecrã de Avaliações. Aqui a pergunta é «o que aconteceu a este
     * aluno neste elemento», e a resposta mais importante é a primeira: o
     * elemento não se aplicava a ele.
     *
     * M3: DUAS RAZÕES, DUAS FRASES. «Fora do período de matrícula» só é
     * verdade quando a matrícula (`enrolled_on`/`left_on`) é a causa — quando
     * a matrícula continua dentro da janela e é a frequência da disciplina
     * (regra das duas perguntas) que exclui o elemento, dizer «fora do
     * período de matrícula» a um aluno matriculado seria falso.
     *
     * @param  list<ResultState>  $states
     */
    protected function stateLabel(bool $applicable, bool $withinEnrollmentWindow, array $states, ?string $value): string
    {
        if (! $applicable) {
            return $withinEnrollmentWindow
                ? 'Não aplicável — não frequentava a disciplina nesta data'
                : 'Não aplicável — fora do período de matrícula';
        }

        if ($states === []) {
            return 'Sem itens definidos';
        }

        if ($value !== null) {
            return 'Realizado';
        }

        $unique = array_unique(array_map(fn (ResultState $state): string => $state->value, $states));

        if ($unique === [ResultState::Pending->value]) {
            return 'Por corrigir';
        }

        // Estados sem valor: falta, dispensa, anulação. Cada um diz-se pelo seu
        // próprio nome — «faltou» e «dispensado» não são a mesma coisa, e
        // nenhum deles é um zero (§13.3).
        $labels = [];
        foreach ($states as $state) {
            $labels[$state->label()] = true;
        }

        return implode(' · ', array_keys($labels));
    }

    /**
     * @return array{0: CalculationRule, 1: array<int, string>, 2: list<ScaleBand>}
     */
    protected function configuration(mixed $version): array
    {
        $absence = match ($version->absence_mode) {
            'exclude_all', 'zero_all', 'zero_unjustified_only', 'exclude_all_warn' => $version->absence_mode,
            default => 'exclude_all_warn',
        };
        $rounding = match ($version->rounding_mode) {
            'half_up', 'half_down', 'half_even', 'ceil', 'floor', 'none' => $version->rounding_mode,
            default => 'half_up',
        };
        $stage = match ($version->rounding_stage) {
            'final_only', 'each_domain', 'each_stage' => $version->rounding_stage,
            default => 'final_only',
        };

        $rule = new CalculationRule(
            absenceMode: $absence,
            roundingMode: $rounding,
            roundingScale: $version->rounding_scale,
            roundingStage: $stage,
        );

        /** @var array<int, string> $domainWeights */
        $domainWeights = $version->domains()->pluck('weight_percent', 'domain_id')->all();

        /** @var list<ScaleBand> $bands */
        $bands = $version->scale->levels()
            ->whereNotNull('band_min_normalized')
            ->whereNotNull('band_max_normalized')
            ->get()
            ->map(fn ($level): ScaleBand => new ScaleBand(
                id: $level->id,
                bandMin: (string) $level->band_min_normalized,
                bandMax: (string) $level->band_max_normalized,
            ))
            ->all();

        return [$rule, $domainWeights, $bands];
    }

    /**
     * @param  Collection<int, Instrument>  $instruments
     * @return array<int, string>
     */
    protected function domainNames(Collection $instruments): array
    {
        $ids = [];

        foreach ($instruments as $instrument) {
            foreach ($instrument->items as $item) {
                foreach ($item->domainAllocations as $allocation) {
                    $ids[(int) $allocation->domain_id] = true;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var array<int, string> $names */
        $names = Domain::query()
            ->whereIn('id', array_keys($ids))
            ->pluck('name', 'id')
            ->all();

        return $names;
    }
}
