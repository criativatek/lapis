<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * O ESTADO DE ENTREGA DE UM AVISO — e nada do seu conteúdo.
 *
 * Sem worker não há retry automático, e log não chega: uma falha só registada
 * num ficheiro é uma falha perdida. Esta tabela existe para que uma falha de
 * SMTP fique **visível no backoffice**, com um botão para reenviar (ADR-0011
 * §6).
 *
 * O QUE NUNCA ESTÁ AQUI: corpo, assunto, endereço do destinatário, mensagem da
 * excepção, resposta do servidor, stack trace, credenciais. O destinatário
 * deriva do papel — a equipa de `lapis.support.inbox`, quem pediu da relação
 * com o pedido — e é essa ausência que impede esta tabela técnica de se tornar
 * uma segunda cópia dos dados pessoais, e que faz a anonimização não ter de vir
 * cá limpar nada (limita-se a apagar estas linhas).
 *
 * O REENVIO RECONSTRÓI O EMAIL A PARTIR DO PEDIDO. Nada aqui é lido para
 * compor a mensagem: se o fosse, esta tabela teria de guardar conteúdo, que é
 * exactamente o que recusa.
 *
 * @property int $id
 * @property string $ulid
 * @property int $support_request_id
 * @property SupportNotificationType $notification_type
 * @property SupportRecipientRole $recipient_role
 * @property int $attempts
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $last_failed_at
 * @property Carbon|null $delivered_at
 * @property SupportDeliveryFailureCode|null $failure_code
 */
#[Fillable([
    'support_request_id', 'notification_type', 'recipient_role',
    'attempts', 'last_attempt_at', 'last_failed_at', 'delivered_at', 'failure_code',
])]
class SupportNotificationDelivery extends Model
{
    use HasUlids;

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'notification_type' => SupportNotificationType::class,
            'recipient_role' => SupportRecipientRole::class,
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failure_code' => SupportDeliveryFailureCode::class,
        ];
    }

    /**
     * @return BelongsTo<SupportRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(SupportRequest::class, 'support_request_id');
    }

    public function hasFailed(): bool
    {
        return $this->delivered_at === null && $this->failure_code !== null;
    }

    /**
     * As entregas que estão à espera de uma pessoa: falharam e ninguém as
     * reenviou com sucesso.
     *
     * @param  Builder<SupportNotificationDelivery>  $query
     * @return Builder<SupportNotificationDelivery>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('delivered_at')->whereNotNull('failure_code');
    }
}
