<?php

namespace App\Console\Commands;

use App\Actions\Usage\SetUsageOptOut;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Executa uma oposição recebida por outro canal (por exemplo, por email para o
 * contacto de privacidade) pela MESMA ação que as definições da conta usam
 * (docs/usage-insights.md, «Direito de oposição»): grava `usage_opted_out_at` e
 * apaga o que já existia sobre a pessoa. `--undo` volta a ligar a recolha para
 * a pessoa a partir desse momento, sem recuperar nada.
 */
class UsageOptOut extends Command
{
    protected $signature = 'lapis:usage-opt-out {email} {--undo : Turn collection back on for this person instead}';

    protected $description = 'Record a person\'s objection to usage metrics and erase what was collected about them';

    public function handle(SetUsageOptOut $optOut): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("Nenhum utilizador com o email {$this->argument('email')}.");

            return self::FAILURE;
        }

        if ($this->option('undo')) {
            $optOut->optIn($user);
            $this->info("A recolha de métricas de utilização voltou a valer para {$user->email}, a partir de agora e sem histórico.");

            return self::SUCCESS;
        }

        $optOut->optOut($user);

        $this->info("{$user->email} opôs-se à recolha: dados de utilização eliminados e recolha desligada para esta pessoa.");

        return self::SUCCESS;
    }
}
