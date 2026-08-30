<?php

namespace App\Actions\Support;

use App\Models\SupportAuthorRole;
use App\Models\SupportMessage;
use App\Models\SupportNotificationType;
use App\Models\SupportRequest;
use App\Models\SupportRequestStatus;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Support\SupportNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Uma mensagem nova no fio — e o que ela faz ao estado do pedido.
 *
 * QUEM RESPONDE MOVE A BOLA, e o estado di-lo sem ninguém ter de o escolher
 * numa caixa:
 *
 *  - **operador responde** → `waiting_for_user`, e `waiting_since` começa a
 *    contar. É deste carimbo que saem o lembrete dos 23 dias e o auto-resolve
 *    dos 30;
 *  - **requerente responde** → `open`, e o relógio da espera **para**: os dois
 *    campos voltam a NULL, porque uma espera que teve resposta acabou. Um
 *    segundo ciclo de espera terá o seu próprio lembrete, e isso é correcto —
 *    é uma espera nova.
 *
 * REABRIR É RESPONDER A UM PEDIDO RESOLVIDO (ADR-0011 §12). Não há botão
 * separado: quem tem mais a dizer, diz, e o pedido volta a `open` com
 * `resolved_at` a NULL. A consequência que importa é de retenção — sem
 * `resolved_at` o relógio dos 24 meses **para**, e um pedido activo nunca é
 * anonimizado.
 */
class ReplyToSupportRequest
{
    public function __construct(
        protected AuditLog $audit,
        protected SupportNotifier $notifier,
    ) {}

    /** A resposta de quem abriu o pedido. Reabre-o, se estava resolvido. */
    public function fromRequester(SupportRequest $request, User $user, string $body): SupportRequest
    {
        $reabriu = $request->isResolved();

        $updated = DB::transaction(function () use ($request, $user, $body, $reabriu): SupportRequest {
            $this->appendMessage($request, SupportAuthorRole::Requester, $user, $body);

            $request->forceFill([
                'status' => SupportRequestStatus::Open,
                // A espera acabou: teve resposta.
                'waiting_since' => null,
                'waiting_reminder_sent_at' => null,
                // E, se estava resolvido, deixa de estar — o relógio dos 24
                // meses para aqui e só recomeça no próximo `resolved_at`.
                'resolved_at' => null,
                'resolved_by' => null,
                'auto_resolved' => false,
            ])->save();

            if ($reabriu) {
                // O CAUSER FICA, ao contrário da criação: reabrir é um acto de
                // quem já é titular do pedido, não um facto novo sobre ele — e
                // a anonimização não precisa de o apagar, porque o evento não
                // diz mais do que o pedido já dizia.
                $this->audit->recordPlatform(
                    'support.reopened_by_requester',
                    $user,
                    'Pedido de suporte '.$request->reference.' reaberto por quem o abriu.',
                    [
                        'reference' => $request->reference,
                        'from' => SupportRequestStatus::Resolved->value,
                        'to' => SupportRequestStatus::Open->value,
                    ],
                );
            }

            return $request;
        });

        // A equipa tem de saber que há coisa nova — inclusive num pedido que
        // julgava fechado.
        DB::afterCommit(function () use ($updated): void {
            $this->notifier->send($updated, SupportNotificationType::TeamNewRequest, force: true);
        });

        return $updated;
    }

    /** A resposta de quem opera a plataforma. Passa a bola para o utilizador. */
    public function fromOperator(SupportRequest $request, User $operator, string $body): SupportRequest
    {
        $updated = DB::transaction(function () use ($request, $operator, $body): SupportRequest {
            $this->appendMessage($request, SupportAuthorRole::Operator, $operator, $body);

            $request->forceFill([
                'status' => SupportRequestStatus::WaitingForUser,
                // O relógio começa AGORA, e não na data da pergunta: a espera é
                // pela resposta a esta mensagem.
                'waiting_since' => Carbon::now(),
                'waiting_reminder_sent_at' => null,
                'resolved_at' => null,
                'resolved_by' => null,
                'auto_resolved' => false,
            ])->save();

            $this->audit->recordPlatform(
                'support.replied',
                $operator,
                'Resposta enviada no pedido de suporte '.$request->reference.'.',
                ['reference' => $request->reference, 'to' => SupportRequestStatus::WaitingForUser->value],
            );

            return $request;
        });

        DB::afterCommit(function () use ($updated): void {
            $this->notifier->send($updated, SupportNotificationType::RequestReplied, force: true);
        });

        return $updated;
    }

    /**
     * O corpo entra no fio — e **nunca** no rasto de auditoria.
     *
     * É a única cópia do que a pessoa escreveu, e é a que a anonimização apaga.
     */
    protected function appendMessage(SupportRequest $request, SupportAuthorRole $role, ?User $author, string $body): void
    {
        SupportMessage::create([
            'support_request_id' => $request->getKey(),
            'author_role' => $role,
            'author_user_id' => $author?->getKey(),
            'body' => $body,
        ]);
    }
}
