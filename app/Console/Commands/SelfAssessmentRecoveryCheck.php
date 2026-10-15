<?php

namespace App\Console\Commands;

use App\Support\Assessment\SelfAssessmentWrites;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Which recovery applies to the 0.167.0 self-assessment, said from the data
 * itself (docs/self-assessment-recovery.md). Read-only.
 *
 * The 0.166.0 code can read everything the 0.167.0 writes EXCEPT two things: a
 * number answered on a scale without levels (it does not know the column) and
 * a student with two self-assessments for one period (it picks one in whatever
 * order the database returns them). A started «nova autoavaliação» leaves the
 * earlier one out of every 0.166.0 screen. While none of the three exists, a
 * code-only rollback is a full recovery (scenario a); once any does, it is not,
 * and the self-assessment is contained instead (scenario b).
 *
 * Counted across the whole platform, on the tables, with no tenant: it is an
 * operator's question about the installation, asked during maintenance.
 */
class SelfAssessmentRecoveryCheck extends Command
{
    protected $signature = 'lapis:self-assessment-recovery-check';

    protected $description = 'Say whether a code-only rollback of 0.167.0 is still a full recovery for the self-assessment (read-only)';

    public function handle(): int
    {
        $numeric = DB::table('self_assessment_responses')->whereNotNull('numeric_value')->count();

        $twoInOnePeriod = DB::query()->fromSub(
            DB::table('self_assessments')
                ->select('enrollment_id', 'academic_period_id')
                ->groupBy('enrollment_id', 'academic_period_id')
                ->havingRaw('COUNT(*) > 1'),
            'pairs',
        )->count();

        $renewed = DB::table('audit_events')->where('event', 'self_assessment.renewed')->count();
        $reworded = DB::table('audit_events')->where('event', 'self_assessment.questions_reworded')->count();
        $readOnly = SelfAssessmentWrites::readOnly();

        $this->line('Autoavaliação — recuperação da 0.167.0');
        $this->line("  respostas numéricas: {$numeric}");
        $this->line("  alunos com duas autoavaliações num período: {$twoInOnePeriod}");
        $this->line("  novas autoavaliações iniciadas: {$renewed}");
        $this->line("  reflexões personalizadas (informativo): {$reworded}");
        $this->line('  modo só de leitura: '.($readOnly ? 'ligado (restauros de backup suspensos)' : 'desligado'));

        if ($numeric === 0 && $twoInOnePeriod === 0 && $renewed === 0) {
            $this->info('Cenário a): nada que a 0.166.0 não saiba ler. O rollback só de código é uma recuperação completa.');

            return self::SUCCESS;
        }

        $this->warn('Cenário b): há dados que só a 0.167.0 apresenta corretamente. Não repor a 0.166.0 — conter com o modo só de leitura e corrigir numa 0.167.x.');

        return self::FAILURE;
    }
}
