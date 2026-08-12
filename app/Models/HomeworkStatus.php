<?php

namespace App\Models;

/**
 * How much of the assigned homework a student completed — the only detail
 * field for EvidenceKind::Homework.
 */
enum HomeworkStatus: string
{
    case Done = 'done';
    case PartiallyDone = 'partially_done';
    case NotDone = 'not_done';

    public function label(): string
    {
        return match ($this) {
            self::Done => __('Realizado'),
            self::PartiallyDone => __('Parcialmente realizado'),
            self::NotDone => __('Não realizado'),
        };
    }
}
