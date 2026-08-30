<?php

namespace App\Support\Support;

use App\Mail\SupportNotificationMail;
use App\Models\SupportNotificationType;
use App\Models\SupportRequest;

/**
 * O email de um aviso, construído A PARTIR DO PEDIDO.
 *
 * Existe para que o primeiro envio e o reenvio manual do backoffice passem pelo
 * MESMO caminho: nada é reconstruído a partir de `support_notification_deliveries`,
 * que por isso não precisa de guardar conteúdo nenhum (ADR-0011 §6). Se um dia
 * alguém acrescentar um tipo de aviso, é aqui que ele aparece — e continua sem
 * poder trazer texto da pessoa consigo.
 */
class SupportMailFactory
{
    public static function for(SupportRequest $request, SupportNotificationType $type): SupportNotificationMail
    {
        return new SupportNotificationMail($request, $type);
    }
}
