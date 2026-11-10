<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Domain\Assessment\CalculationOutcome;
use App\Domain\Assessment\DomainOutcome;
use App\Models\Enrollment;
use App\Models\Instrument;
use Illuminate\Support\Collection;

/**
 * DE QUE INSTRUMENTOS NASCE O QUANTITATIVO de cada domínio da pauta.
 *
 * NÃO CALCULA NADA DE NOVO. Tudo o que sai daqui vem da `explanation` que o
 * `CalculationEngine` já produziu (`included` e `excluded` de cada domínio);
 * esta classe só AGRUPA por instrumento e escreve a proporção entre as
 * parcelas, como `AccumulatedBreakdown` e `CoverageExplanation` já fazem. Se
 * refizesse a conta por sua conta, passaria a haver duas respostas para o mesmo
 * número.
 *
 * A SOMA DOS ELEMENTOS CONTADOS REPRODUZ O QUANTITATIVO. `points_earned` e
 * `points_possible` de cada elemento são já ponderados pela alocação ao
 * domínio, e a razão entre as suas somas (só dos que contaram) é exatamente o
 * `normalized_value` do domínio. O bónus soma ao numerador e nunca ao
 * denominador — a mesma regra do motor.
 *
 * UM INSTRUMENTO É UMA LINHA, mesmo que tenha itens contados e itens de fora
 * (ex.: uma ficha em que só faltou a uma das perguntas). O motivo da exclusão
 * viaja CRU (`absent`, `pending`, …): quem o traduz em palavras é o ecrã.
 * Um domínio sem entradas devolve uma lista vazia — nunca um zero inventado.
 */
class EvaluationSheetElements
{
    /**
     * @param  list<array{enrollment: Enrollment, outcome: CalculationOutcome}>  $results
     * @return array<int, array<int, list<array<string, mixed>>>> enrollment id => domain id => elementos
     */
    public function forResults(array $results): array
    {
        $instruments = $this->instrumentsBehind($results);
        $byEnrollment = [];

        foreach ($results as $row) {
            $outcome = $row['outcome'];
            $domainOutcomes = collect($outcome->domains)->keyBy('domainId');
            $perDomain = [];

            foreach ($this->domainExplanations($outcome) as $explanation) {
                $domainId = (int) ($explanation['domain_id'] ?? 0);

                $perDomain[$domainId] = $this->elementsOf(
                    $explanation,
                    $instruments,
                    $domainOutcomes->get($domainId),
                );
            }

            $byEnrollment[(int) $row['enrollment']->getKey()] = $perDomain;
        }

        return $byEnrollment;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function domainExplanations(CalculationOutcome $outcome): array
    {
        $domains = $outcome->explanation['domains'] ?? [];

        return is_array($domains) ? array_values($domains) : [];
    }

    /**
     * Uma consulta para a turma inteira: todos os instrumentos nomeados por
     * qualquer explicação de domínio de qualquer aluno.
     *
     * @param  list<array{enrollment: Enrollment, outcome: CalculationOutcome}>  $results
     * @return Collection<int, Instrument>
     */
    protected function instrumentsBehind(array $results): Collection
    {
        $ids = [];

        foreach ($results as $row) {
            foreach ($this->domainExplanations($row['outcome']) as $explanation) {
                foreach (['included', 'excluded'] as $side) {
                    foreach ($explanation[$side] ?? [] as $entry) {
                        if (isset($entry['instrument_id'])) {
                            $ids[(int) $entry['instrument_id']] = true;
                        }
                    }
                }
            }
        }

        if ($ids === []) {
            return collect();
        }

        /** @var Collection<int, Instrument> $instruments */
        $instruments = Instrument::query()
            ->whereKey(array_keys($ids))
            ->get(['id', 'title', 'applied_on'])
            ->keyBy('id');

        return $instruments;
    }

    /**
     * @param  array<string, mixed>  $explanation
     * @param  Collection<int, Instrument>  $instruments
     * @return list<array<string, mixed>>
     */
    protected function elementsOf(array $explanation, Collection $instruments, ?DomainOutcome $domainOutcome): array
    {
        /** @var array<int, array{earned: numeric-string, possible: numeric-string, count: int, reasons: array<string, int>, excluded: int}> $groups */
        $groups = [];

        foreach ($explanation['included'] ?? [] as $entry) {
            if (! isset($entry['instrument_id'])) {
                continue; // Explicação antiga, sem instrumento: não se inventa.
            }

            $id = (int) $entry['instrument_id'];
            $groups[$id] ??= $this->emptyGroup();

            $fraction = Bc::div(Bc::of((string) $entry['allocation_percent']), '100');
            $groups[$id]['earned'] = Bc::add($groups[$id]['earned'], Bc::mul(Bc::of((string) $entry['earned']), $fraction));

            // O bónus soma ao numerador e nunca ao denominador (regra do motor).
            if (($entry['is_bonus'] ?? false) === false) {
                $groups[$id]['possible'] = Bc::add($groups[$id]['possible'], Bc::mul(Bc::of((string) $entry['possible']), $fraction));
            }

            $groups[$id]['count']++;
        }

        foreach ($explanation['excluded'] ?? [] as $entry) {
            if (! isset($entry['instrument_id'])) {
                continue;
            }

            $id = (int) $entry['instrument_id'];
            $groups[$id] ??= $this->emptyGroup();
            $reason = (string) $entry['reason'];

            $groups[$id]['reasons'][$reason] = ($groups[$id]['reasons'][$reason] ?? 0) + 1;
            $groups[$id]['excluded']++;
        }

        $domainPossible = $domainOutcome?->pointsPossible;
        $elements = [];

        foreach ($groups as $id => $group) {
            $instrument = $instruments->get($id);

            if ($instrument === null) {
                continue;
            }

            $counted = $group['count'] > 0;
            $appliedOn = $instrument->applied_on;

            $elements[] = [
                'instrument_id' => $id,
                'title' => (string) $instrument->title,
                'applied_on' => $appliedOn->format('Y-m-d'),
                'applied_on_label' => $appliedOn->format('d/m/Y'),
                'counted' => $counted,
                'normalized_value' => $counted && ! Bc::isZero($group['possible'])
                    ? Bc::truncate(Bc::mul(Bc::div($group['earned'], $group['possible']), '100'), 6)
                    : null,
                'points_earned' => $counted ? Bc::truncate($group['earned'], 4) : null,
                'points_possible' => $counted ? Bc::truncate($group['possible'], 4) : null,
                // O peso EFETIVO: a fatia do denominador do domínio que este
                // instrumento ocupa. Nunca um peso configurado.
                'weight_percent' => $counted && $domainPossible !== null && ! Bc::isZero(Bc::of($domainPossible))
                    ? Bc::truncate(Bc::mul(Bc::div($group['possible'], Bc::of($domainPossible)), '100'), 6)
                    : null,
                'item_count' => $group['count'],
                'excluded_item_count' => $group['excluded'],
                'reasons' => array_map(
                    fn (string $reason, int $count): array => ['reason' => $reason, 'item_count' => $count],
                    array_keys($group['reasons']),
                    array_values($group['reasons']),
                ),
            ];
        }

        usort($elements, fn (array $a, array $b): int => [$a['applied_on'], $a['title'], $a['instrument_id']]
            <=> [$b['applied_on'], $b['title'], $b['instrument_id']]);

        return $elements;
    }

    /**
     * @return array{earned: numeric-string, possible: numeric-string, count: int, reasons: array<string, int>, excluded: int}
     */
    protected function emptyGroup(): array
    {
        return ['earned' => '0', 'possible' => '0', 'count' => 0, 'reasons' => [], 'excluded' => 0];
    }
}
