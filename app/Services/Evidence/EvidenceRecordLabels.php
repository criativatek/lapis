<?php

namespace App\Services\Evidence;

use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;

/**
 * Short, type-specific suffixes for compact listings — shared by the class
 * logbook and the consultation, so one record reads the same in both.
 */
final class EvidenceRecordLabels
{
    /**
     * «Não realizado», «Positiva», «Avaliação positiva — Incluir no
     * relatório»… or null when the kind has nothing to add.
     */
    public static function detail(EvidenceRecord $record): ?string
    {
        return match ($record->kind) {
            EvidenceKind::Homework => $record->homework_status?->label(),
            EvidenceKind::Participation => $record->participation_level?->label(),
            EvidenceKind::Progress, EvidenceKind::Difficulty => $record->domain?->name,
            EvidenceKind::Activity => $record->activity_evaluation === null ? null : ($record->activity_evaluation->label().($record->activity_include_in_report ? ' — '.__('Incluir no relatório') : '')),
            default => null,
        };
    }
}
