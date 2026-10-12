<?php

namespace App\Services\Usage\Insights;

/**
 * Uma linha de `usage_daily_rollups` agrupada por pessoa, dia e funcionalidade.
 */
final readonly class RollupRow
{
    public function __construct(
        public int $userId,
        public string $day,
        public string $feature,
        public int $views,
        public int $actions,
        public int $items,
        public int $failures,
        public int $itemsCreated = 0,
        public int $itemsChanged = 0,
        public int $itemsUnchanged = 0,
    ) {}

    /** Consulta ou ação concluída — uma falha sozinha não torna ninguém ativo. */
    public function isActive(): bool
    {
        return $this->views > 0 || $this->actions > 0;
    }
}
