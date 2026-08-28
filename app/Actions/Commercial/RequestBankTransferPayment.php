<?php

namespace App\Actions\Commercial;

use App\Models\BillingProfile;
use App\Models\CommercialCondition;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Commercial\BankTransferReference;
use App\Support\Commercial\CheckoutUnavailable;
use App\Support\Commercial\FounderAvailability;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * O cliente diz que vai transferir. Ninguém recebeu nada ainda.
 *
 * ISTO NÃO É `RecordSubscriptionPayment`, e a diferença é o ponto todo. Aquele
 * regista dinheiro que um operador confirmou ter recebido — é a única coisa de
 * que a receita é calculada. Este escreve uma INTENÇÃO declarada por quem
 * compra, em estado `pending`, e é por isso que:
 *
 *  - **`recorded_by` fica a `null`.** Nenhum operador registou nada. Pôr lá o
 *    cliente faria uma declaração de intenção passar por um registo de caixa.
 *  - **`provider` é `bank_transfer`.** A linha foi criada pela aplicação e não
 *    escrita à mão, que é exactamente a distinção para que o `record()` reserva
 *    esta coluna ao deixá-la nula.
 *  - **O plano não muda, e nada é aprovisionado.** Uma transferência não avisa
 *    ninguém quando chega; dar acesso agora seria dar acesso a quem clicou e
 *    não a quem pagou. A conta fica no plano que já tinha.
 *  - **`paid_at` fica a `null`, para sempre nesta linha.** O modelo recusa
 *    escrevê-lo depois da criação, de propósito. Quando o dinheiro entrar, o
 *    operador regista a linha verdadeira e anula esta — dois factos, duas
 *    linhas, em vez de uma linha que mudou de sentido a meio.
 *
 * A CONDIÇÃO FUNDADOR AQUI É UMA OFERTA, NÃO UM FACTO. Grava-se o que foi
 * mostrado ao cliente quando a condição estava aberta; quem confirma o dinheiro
 * é que decide o que fica registado — como o `RecordSubscriptionPayment` insiste,
 * pagar 29,90 € não faz de ninguém fundador.
 */
class RequestBankTransferPayment
{
    public function __construct(
        protected AuditLog $audit,
        protected CurrentOrganization $currentOrganization,
        protected FounderAvailability $founder,
        protected BankTransferReference $references,
    ) {}

    public const PROVIDER = 'bank_transfer';

    /**
     * @param  array{name: string, tax_number: ?string, address_line1: string, address_line2: ?string, postal_code: string, city: string, country: string, email: string}  $billing
     *
     * @throws CheckoutUnavailable
     */
    public function request(Organization $organization, User $buyer, Plan $plan, array $billing): SubscriptionPayment
    {
        $this->assertAvailable();

        $cents = $this->priceFor($plan);

        return DB::transaction(function () use ($organization, $buyer, $plan, $billing, $cents): SubscriptionPayment {
            $profile = $this->storeBillingProfile($organization, $billing);

            // Um segundo clique não gera uma segunda referência: quem voltar ao
            // checkout actualiza os dados de faturação e recebe de volta o
            // mesmo pedido. Duas referências para a mesma compra é a forma mais
            // rápida de ninguém saber o que foi pago.
            $existing = $this->pendingFor($organization);

            if ($existing !== null) {
                return $existing;
            }

            $payment = SubscriptionPayment::withoutGlobalScope('organization')->create([
                'organization_id' => $organization->getKey(),
                // Deliberadamente sem subscrição: a que está a ser comprada
                // ainda não existe, e prender isto à actual diria que o
                // pagamento é do plano antigo.
                'organization_subscription_id' => null,
                'amount_cents' => $cents,
                'currency' => (string) config('billing.currency'),
                'status' => PaymentStatus::Pending,
                'method' => PaymentMethod::BankTransfer,
                'provider' => self::PROVIDER,
                'provider_reference' => $this->references->generate(),
                'commercial_condition' => $this->founder->isOpen() ? CommercialCondition::Founder : CommercialCondition::Standard,
                'recorded_by' => null,
                'metadata' => [
                    'plan_key' => $plan->key,
                    'requested_by_user_id' => $buyer->getKey(),
                    'billing_profile_id' => $profile->getKey(),
                    'expires_at' => Carbon::now()->addDays((int) config('billing.bank_transfer.window_days'))->toDateTimeString(),
                ],
            ]);

            $this->currentOrganization->runFor($organization, fn () => $this->audit->record(
                'commercial.payment_requested',
                $organization,
                $buyer,
                summary: sprintf(
                    'Pedido de pagamento por transferência: %s %s, referência %s.',
                    number_format($cents / 100, 2, ',', ' '),
                    (string) config('billing.currency'),
                    $payment->provider_reference,
                ),
                properties: [
                    'payment_ulid' => $payment->ulid,
                    'plan_key' => $plan->key,
                    'amount_cents' => $cents,
                    'reference' => $payment->provider_reference,
                ],
            ));

            return $payment;
        });
    }

    /** O pedido por pagar que já exista para esta organização, se houver. */
    public function pendingFor(Organization $organization): ?SubscriptionPayment
    {
        return SubscriptionPayment::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->where('status', PaymentStatus::Pending)
            ->where('provider', self::PROVIDER)
            ->latest('id')
            ->first();
    }

    /**
     * O que este plano custa hoje, em cêntimos.
     *
     * @throws CheckoutUnavailable
     */
    public function priceFor(Plan $plan): int
    {
        $tabelado = config('billing.prices.'.$plan->key);

        if ($tabelado === null) {
            // O Base é gratuito e o Institucional é sob consulta. Nenhum tem
            // preço em `config/billing.php`, e é isso — e não uma lista de
            // exclusões — que os mantém fora do checkout.
            throw new CheckoutUnavailable(__('O plano :plan não pode ser subscrito online.', ['plan' => $plan->name]));
        }

        return $this->founder->isOpen() ? $this->founder->priceCents() : (int) $tabelado;
    }

    /** @throws CheckoutUnavailable */
    protected function assertAvailable(): void
    {
        if (! config('billing.bank_transfer.enabled')) {
            throw new CheckoutUnavailable(__('O pagamento por transferência bancária não está disponível de momento.'));
        }

        if (blank(config('billing.bank_transfer.iban'))) {
            // Um ecrã com o IBAN em branco é pior do que um erro: o cliente
            // julga que transferiu e ninguém recebe nada.
            throw new CheckoutUnavailable(__('Os dados bancários ainda não estão configurados. Contacte-nos para concluir a subscrição.'));
        }
    }

    /** @param array<string, mixed> $billing */
    protected function storeBillingProfile(Organization $organization, array $billing): BillingProfile
    {
        return $this->currentOrganization->runFor($organization, fn (): BillingProfile => BillingProfile::updateOrCreate(
            ['organization_id' => $organization->getKey()],
            $billing + ['organization_id' => $organization->getKey()],
        ));
    }
}
