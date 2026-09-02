<?php

namespace App\Actions\Support;

use App\Models\SupportRequest;
use App\Models\SupportSeverity;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * As duas decisões que um operador toma sobre a fila: quanto pesa, e de quem é.
 *
 * COM AUTOR NO RASTO, ao contrário da criação. A ADR-0011 §10 distingue as duas
 * coisas e a distinção é deliberada: um pedido nasce sem autor no rasto porque
 * o autor seria o titular dos dados, e o rasto de auditoria não pode carregar
 * identificadores dele. Um acto do operador é o oposto — é precisamente por
 * saber quem o praticou que ele serve para alguma coisa.
 *
 * NENHUMA DESTAS NOTIFICA NINGUÉM. Não são conversa: o professor não fica a
 * saber que o pedido dele foi classificado como cosmético, e ainda bem — seria
 * uma promessa implícita sobre quando é que vai ser tratado, e é justamente
 * isso que a §13 recusou.
 */
class TriageSupportRequest
{
    public function __construct(protected AuditLog $audit) {}

    public function setSeverity(SupportRequest $request, User $operator, ?SupportSeverity $severity): SupportRequest
    {
        return DB::transaction(function () use ($request, $operator, $severity): SupportRequest {
            $previous = $request->severity;

            $request->forceFill(['severity' => $severity?->value])->save();

            $this->audit->recordPlatform(
                'support.severity',
                $operator,
                'Pedido de suporte '.$request->reference.': severidade '.($severity === null ? 'sem' : $severity->value),
                ['reference' => $request->reference, 'from' => $previous?->value, 'to' => $severity?->value],
            );

            return $request->fresh();
        });
    }

    /** Passar `$assignee` a null larga o pedido. */
    public function assign(SupportRequest $request, User $operator, ?User $assignee): SupportRequest
    {
        return DB::transaction(function () use ($request, $operator, $assignee): SupportRequest {
            // O par é escrito de uma vez: metade de uma atribuição é uma linha
            // que ninguém sabe ler, e o CHECK da base diz o mesmo em SQL.
            $request->forceFill([
                'assigned_to' => $assignee?->getKey(),
                'assigned_at' => $assignee === null ? null : now(),
            ])->save();

            $this->audit->recordPlatform(
                'support.assigned',
                $operator,
                'Pedido de suporte '.$request->reference.($assignee === null ? ': largado.' : ': atribuido.'),
                // O NOME do responsavel nao entra: o rasto guarda que houve
                // atribuicao e quem a fez, e quem a recebeu le-se na propria
                // linha do pedido. Um identificador a mais no rasto e um a mais.
                ['reference' => $request->reference, 'assigned' => $assignee !== null],
            );

            return $request->fresh();
        });
    }
}
