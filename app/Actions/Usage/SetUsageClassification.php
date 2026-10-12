<?php

namespace App\Actions\Usage;

use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Usage\UsageClassification;
use Illuminate\Support\Facades\DB;

/**
 * O único escritor de `organizations.usage_classification`.
 *
 * Diz para que serve a organização NAS MÉTRICAS DE UTILIZAÇÃO e mais nada: não
 * é `is_test_account` (comercial, lido só pelo pré-voo), não toca em plano,
 * módulos, acesso nem subscrição. Nada é inferido do nome, do email, do plano ou
 * da atividade — decide um operador nomeado.
 *
 * Audita como evento de PLATAFORMA (`organization_id` nulo): o responsável
 * institucional lê a auditoria da sua organização e não deve saber como esta é
 * classificada para métricas internas. Mudar para o mesmo valor não grava nem
 * audita.
 */
class SetUsageClassification
{
    public function __construct(protected AuditLog $audit) {}

    public function set(
        Organization $organization,
        User $operator,
        UsageClassification $classification,
        ?string $note = null,
    ): Organization {
        return DB::transaction(function () use ($organization, $operator, $classification, $note): Organization {
            $locked = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $previous = UsageClassification::from((string) ($locked->usage_classification ?? 'unclassified'));

            if ($previous === $classification) {
                return $locked;
            }

            $note = $note === null || trim($note) === '' ? null : trim($note);

            // `forceFill`, porque as colunas não são fillable — ver o modelo.
            $locked->forceFill([
                'usage_classification' => $classification->value,
                'usage_classified_at' => now(),
            ])->save();

            $this->audit->recordPlatform(
                'admin.usage_classification_set',
                $operator,
                'Organização classificada para as métricas de utilização: '.$classification->label().'.',
                [
                    'organization_id' => $locked->getKey(),
                    'previous' => $previous->value,
                    'classification' => $classification->value,
                    'note' => $note,
                ],
            );

            return $locked;
        });
    }
}
