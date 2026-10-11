<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Concede ou revoga a autorização de ver /admin/usage. É a primeira concessão,
 * feita no servidor; depois quem já a tem pode concedê-la no backoffice. Só
 * aceita quem já é administrador da plataforma.
 */
class GrantUsageInsightsAccess extends Command
{
    protected $signature = 'lapis:grant-usage-insights {email} {--revoke : Remove the usage-insights authorisation instead of granting it}';

    protected $description = 'Grant or revoke usage-insights access for a platform admin by email';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("Nenhum utilizador com o email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');

        if ($grant && ! $user->isPlatformAdmin()) {
            $this->error("{$user->email} não é administrador da plataforma; a autorização não se concede a quem não o é.");

            return self::FAILURE;
        }

        $user->forceFill(['can_view_usage_insights' => $grant])->save();

        $this->info($grant
            ? "{$user->email} pode agora ver as métricas de utilização."
            : "{$user->email} deixou de poder ver as métricas de utilização.");

        return self::SUCCESS;
    }
}
