<?php

namespace App\Models;

/**
 * Quem escreveu uma mensagem do fio.
 *
 * `System` existe para uma coisa só: a mensagem que o auto-resolve dos 30 dias
 * deixa. Sem ela, um pedido fechava-se sozinho e o histórico não dizia porquê —
 * e quem o abrisse um ano depois leria um fim sem explicação.
 */
enum SupportAuthorRole: string
{
    /** Quem pediu ajuda. Pode não ter conta. */
    case Requester = 'requester';

    /** Quem responde pela plataforma. Tem sempre conta — a base de dados exige-o. */
    case Operator = 'operator';

    /** A aplicação a explicar-se: auto-resolve, e nada mais na V1. */
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Requester => __('Quem pediu'),
            self::Operator => __('Suporte'),
            self::System => __('Sistema'),
        };
    }
}
