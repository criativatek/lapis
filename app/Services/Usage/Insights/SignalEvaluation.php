<?php

namespace App\Services\Usage\Insights;

/**
 * O resultado de avaliar os sinais para um conjunto de pessoas.
 *
 * `signals` e `notEvaluated` são indexados por user_id; `counts` e
 * `notEvaluatedCounts` por chave de sinal (quantas pessoas).
 */
final readonly class SignalEvaluation
{
    /**
     * @param  array<int, list<Signal>>  $signals
     * @param  array<int, list<string>>  $notEvaluated  chaves dos sinais que ficaram por avaliar por falta de cobertura
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $notEvaluatedCounts
     */
    public function __construct(
        public array $signals,
        public array $notEvaluated,
        public array $counts,
        public array $notEvaluatedCounts,
    ) {}

    /**
     * @return list<Signal>
     */
    public function for(int $userId): array
    {
        return $this->signals[$userId] ?? [];
    }

    public function has(int $userId, string $key): bool
    {
        foreach ($this->for($userId) as $signal) {
            if ($signal->key === $key) {
                return true;
            }
        }

        return false;
    }
}
