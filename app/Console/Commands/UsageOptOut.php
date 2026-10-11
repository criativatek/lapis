<?php

namespace App\Console\Commands;

use App\Actions\Usage\ForgetUsageData;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Executa uma oposição procedente à recolha de métricas de utilização
 * (docs/usage-insights.md, «Direito de oposição»): grava `usage_opted_out_at` e
 * apaga o que já existia sobre a pessoa. `--undo` volta a ligar a recolha a
 * partir desse momento, sem recuperar nada.
 */
class UsageOptOut extends Command
{
    protected $signature = 'lapis:usage-opt-out {email} {--undo : Turn collection back on for this person instead}';

    protected $description = 'Record a person\'s objection to usage metrics and erase what was collected about them';

    public function handle(ForgetUsageData $forget): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("Nenhum utilizador com o email {$this->argument('email')}.");

            return self::FAILURE;
        }

        if ($this->option('undo')) {
            $user->forceFill(['usage_opted_out_at' => null])->save();
            $this->info("A recolha de métricas de utilização voltou a ligar-se para {$user->email}.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $forget): void {
            $user->forceFill(['usage_opted_out_at' => now()])->save();
            $forget->forUser($user);
        });

        $this->info("{$user->email} opôs-se à recolha: dados de utilização eliminados e recolha desligada.");

        return self::SUCCESS;
    }
}
