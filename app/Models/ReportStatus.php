<?php

namespace App\Models;

/**
 * Where a report stands (§36).
 *
 * TWO STATES, AND THE LINE BETWEEN THEM IS THE POINT. A draft is alive: its
 * sections regenerate from whatever the data says now. A finalized report is a
 * document — it holds its own copy of everything it said, and no later change
 * to a grade, a logo or a school's name rewrites it (§37).
 *
 * There is no `archived` and no `published`. Neither has a defined meaning in
 * this application yet, and inventing states is inventing workflow.
 */
enum ReportStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Rascunho'),
            self::Finalized => __('Finalizado'),
        };
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isFinalized(): bool
    {
        return $this === self::Finalized;
    }
}
