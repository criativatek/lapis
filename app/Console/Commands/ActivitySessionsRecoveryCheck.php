<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\TimetableActivity;
use App\Models\TimetableActivityKind;
use App\Models\TimetableActivitySession;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

/**
 * Que recuperação se aplica à 0.170.0 (registo por sessão das «outras
 * atividades»), dito a partir dos próprios dados (docs/activity-sessions-recovery.md).
 * SÓ LEITURA: conta e sai, nunca escreve.
 *
 * O que a 0.170.0 escreve que a 0.169.1 não sabe ler: a tabela
 * `timetable_activity_sessions` e as colunas de numeração ficam INERTES no
 * código anterior (ninguém as lê, e as sessões guardadas ficam intactas); o que
 * NÃO é inerte é o tipo `club`: a 0.169.1 não o conhece e rebentaria ao ler uma
 * atividade desse tipo. Por isso:
 *
 *  - sem atividades `club` (sai 0): um rollback só de código para a 0.169.1 é
 *    seguro;
 *  - com atividades `club` (sai 1): nunca repor código abaixo da 0.170.0 —
 *    corrigir para a frente numa 0.170.x.
 *
 * SÓ CONTAGENS E IDS DE ORGANIZAÇÃO: nunca títulos de atividades nem texto de
 * sumários.
 */
class ActivitySessionsRecoveryCheck extends Command
{
    protected $signature = 'lapis:activity-sessions-recovery-check';

    protected $description = 'Say whether a code-only rollback of 0.170.0 is safe: counts the activity sessions, the «Clube» activities and the numbered activities (read-only)';

    public function handle(CurrentOrganization $currentOrganization): int
    {
        $sessions = 0;
        $withRecords = 0;
        $clubs = 0;
        $numbered = 0;

        $this->line('Sessões das outras atividades — recuperação da 0.170.0');

        foreach (Organization::query()->withoutGlobalScope('organization')->orderBy('id')->get() as $organization) {
            $currentOrganization->runFor($organization, function () use ($organization, &$sessions, &$withRecords, &$clubs, &$numbered): void {
                $organizationSessions = TimetableActivitySession::query()->count();
                $organizationRecords = TimetableActivitySession::query()->withRecords()->count();
                $organizationClubs = TimetableActivity::query()->where('kind', TimetableActivityKind::Club->value)->count();
                $organizationNumbered = TimetableActivity::query()->where('numbering_enabled', true)->count();

                if ($organizationSessions === 0 && $organizationClubs === 0 && $organizationNumbered === 0) {
                    return;
                }

                $sessions += $organizationSessions;
                $withRecords += $organizationRecords;
                $clubs += $organizationClubs;
                $numbered += $organizationNumbered;

                $this->line("  organização #{$organization->id}: sessões: {$organizationSessions} (com registos: {$organizationRecords}); atividades «Clube»: {$organizationClubs}; atividades numeradas: {$organizationNumbered}");
            });
        }

        $this->line("  sessões guardadas: {$sessions} (com registos: {$withRecords})");
        $this->line("  atividades «Clube»: {$clubs}");
        $this->line("  atividades com numeração das sessões: {$numbered}");

        if ($clubs === 0) {
            $this->info('Sem atividades «Clube»: o rollback só de código para a 0.169.1 é seguro. A tabela e as colunas novas ficam inertes e as sessões guardadas ficam intactas.');

            return self::SUCCESS;
        }

        $this->warn('Há atividades «Clube»: a 0.169.1 não conhece o tipo e falharia ao lê-las. Não repor código abaixo da 0.170.0 — corrigir para a frente numa 0.170.x.');

        return self::FAILURE;
    }
}
