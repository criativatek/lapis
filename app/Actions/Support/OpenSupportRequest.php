<?php

namespace App\Actions\Support;

use App\Models\Organization;
use App\Models\SupportAuthorRole;
use App\Models\SupportCategory;
use App\Models\SupportMessage;
use App\Models\SupportNotificationType;
use App\Models\SupportRequest;
use App\Models\SupportRequestStatus;
use App\Models\SupportSource;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Support\SupportNotifier;
use App\Support\Support\SupportReference;
use Illuminate\Support\Facades\DB;

/**
 * Abrir um pedido — de um visitante ou de quem tem sessão iniciada.
 *
 * UM SÓ CAMINHO PARA OS DOIS. O que muda entre eles é apenas quem preenche o
 * nome e o email: um visitante escreve-os, um utilizador autenticado tem-nos na
 * conta e nunca os envia. Ter duas acções faria com que uma regra nova — um
 * campo obrigatório, um evento de auditoria — tivesse de ser lembrada em dois
 * sítios, e um dos dois acabaria por ficar para trás.
 *
 * A DESCRIÇÃO É A PRIMEIRA MENSAGEM DO FIO, e também fica na coluna
 * `description`. Não é duplicação por descuido: a coluna é o que a anonimização
 * põe a NULL, e a mensagem é o que ela apaga — as duas operações são
 * diferentes, e o fio tem de começar em algum lado para que a conversa se leia
 * de cima a baixo.
 *
 * O EMAIL SAI DEPOIS DO COMMIT. `DB::afterCommit()` garante que o pedido já
 * existe quando se tenta enviar; uma falha de SMTP fica registada em
 * `support_notification_deliveries` e **nunca** desfaz o pedido (ADR-0011 §5).
 */
class OpenSupportRequest
{
    public function __construct(
        protected AuditLog $audit,
        protected SupportNotifier $notifier,
    ) {}

    /**
     * @param  array{category: string, subject: string, description: string, requester_name?: string, requester_email?: string, technical_reference?: ?string, technical_route?: ?string}  $data
     */
    public function open(
        array $data,
        ?User $user = null,
        ?Organization $organization = null,
    ): SupportRequest {
        $request = DB::transaction(function () use ($data, $user, $organization): SupportRequest {
            /** @var SupportRequest $request */
            $request = SupportRequest::create([
                'reference' => SupportReference::generate(),
                // A IDENTIDADE VEM DO SERVIDOR quando há sessão. Aceitar o nome
                // e o email do corpo do pedido de um utilizador autenticado
                // deixaria qualquer pessoa abrir um pedido em nome de outra.
                'requester_name' => $user?->name ?? $data['requester_name'],
                'requester_email' => $user?->email ?? $data['requester_email'],
                'user_id' => $user?->getKey(),
                'organization_id' => $organization?->getKey(),
                'source' => $user === null ? SupportSource::Guest : SupportSource::Authenticated,
                'category' => SupportCategory::from($data['category']),
                'subject' => $data['subject'],
                'description' => $data['description'],
                'status' => SupportRequestStatus::Open,
                // Contexto técnico, quando o ecrã o soube dizer. `technical_code`
                // fica a NULL: nada o infere — quem classifica é um operador.
                'technical_reference' => $data['technical_reference'] ?? null,
                'technical_route' => $data['technical_route'] ?? null,
                'app_version' => (string) config('app.version'),
            ]);

            SupportMessage::create([
                'support_request_id' => $request->getKey(),
                'author_role' => SupportAuthorRole::Requester,
                'author_user_id' => $user?->getKey(),
                'body' => $data['description'],
            ]);

            // SEM CAUSER, E TAMBÉM PARA QUEM TEM CONTA. Um evento de auditoria
            // é imutável; um evento imutável que aponta para o utilizador e
            // para a organização é um identificador que a anonimização dos 24
            // meses não conseguiria apagar. Ver ADR-0011 §10 — e note-se que as
            // propriedades são todas de vocabulário fechado, sem uma palavra
            // escrita por quem pediu.
            $this->audit->recordPlatformWithoutCauser(
                'support.request_opened',
                'Pedido de suporte '.$request->reference.' aberto.',
                [
                    'reference' => $request->reference,
                    'category' => $request->category->value,
                    'source' => $request->source->value,
                    'app_version' => $request->app_version,
                ],
            );

            return $request;
        });

        DB::afterCommit(function () use ($request): void {
            $this->notifier->send($request, SupportNotificationType::RequestReceived);
            $this->notifier->send($request, SupportNotificationType::TeamNewRequest);
        });

        return $request;
    }
}
