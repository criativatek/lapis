<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Console\Command;

/**
 * Concede ou revoga, no servidor, uma das duas autorizações das métricas de
 * utilização a um administrador da plataforma:
 *
 * - por omissão, a de VER /admin/usage (`can_view_usage_insights`);
 * - com `--collection-control`, a SEPARADA de LIGAR E DESLIGAR a recolha
 *   (`can_control_usage_collection`). Fica na auditoria de plataforma, sem
 *   causador (é a consola), com quem a recebeu.
 *
 * É o caminho da primeira concessão; depois, quem já tem cada autorização pode
 * concedê-la no backoffice a outro administrador, nunca a si próprio. Só aceita
 * quem já é administrador da plataforma.
 */
class GrantUsageInsightsAccess extends Command
{
    protected $signature = 'lapis:grant-usage-insights
        {email}
        {--revoke : Remove the authorisation instead of granting it}
        {--collection-control : Act on the separate authorisation to start and stop the collection}';

    protected $description = 'Grant or revoke usage-insights access (or, with --collection-control, collection control) for a platform admin by email';

    public function handle(AuditLog $audit): int
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

        if ($this->option('collection-control')) {
            return $this->collectionControl($user, $grant, $audit);
        }

        $user->forceFill(['can_view_usage_insights' => $grant])->save();

        $this->info($grant
            ? "{$user->email} pode agora ver as métricas de utilização."
            : "{$user->email} deixou de poder ver as métricas de utilização.");

        return self::SUCCESS;
    }

    private function collectionControl(User $user, bool $grant, AuditLog $audit): int
    {
        if ($user->can_control_usage_collection === $grant) {
            $this->info($grant
                ? "Sem alterações: {$user->email} já podia controlar a recolha."
                : "Sem alterações: {$user->email} já não podia controlar a recolha.");

            return self::SUCCESS;
        }

        $user->forceFill(['can_control_usage_collection' => $grant])->save();

        $audit->recordPlatformWithoutCauser(
            $grant ? 'admin.usage_collection_control_granted' : 'admin.usage_collection_control_revoked',
            $grant
                ? 'Concedida no servidor a autorização de ligar e desligar a recolha de métricas de utilização.'
                : 'Retirada no servidor a autorização de ligar e desligar a recolha de métricas de utilização.',
            ['target_user_id' => $user->id, 'via' => 'console'],
        );

        $this->info($grant
            ? "{$user->email} pode agora ligar e desligar a recolha de métricas (lapis:usage-collection)."
            : "{$user->email} deixou de poder ligar e desligar a recolha de métricas.");

        return self::SUCCESS;
    }
}
