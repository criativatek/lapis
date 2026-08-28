<?php

namespace App\Mail;

use App\Models\SubscriptionPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * As instruções de pagamento, por email.
 *
 * O ECRÃ NÃO CHEGA. Quem transfere fá-lo no homebanking, muitas vezes noutro
 * dispositivo e horas depois — e a referência tem de ir junto, senão a
 * transferência chega sem se saber de quem é. Este email é a cópia que fica na
 * caixa de entrada quando o separador já foi fechado.
 *
 * Não leva dados de alunos. Leva o que é preciso para pagar e a referência que
 * liga o extrato ao pedido.
 */
class BankTransferInstructionsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SubscriptionPayment $payment,
        public string $planName,
    ) {}

    public function build(): self
    {
        $expira = $this->payment->metadata['expires_at'] ?? null;

        return $this->subject(__('Dados para pagamento — :plan', ['plan' => $this->planName]))
            ->view('emails.bank-transfer-instructions', [
                'planName' => $this->planName,
                'amount' => number_format($this->payment->amount_cents / 100, 2, ',', ' ').' '
                    .($this->payment->currency === 'EUR' ? '€' : $this->payment->currency),
                'reference' => $this->payment->provider_reference,
                'beneficiary' => config('billing.bank_transfer.beneficiary'),
                'iban' => config('billing.bank_transfer.iban'),
                'bic' => config('billing.bank_transfer.bic'),
                'expiresAt' => $expira === null ? null : Carbon::parse((string) $expira),
            ]);
    }
}
