<?php

namespace App\Actions\Support;

use App\Models\RetentionHoldReason;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * SUSPENDER E RETOMAR A ELIMINAÇÃO DE UM PEDIDO.
 *
 * Um hold é uma excepção a uma promessa que fizemos por escrito, e por isso
 * tem de ser classificável e contável: o motivo é um vocabulário fechado, nunca
 * uma frase. A nota existe ao lado, opcional e INTERNA — nunca sai para
 * auditoria nem para email, e é o único campo do hold que a anonimização
 * apaga; os restantes sobrevivem como prova de que a excepção foi invocada.
 *
 * O QUE UM HOLD BLOQUEIA, E SÓ ISSO: a anonimização. O lembrete dos 23 dias e o
 * auto-resolve dos 30 continuam a correr — reter um pedido para efeitos legais
 * não é razão para deixar de responder a quem o escreveu.
 *
 * LIBERTAR NÃO REINICIA O RELÓGIO. A janela conta sempre de `resolved_at`, por
 * isso um hold libertado depois dos 24 meses é anonimizado na execução
 * seguinte — não ganha mais dois anos por ter sido levantado (ADR-0011 §9).
 */
class ManageRetentionHold
{
    public function __construct(protected AuditLog $audit) {}

    public function apply(
        SupportRequest $request,
        User $operator,
        RetentionHoldReason $reason,
        ?string $note = null,
    ): SupportRequest {
        if ($request->hasActiveHold()) {
            return $request;
        }

        return DB::transaction(function () use ($request, $operator, $reason, $note): SupportRequest {
            $request->forceFill([
                'retention_hold_at' => Carbon::now(),
                'retention_hold_by' => $operator->getKey(),
                'retention_hold_reason_code' => $reason,
                'retention_hold_note' => $note,
                // Um hold novo apaga o rasto do anterior: o que interessa é o
                // que está em vigor, e a auditoria guarda a história.
                'retention_hold_released_at' => null,
                'retention_hold_released_by' => null,
            ])->save();

            // O MOTIVO VAI, A NOTA NUNCA. A nota é o campo onde um operador
            // escreve à mão, e por isso é o único que pode conter o nome de uma
            // pessoa — num registo que é imutável por construção.
            $this->audit->recordPlatform(
                'support.hold_applied',
                $operator,
                'Eliminação suspensa no pedido de suporte '.$request->reference.'.',
                ['reference' => $request->reference, 'reason_code' => $reason->value],
            );

            return $request;
        });
    }

    public function release(SupportRequest $request, User $operator): SupportRequest
    {
        if (! $request->hasActiveHold()) {
            throw new LogicException('Não há suspensão em vigor para libertar neste pedido de suporte.');
        }

        return DB::transaction(function () use ($request, $operator): SupportRequest {
            $request->forceFill([
                'retention_hold_released_at' => Carbon::now(),
                'retention_hold_released_by' => $operator->getKey(),
            ])->save();

            $this->audit->recordPlatform(
                'support.hold_released',
                $operator,
                'Eliminação retomada no pedido de suporte '.$request->reference.'.',
                [
                    'reference' => $request->reference,
                    'reason_code' => $request->retention_hold_reason_code?->value,
                ],
            );

            return $request;
        });
    }
}
