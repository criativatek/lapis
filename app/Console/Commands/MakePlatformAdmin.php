<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Promotes (or revokes) a user to platform administrator — the SaaS operator.
 * Bootstraps the very first admin, since the flag is never mass-assignable and
 * the backoffice itself is gated behind it.
 */
class MakePlatformAdmin extends Command
{
    protected $signature = 'lapis:make-admin {email} {--revoke : Remove platform-admin access instead of granting it}';

    protected $description = 'Grant or revoke platform-admin access for a user by email';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("Nenhum utilizador com o email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        $attributes = [
            'is_platform_admin' => $grant,
            'is_support_technician' => $grant,
        ];

        // Revogar o estatuto revoga também a autorização das métricas de
        // utilização. Conceder o estatuto NÃO a concede: é um passo à parte.
        if (! $grant) {
            $attributes['can_view_usage_insights'] = false;
        }

        $user->forceFill($attributes)->save();

        $this->info($grant
            ? "{$user->email} é agora administrador da plataforma."
            : "{$user->email} deixou de ser administrador da plataforma.");

        return self::SUCCESS;
    }
}
