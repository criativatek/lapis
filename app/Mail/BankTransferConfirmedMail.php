<?php

namespace App\Mail;

use App\Models\SubscriptionPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * «Recebemos o seu pagamento.»
 *
 * PORQUE É QUE ESTE EMAIL TEM DE EXISTIR. Quem transfere fica sem saber de
 * nada: a transferência não gera recibo do nosso lado e o plano não muda no
 * momento em que o dinheiro entra. Sem esta mensagem, o silêncio entre
 * transferir e ver o plano mudado é indistinguível de o pagamento se ter
 * perdido — e a primeira coisa que uma pessoa faz nesse silêncio é escrever a
 * perguntar, ou transferir outra vez.
 *
 * NÃO PROMETE O QUE NÃO CONTROLA. Diz que o pagamento entrou e que a conta vai
 * ser mudada; não diz «a sua conta é agora Pro», porque activar é um acto à
 * parte e pode acontecer minutos depois.
 */
class BankTransferConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SubscriptionPayment $payment,
        public string $planName,
    ) {}

    public function build(): self
    {
        return $this->subject(__('Recebemos o seu pagamento — :plan', ['plan' => $this->planName]))
            ->view('emails.bank-transfer-confirmed', [
                'planName' => $this->planName,
                'amount' => number_format($this->payment->amount_cents / 100, 2, ',', ' ').' '
                    .($this->payment->currency === 'EUR' ? '€' : $this->payment->currency),
                'reference' => $this->payment->provider_reference,
                'paidAt' => $this->payment->paid_at,
                'periodEndsAt' => $this->payment->period_ends_at,
            ]);
    }
}
