<?php

namespace App\Models;

/**
 * Que aviso é este. Lista fechada, porque o backoffice conta-os e o reenvio
 * reconstrói o email a partir do tipo — nunca a partir de conteúdo guardado.
 */
enum SupportNotificationType: string
{
    /** «Recebemos o seu pedido», para quem o abriu. */
    case RequestReceived = 'request_received';

    /** «Há uma resposta nova», para quem o abriu. */
    case RequestReplied = 'request_replied';

    /** O lembrete dos 23 dias, para quem o abriu. */
    case WaitingReminder = 'waiting_reminder';

    /** «Entrou um pedido novo», para a caixa da equipa. */
    case TeamNewRequest = 'team_new_request';

    public function label(): string
    {
        return match ($this) {
            self::RequestReceived => __('Pedido recebido'),
            self::RequestReplied => __('Resposta enviada'),
            self::WaitingReminder => __('Lembrete de espera'),
            self::TeamNewRequest => __('Aviso à equipa'),
        };
    }

    /** A quem se dirige. O papel decide o destinatário; o endereço nunca é guardado. */
    public function recipientRole(): SupportRecipientRole
    {
        return $this === self::TeamNewRequest
            ? SupportRecipientRole::SupportTeam
            : SupportRecipientRole::Requester;
    }
}
