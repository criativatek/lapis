<?php

namespace App\Services\Usage\Insights;

use App\Support\Usage\UsageFeature;

/**
 * O que um utilizador fez num período, em números. «Ativo» é ter consultado ou
 * concluído uma ação — uma falha sozinha não torna ninguém ativo.
 */
final readonly class UserActivity
{
    /**
     * @param  list<string>  $activeDayList  `Y-m-d`, por ordem
     * @param  list<UsageFeature>  $features  as funcionalidades com atividade
     */
    public function __construct(
        public int $activeDays,
        public int $activeWeeks,
        public int $actions,
        public int $items,
        public int $failures,
        public array $activeDayList,
        public array $features,
    ) {}

    public static function none(): self
    {
        return new self(0, 0, 0, 0, 0, [], []);
    }
}
