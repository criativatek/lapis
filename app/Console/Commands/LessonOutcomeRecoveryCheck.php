<?php

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Que recuperação se aplica ao registo de «aula não realizada» (`not_held`) e
 * da greve como motivo de ausência. SÓ LEITURA: conta e sai, nunca escreve.
 *
 * Código ANTERIOR a esta funcionalidade lê `lessons.outcome` e
 * `lessons.outcome_reason` através de enums que não têm estes valores: um
 * `ValueError` ao ler QUALQUER aula da turma. As colunas novas ficam inertes,
 * mas os valores não. Por isso:
 *
 *  - sem nenhuma aula `not_held` nem motivo `strike` (sai 0): um rollback só
 *    de código é seguro;
 *  - com alguma (sai 1): nunca repor código abaixo desta versão — corrigir
 *    para a frente. Voltar atrás o código não desfaz as linhas já gravadas;
 *    apagá-las ou convertê-las é uma operação de dados que precisa de
 *    autorização expressa.
 *
 * SÓ CONTAGENS E IDS DE ORGANIZAÇÃO: nunca causas, situações nem descrições.
 */
class LessonOutcomeRecoveryCheck extends Command
{
    protected $signature = 'lapis:lesson-outcome-recovery-check';

    protected $description = 'Say whether a code-only rollback of the «aula não realizada» outcome is safe: counts the not_held lessons and the strike absences (read-only)';

    public function handle(CurrentOrganization $currentOrganization): int
    {
        $notHeld = 0;
        $strikeAbsences = 0;

        $this->line('Aulas não realizadas — recuperação');

        foreach (Organization::query()->withoutGlobalScope('organization')->orderBy('id')->get() as $organization) {
            $currentOrganization->runFor($organization, function () use ($organization, &$notHeld, &$strikeAbsences): void {
                $organizationNotHeld = Lesson::query()->where('outcome', 'not_held')->count();
                $organizationStrike = Lesson::query()->where('outcome_reason', 'strike')->count();

                if ($organizationNotHeld === 0 && $organizationStrike === 0) {
                    return;
                }

                $notHeld += $organizationNotHeld;
                $strikeAbsences += $organizationStrike;

                $this->line("  organização #{$organization->id}: aulas não realizadas: {$organizationNotHeld}; ausências por greve: {$organizationStrike}");
            });
        }

        // Linhas de organizações apagadas ou fora do âmbito: o total da tabela é a
        // verdade, e a soma por organização nunca pode ficar abaixo dele.
        $total = (int) DB::table('lessons')->where('outcome', 'not_held')->orWhere('outcome_reason', 'strike')->count();
        $this->line("  aulas não realizadas: {$notHeld}; ausências por greve: {$strikeAbsences}");

        if ($total === 0) {
            $this->info('Sem aulas não realizadas nem ausências por greve: o rollback só de código é seguro. As colunas novas ficam inertes.');

            return self::SUCCESS;
        }

        $this->warn('Há registos que o código anterior não sabe ler (falharia ao abrir a turma). Não repor código abaixo desta versão — corrigir para a frente, ou converter os registos com autorização expressa.');

        return self::FAILURE;
    }
}
