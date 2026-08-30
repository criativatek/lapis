<?php

namespace App\Support\Support;

use App\Models\SupportDeliveryFailureCode;
use App\Models\SupportNotificationDelivery;
use App\Models\SupportNotificationType;
use App\Models\SupportRecipientRole;
use App\Models\SupportRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * ENVIAR O AVISO E REGISTAR O QUE ACONTECEU — o único sítio que o faz.
 *
 * DEPOIS DO COMMIT, SEMPRE. Os chamadores envolvem isto em
 * `DB::afterCommit()`: o pedido é gravado primeiro, e só depois se tenta o
 * email. Uma falha de SMTP **nunca** desfaz um pedido — o pedido existe, e o
 * que falhou foi o aviso.
 *
 * SÍNCRONO, E ISSO É O DESENHO. Em produção `QUEUE_CONNECTION=database` e não
 * há processo nenhum a consumir a tabela `jobs`: o crontab tem `schedule:run` e
 * o backup, mais nada. Um mailable `ShouldQueue` seria escrito na base de dados
 * e nunca enviado, sem que nada se queixasse — exactamente o modo de falha que
 * o ADR-0011 §5 recusa.
 *
 * O REGISTO NÃO GUARDA CONTEÚDO. Uma linha de `support_notification_deliveries`
 * diz que tipo de aviso era, para que PAPEL ia, quantas tentativas houve e como
 * falhou — de um vocabulário fechado. Nunca o corpo, nunca o assunto, nunca o
 * endereço, nunca a mensagem da excepção: `SupportDeliveryFailureCode` classifica
 * a partir da classe e do código SMTP, sem ler o texto.
 *
 * O EMAIL É RECONSTRUÍDO DO PEDIDO, sempre — no primeiro envio e no reenvio
 * manual. Se fosse reconstruído desta tabela, ela teria de guardar conteúdo,
 * que é o que recusa.
 */
class SupportNotifier
{
    /**
     * Tenta entregar um aviso, e devolve a linha de estado.
     *
     * IDEMPOTENTE NO SUCESSO: um aviso já entregue não é reenviado por engano —
     * só o reenvio explícito do backoffice (`force`) volta a tentar. É isso que
     * impede um `support:retention` a correr duas vezes no mesmo dia de mandar
     * dois lembretes à mesma pessoa.
     */
    public function send(SupportRequest $request, SupportNotificationType $type, bool $force = false): SupportNotificationDelivery
    {
        $role = $type->recipientRole();

        /** @var SupportNotificationDelivery $delivery */
        $delivery = SupportNotificationDelivery::query()->firstOrCreate(
            [
                'support_request_id' => $request->getKey(),
                'notification_type' => $type,
                'recipient_role' => $role,
            ],
            ['attempts' => 0],
        );

        if ($delivery->delivered_at !== null && ! $force) {
            return $delivery;
        }

        $recipient = $this->recipientFor($request, $role);

        if ($recipient === null) {
            // Um pedido anonimizado já não tem para onde escrever. Não é uma
            // falha de transporte, e por isso não é marcado como tal.
            return $delivery;
        }

        $delivery->attempts++;
        $delivery->last_attempt_at = Carbon::now();

        try {
            Mail::to($recipient)->send(SupportMailFactory::for($request, $type));

            $delivery->delivered_at = Carbon::now();
            $delivery->last_failed_at = null;
            $delivery->failure_code = null;
        } catch (Throwable $exception) {
            $delivery->delivered_at = null;
            $delivery->last_failed_at = Carbon::now();
            // A CLASSE e o código SMTP. Nunca a mensagem — ver o enum.
            $delivery->failure_code = SupportDeliveryFailureCode::fromThrowable($exception);

            // O log técnico fica com o detalhe completo; a base de dados fica
            // com o facto. `report()` não relança: o pedido sobrevive.
            report($exception);
        }

        $delivery->save();

        return $delivery;
    }

    /**
     * O endereço, derivado do PAPEL.
     *
     * Nunca lido da tabela de entregas, que deliberadamente não o guarda. NULL
     * quando o pedido já foi anonimizado — não há a quem escrever, e inventar
     * um destinatário seria pior do que não enviar.
     */
    protected function recipientFor(SupportRequest $request, SupportRecipientRole $role): ?string
    {
        return match ($role) {
            SupportRecipientRole::SupportTeam => (string) config('lapis.support.inbox'),
            SupportRecipientRole::Requester => $request->requester_email,
        };
    }
}
