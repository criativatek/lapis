<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/** Grants or revokes the Artisan-only technical support capability. */
class GrantSupportAccess extends Command
{
    protected $signature = 'lapis:grant-support-access {email} {--revoke : Remove support-access capability instead of granting it}';

    protected $description = 'Grant or revoke support-access capability for a user by email';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("Nenhum utilizador com o email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        $user->forceFill(['is_support_technician' => $grant])->save();

        $this->info($grant
            ? "{$user->email} tem agora acesso técnico."
            : "{$user->email} deixou de ter acesso técnico.");

        return self::SUCCESS;
    }
}
