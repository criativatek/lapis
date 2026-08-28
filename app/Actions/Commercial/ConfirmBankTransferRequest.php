<?php

namespace App\Actions\Commercial;

use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\Commercial\CheckoutUnavailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * O dinheiro chegou: fecha o pedido e regista o pagamento verdadeiro.
 *
 * DUAS LINHAS, NÃO UMA ALTERADA. Um `SubscriptionPayment` é imutável excepto no
 * estado — `paid_at` não se escreve depois da criação, e uma linha `paid` sem
 * essa data violaria a restrição da base de dados. Por isso o pedido pendente
 * é **anulado com razão** e o pagamento recebido é **registado de novo**, que é
 * exactamente o mecanismo que o `CorrectSubscriptionPayment` documenta para
 * qualquer correcção: duas linhas e um rasto, em vez de uma linha que mudou de
 * sentido a meio.
 *
 * O VALOR É O QUE ENTROU, não o que foi pedido. Quem confirma vê o extrato: se
 * o cliente transferiu 44,90 € quando o pedido dizia 29,90 €, regista-se 44,90 €.
 * O pedido era uma intenção; só isto é receita.
 *
 * NÃO MUDA O PLANO, e é deliberado — a regra é do upstream e está certa:
 * aprovisionar e cobrar são factos independentes, e juntá-los aqui seria o
 * princípio de «conta Pro» passar a implicar «pagou». Quem confirma activa o
 * plano a seguir, no ecrã da conta, com esse acto registado em separado.
 */
class ConfirmBankTransferRequest
{
    public function __construct(
        protected RecordSubscriptionPayment $records,
        protected CorrectSubscriptionPayment $corrections,
    ) {}

    /**
     * @throws CheckoutUnavailable
     */
    public function confirm(
        SubscriptionPayment $request,
        Organization $organization,
        User $operator,
        int $amountCents,
        Carbon $paidAt,
    ): SubscriptionPayment {
        if ($request->status !== PaymentStatus::Pending) {
            throw new CheckoutUnavailable(__('Só um pedido pendente pode ser confirmado. Este está :status.', [
                'status' => mb_strtolower($request->status->label()),
            ]));
        }

        if ($request->provider !== RequestBankTransferPayment::PROVIDER) {
            throw new CheckoutUnavailable(__('Este registo não é um pedido de transferência bancária.'));
        }

        return DB::transaction(function () use ($request, $organization, $operator, $amountCents, $paidAt): SubscriptionPayment {
            $referencia = (string) $request->provider_reference;

            $this->corrections->void($request, $organization, $operator, __(
                'Substituído pelo pagamento recebido (referência :reference).',
                ['reference' => $referencia],
            ));

            return $this->records->record(
                organization: $organization,
                operator: $operator,
                amountCents: $amountCents,
                status: PaymentStatus::Paid,
                paidAt: $paidAt,
                currency: $request->currency,
                method: PaymentMethod::BankTransfer,
                providerReference: $referencia,
                // A condição que foi MOSTRADA ao cliente segue como proposta; o
                // `record()` copia-a, e quem confirma pode corrigi-la depois no
                // ecrã da condição comercial se não for essa.
                commercialCondition: $request->commercial_condition,
                periodStartsAt: $paidAt,
                periodEndsAt: $paidAt->copy()->addYear(),
                metadata: ['confirmed_request_ulid' => $request->ulid],
            );
        });
    }
}
