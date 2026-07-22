<?php

namespace App\Models;

/**
 * What caused a calculation snapshot to be frozen (§13.6). A snapshot is written
 * once, at one of these moments, and never again.
 */
enum SnapshotTrigger: string
{
    case ProposalConfirmed = 'proposal_confirmed';
    case PeriodClosed = 'period_closed';
    case YearClosed = 'year_closed';
    case ProfileMigration = 'profile_migration';

    public function label(): string
    {
        return match ($this) {
            self::ProposalConfirmed => __('Confirmação da proposta'),
            self::PeriodClosed => __('Encerramento do período'),
            self::YearClosed => __('Encerramento do ano'),
            self::ProfileMigration => __('Migração de perfil'),
        };
    }
}
