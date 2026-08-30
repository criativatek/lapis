<?php

namespace App\Models;

/**
 * PARA QUEM, POR PAPEL — nunca por endereço.
 *
 * O da equipa deriva de `config('lapis.support.inbox')`; o de quem pediu deriva
 * da relação com o pedido. É essa ausência de coluna que faz a anonimização
 * funcionar sem ter de vir limpar a tabela técnica, e que a impede de se tornar
 * uma segunda cópia dos dados pessoais (ADR-0011 §6).
 */
enum SupportRecipientRole: string
{
    case Requester = 'requester';
    case SupportTeam = 'support_team';

    public function label(): string
    {
        return match ($this) {
            self::Requester => __('Quem pediu'),
            self::SupportTeam => __('Equipa de suporte'),
        };
    }
}
