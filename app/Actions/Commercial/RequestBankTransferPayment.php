<?php

namespace App\Actions\Commercial;

use App\Models\BillingProfile;
use App\Models\CommercialCondition;
use App\Models\FounderSeat;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Audit\AuditLog;
use App\Support\Commercial\BankTransferReference;
use App\Support\Commercial\CheckoutUnavailable;
use App\Support\Commercial\FounderAvailability;
use App\Support\Commercial\FounderSeats;
use App\Support\Commercial\Vouchers;
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
 * mostrado ao cliente quando a condição estava aberta; pagar 29,90 € continua a
 * não fazer de ninguém fundador, como o `RecordSubscriptionPayment` insiste.
 *
 * MAS A OFERTA CUSTA UM LUGAR. Até aqui, «é fundador?» era
 * `FounderAvailability::isOpen()` a ler uma tabela que ninguém enchia: cada
 * comprador via «restam 250 lugares» e recebia o preço de fundador, para
 * sempre, quantos fossem. Agora o pedido **toma um lugar** (`FounderSeats`) e é
 * o lugar — com o seu número e o seu preço congelado — que decide a condição e
 * a quantia deste pedido. Se não houver lugar, o checkout continua ao preço de
 * tabela em vez de prometer o que já não existe.
 *
 * E O CONTRATO FICA DETERMINADO ANTES DE O DINHEIRO SAIR. Um pedido pendente
 * não é eterno: caduca com a janela de transferência, e um pedido de fundador
 * caduca também com o lugar que o sustenta. Quem voltasse ao checkout no 20.º
 * dia recebia de volta a mesma referência a 29,90 € sem lugar nenhum por trás,
 * transferia, e só na confirmação é que alguém descobriria — com o dinheiro já
 * na conta. `revalidate()` decide antes: reafirma o lugar se ainda o houver,
 * anula o pedido e emite outro se não houver. «Quem confirma decide» não é
 * resposta quando o comprador já transferiu.
 */
class RequestBankTransferPayment
{
    public function __construct(
        protected AuditLog $audit,
        protected CurrentOrganization $currentOrganization,
        protected FounderAvailability $founder,
        protected FounderSeats $seats,
        protected BankTransferReference $references,
        // Para ANULAR um pedido que deixou de poder ser honrado. O mesmo
        // mecanismo que o `ConfirmBankTransferRequest` usa, e pela mesma razão:
        // um `SubscriptionPayment` não muda de sentido a meio — anula-se com um
        // motivo registado e emite-se outro.
        protected CorrectSubscriptionPayment $corrections,
        protected Vouchers $vouchers,
        protected RedeemVoucher $redemptions,
        protected ReleaseVoucherRedemption $releases,
    ) {}

    public const PROVIDER = 'bank_transfer';

    /**
     * @param  array{name: string, tax_number: ?string, address_line1: string, address_line2: ?string, postal_code: string, city: string, country: string, email: string}  $billing
     * @param  Voucher|null  $voucher  um código com preço, já resolvido como válido pelo chamador — a decisão final é tomada aqui, sob a transação
     *
     * @throws CheckoutUnavailable
     */
    public function request(Organization $organization, User $buyer, Plan $plan, array $billing, ?Voucher $voucher = null): SubscriptionPayment
    {
        $this->assertAvailable();

        // Chamado antes da transação apenas para RECUSAR o plano: o Base e o
        // Institucional não têm preço em `config/billing.php` e é isso que os
        // mantém fora do checkout. A quantia efectiva deste pedido é decidida
        // lá dentro, a partir do lugar — se houver.
        $listPrice = $this->listPriceFor($plan);

        return DB::transaction(function () use ($organization, $buyer, $plan, $billing, $listPrice, $voucher): SubscriptionPayment {
            $profile = $this->storeBillingProfile($organization, $billing);

            // Um segundo clique não gera uma segunda referência: quem voltar ao
            // checkout actualiza os dados de faturação e recebe de volta o
            // mesmo pedido. Duas referências para a mesma compra é a forma mais
            // rápida de ninguém saber o que foi pago.
            $existing = $this->pendingFor($organization);

            if ($existing !== null) {
                // A CONDIÇÃO TEM DE CONTINUAR A VALER, e não basta a referência
                // continuar a existir. Um pedido de fundador cuja reserva
                // expirou é uma referência a 29,90 € sem lugar por trás: quem a
                // apanhasse transferia o preço de fundador para um contrato que
                // já ninguém podia honrar. `revalidate()` decide antes de o
                // dinheiro sair — reafirma o lugar se ainda o houver, e anula o
                // pedido se não houver.
                $valido = $this->revalidate($existing, $organization, $buyer);

                if ($valido !== null) {
                    return $valido;
                }

                // Caiu: o pedido antigo foi anulado ali dentro e segue-se para
                // baixo, para emitir um novo com a condição que vale HOJE.
            }

            // UM VOUCHER É UM BENEFÍCIO, E SÓ SE CONSOME SE BENEFICIAR. A
            // decisão acontece ANTES de tomar um lugar de fundador, porque as
            // duas condições não acumulam e um lugar tomado por um contrato que
            // vai ser de voucher seria um lugar roubado a quem o pagaria:
            //
            //  - o voucher aplica-se sempre sobre o preço de TABELA;
            //  - a oferta normal é o preço de fundador enquanto a condição
            //    estiver aberta, e o de tabela depois;
            //  - ganha o preço mais baixo; NO EMPATE ganha a oferta normal, que
            //    não consome o código — o cliente paga o mesmo e fica com o
            //    voucher na mão.
            $voucherPrice = $voucher === null ? null : $this->vouchers->resultPriceFor($voucher, $listPrice);
            $useVoucher = $voucherPrice !== null
                && $voucherPrice < ($this->founder->isOpen() ? $this->founder->priceCents() : $listPrice);

            // O LUGAR DECIDE, E NÃO A CONFIG. Se houver lugar, este pedido leva
            // o número e o preço que o lugar congelou; se não houver, leva o
            // preço de tabela e a condição normal. Era exactamente aqui que a
            // promessa se desfazia: `isOpen()` dizia «sim» a toda a gente.
            //
            // Um contrato que vai ser de voucher NUNCA chega ao `claim()`: é a
            // exclusão estrutural entre as duas condições, no fluxo e não num
            // `if` tardio.
            $seat = $useVoucher ? null : $this->seats->claim($organization, null, $buyer);

            // A corrida honesta: a oferta de fundador fechou entre o `isOpen()`
            // e o `claim()` — o último lugar foi de outra pessoa. A comparação
            // volta a fazer-se contra o que sobrou, que é o preço de tabela; se
            // o voucher agora beneficiar, é ele que vale. Continua a nunca
            // haver lugar E voucher no mesmo pedido: aqui, `$seat` é null.
            if (! $useVoucher && $seat === null && $voucherPrice !== null && $voucherPrice < $listPrice) {
                $useVoucher = true;
            }

            // A reserva do resgate, com a MESMA janela do pedido: quando um
            // caduca, caduca o outro, e `revalidate()` trata os dois pelo mesmo
            // padrão que os lugares de fundador estabeleceram.
            $redemption = null;

            if ($useVoucher && $voucher !== null) {
                $redemption = $this->redemptions->reserve(
                    $voucher,
                    $organization,
                    $buyer,
                    $plan,
                    $listPrice,
                    Carbon::now()->addDays((int) config('billing.bank_transfer.window_days')),
                );
            }

            // Escrito como uma verificação explícita e não como `?->x ?? y`: à
            // esquerda de `??` o operador nullsafe é redundante — `??` já tem
            // semântica de isset — e um `?->` redundante lê-se como se
            // estivesse a fazer alguma coisa. A mesma disciplina que o
            // `SubscriptionCondition` já documenta.
            $cents = $seat === null ? $listPrice : $seat->price_cents;
            $currency = $seat === null ? (string) config('billing.currency') : $seat->currency;

            if ($redemption !== null) {
                $cents = (int) $redemption->result_price_cents;
                $currency = (string) $redemption->result_currency;
            }

            $payment = SubscriptionPayment::withoutGlobalScope('organization')->create([
                'organization_id' => $organization->getKey(),
                // Deliberadamente sem subscrição: a que está a ser comprada
                // ainda não existe, e prender isto à actual diria que o
                // pagamento é do plano antigo.
                'organization_subscription_id' => null,
                'amount_cents' => $cents,
                'currency' => $currency,
                'status' => PaymentStatus::Pending,
                'method' => PaymentMethod::BankTransfer,
                'provider' => self::PROVIDER,
                'provider_reference' => $this->references->generate(),
                'commercial_condition' => match (true) {
                    $redemption !== null => CommercialCondition::Voucher,
                    $seat !== null => CommercialCondition::Founder,
                    default => CommercialCondition::Standard,
                },
                // O CÓDIGO VALIDADO fica também na coluna de texto histórica —
                // agora com um resgate do motor por trás, que é o que o
                // backoffice distingue de um texto avulso.
                'voucher_code' => $redemption?->voucher()->value('code'),
                'recorded_by' => null,
                'metadata' => [
                    'plan_key' => $plan->key,
                    'requested_by_user_id' => $buyer->getKey(),
                    'billing_profile_id' => $profile->getKey(),
                    'expires_at' => Carbon::now()->addDays((int) config('billing.bank_transfer.window_days'))->toDateTimeString(),
                    // O ordinal prometido, guardado com o pedido que o tomou.
                    'founder_seat_number' => $seat?->seat_number,
                    'voucher_redemption_ulid' => $redemption?->ulid,
                ],
            ]);

            // Fecha o círculo entre o lugar e o pedido que o reservou. Feito
            // depois porque o lugar tem de existir ANTES de se saber o preço, e
            // o pagamento tem de existir antes de se poder apontar para ele.
            $seat?->forceFill(['subscription_payment_id' => $payment->getKey()])->save();

            // O mesmo círculo para o resgate: a reserva aponta para o pedido
            // que a segura, e é por este fio que `stillHolds()` e a confirmação
            // a reencontram.
            $redemption?->forceFill(['subscription_payment_id' => $payment->getKey()])->save();

            $this->currentOrganization->runFor($organization, fn () => $this->audit->record(
                'commercial.payment_requested',
                $organization,
                $buyer,
                summary: sprintf(
                    'Pedido de pagamento por transferência: %s %s, referência %s.',
                    number_format($cents / 100, 2, ',', ' '),
                    $currency,
                    $payment->provider_reference,
                ),
                properties: [
                    'payment_ulid' => $payment->ulid,
                    'plan_key' => $plan->key,
                    'amount_cents' => $cents,
                    'reference' => $payment->provider_reference,
                    'commercial_condition' => $payment->commercial_condition?->value,
                    'founder_seat_number' => $seat?->seat_number,
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
     * O pedido pendente que AINDA REPRESENTA UM CONTRATO VÁLIDO, para mostrar.
     *
     * Um pedido pendente não é eterno em duas dimensões, e o ecrã tem de as
     * respeitar às duas antes de voltar a pôr uma referência à frente de quem
     * compra:
     *
     *  - **A janela de transferência.** `metadata.expires_at` sempre existiu e
     *    nunca foi lido por ninguém: um pedido de há dois meses reaparecia como
     *    se fosse de ontem.
     *  - **O lugar de fundador.** Uma referência a 29,90 € só vale enquanto
     *    houver um `FounderSeat` a segurá-la, ao mesmo preço e na mesma moeda.
     *    Sem isso é o preço de uma condição que já não existe.
     *
     * SÓ LÊ. É chamado pelo ecrã de checkout (GET), que não pode ter efeitos —
     * quem anula e reemite é `request()`, no POST, e só quando o comprador
     * volta mesmo a submeter.
     */
    public function validPendingFor(Organization $organization): ?SubscriptionPayment
    {
        $pending = $this->pendingFor($organization);

        return $pending !== null && $this->stillHolds($pending, $organization) ? $pending : null;
    }

    /**
     * O pedido continua a valer? — a mesma pergunta para o ecrã e para o POST.
     *
     * Duas dimensões, e falha qualquer uma chega: a janela de transferência que
     * o comprador viu anunciada, e — só para um pedido de fundador — o lugar
     * que segura o preço.
     */
    protected function stillHolds(SubscriptionPayment $payment, Organization $organization): bool
    {
        if (! $this->withinTransferWindow($payment)) {
            return false;
        }

        if ($payment->commercial_condition === CommercialCondition::Voucher) {
            $redemption = $this->redemptionOf($payment);

            return $redemption !== null && $this->redemptionBacks($redemption, $payment);
        }

        if ($payment->commercial_condition !== CommercialCondition::Founder) {
            return true;
        }

        $seat = $this->seats->seatOf($organization);

        return $seat !== null && $this->seatBacks($seat, $payment);
    }

    /** A reserva de voucher que este pedido segura, se ainda existir. */
    protected function redemptionOf(SubscriptionPayment $payment): ?VoucherRedemption
    {
        return VoucherRedemption::query()
            ->where('subscription_payment_id', $payment->getKey())
            ->first();
    }

    /**
     * A reserva sustenta MESMO este pedido — a mesma pergunta de `seatBacks()`:
     * viva, ao mesmo preço e na mesma moeda. Se o que a reserva congelou já não
     * é o que o pedido pede, duas quantias diferentes para a mesma compra é
     * exactamente o que este domínio está escrito para impedir.
     */
    protected function redemptionBacks(VoucherRedemption $redemption, SubscriptionPayment $payment): bool
    {
        return $redemption->isHolding()
            && $redemption->result_price_cents === $payment->amount_cents
            && $redemption->result_currency === $payment->currency;
    }

    /**
     * Dentro da janela que o pedido anunciou a quem comprou.
     *
     * `metadata.expires_at` sempre existiu e nunca ninguém o leu: um pedido de
     * há dois meses reaparecia no ecrã como se fosse de ontem. Um pedido sem
     * data continua válido — é anterior a este campo, e inventar-lhe um prazo
     * retroactivo seria pior do que o não ter.
     */
    protected function withinTransferWindow(SubscriptionPayment $payment): bool
    {
        $expiresAt = $payment->metadata['expires_at'] ?? null;

        return ! is_string($expiresAt) || Carbon::parse($expiresAt)->isFuture();
    }

    /**
     * O lugar sustenta MESMO este pedido.
     *
     * Não basta existir um lugar: o preço e a moeda têm de bater certo. Se a
     * condição de fundador mudou de valor entre o pedido e agora, a referência
     * antiga pede uma quantia que o contrato que dela sairia já não teria — e
     * duas quantias diferentes para a mesma compra é exactamente o que este
     * domínio inteiro está escrito para impedir.
     */
    protected function seatBacks(FounderSeat $seat, SubscriptionPayment $payment): bool
    {
        return $seat->isHolding()
            && $seat->price_cents === $payment->amount_cents
            && $seat->currency === $payment->currency;
    }

    /**
     * Confirma que um pedido pendente ainda pode ser honrado, ou anula-o.
     *
     * O CONTRATO FICA DETERMINADO ANTES DE O DINHEIRO SAIR. É esta a regra que
     * faltava. Antes, um comprador que voltasse ao checkout depois de a reserva
     * expirar recebia de volta a mesma referência a 29,90 €, transferia, e só
     * ao confirmar é que alguém descobria que já não havia lugar — com o
     * dinheiro na conta e uma conversa desagradável pela frente.
     *
     * Três desfechos, e nenhum deles é «logo se vê»:
     *
     *  1. **O lugar aguenta-se** (ou nunca houve condição de fundador em jogo):
     *     devolve-se o mesmo pedido, com a mesma referência. É o caso normal, e
     *     continua a não gerar uma segunda referência para a mesma compra.
     *  2. **A reserva expirou e ainda há lugar**: toma-se um lugar novo. Se o
     *     preço bater certo com o do pedido, a referência antiga continua boa e
     *     nada muda para quem compra.
     *  3. **Não há lugar, ou o preço já não é o mesmo**: o pedido antigo é
     *     ANULADO, com o motivo registado, e devolve-se NULL para que o
     *     `request()` emita um novo com a condição que vale hoje. Uma
     *     referência a um preço que já ninguém pode honrar não pode
     *     sobreviver a este ponto.
     */
    protected function revalidate(SubscriptionPayment $payment, Organization $organization, User $buyer): ?SubscriptionPayment
    {
        if ($this->stillHolds($payment, $organization)) {
            return $payment;
        }

        // RECUPERAR SÓ DENTRO DA JANELA. Se o que caducou foi o prazo do
        // próprio pedido, tomar um lugar novo não o ressuscita — a referência
        // que o comprador tem na mão já não vale, e emite-se outra. Só se
        // recupera o caso inverso: a janela ainda de pé e o lugar perdido, que
        // é o que acontece quando um operador liberta um lugar ou a reserva
        // vence primeiro.
        if ($payment->commercial_condition === CommercialCondition::Founder && $this->withinTransferWindow($payment)) {
            // `claim()` é idempotente: devolve o que a organização já tenha, ou
            // toma o menor livre.
            $seat = $this->seats->claim($organization, $payment, $buyer);

            if ($seat !== null && $this->seatBacks($seat, $payment)) {
                return $payment;
            }
        }

        // Um pedido de voucher que caiu deixa a reserva para trás — e ela tem
        // de ser LIBERTADA, não abandonada: enquanto existir, consome
        // capacidade do código e bloqueia o retry desta organização. A
        // confirmada nunca chega aqui (o pedido dela já não está pendente).
        if ($payment->commercial_condition === CommercialCondition::Voucher) {
            $redemption = $this->redemptionOf($payment);

            if ($redemption !== null && ! $redemption->isConfirmed()) {
                $this->releases->release($redemption, $organization, __(
                    'O pedido de pagamento que a segurava caducou ou deixou de poder ser honrado.',
                ), $buyer);
            }
        }

        // O motivo fica no trilho, e quem o «causou» é quem voltou ao checkout:
        // a anulação é automática, mas não é anónima — foi este clique que a
        // desencadeou, e é o que um operador precisa de ver ao reconstruir o
        // que aconteceu à referência antiga.
        $this->corrections->void($payment, $organization, $buyer, __(
            'Pedido anulado automaticamente: a condição comercial que lhe deu origem deixou de estar disponível '
            .'antes de o pagamento ser confirmado. Foi emitida uma nova referência com a condição em vigor.',
        ));

        return null;
    }

    /**
     * O que se PEDIRIA a quem chegasse ao checkout agora, em cêntimos.
     *
     * PARA MOSTRAR, NÃO PARA CONTRATAR. Continua a responder o preço de
     * fundador enquanto a condição estiver aberta, porque é isso que o ecrã
     * tem de escrever — mas quem contrata leva o preço do LUGAR que tomou, que
     * é o que `request()` grava e o que fica congelado. Entre esta leitura e o
     * clique seguinte, o último lugar pode ter sido tomado por outra pessoa; é
     * o lugar que decide, e não este número.
     *
     * @throws CheckoutUnavailable
     */
    public function priceFor(Plan $plan): int
    {
        $tabelado = $this->listPriceFor($plan);

        return $this->founder->isOpen() ? $this->founder->priceCents() : $tabelado;
    }

    /**
     * O preço de tabela do plano, e a recusa dos que não se vendem online.
     *
     * O Base é gratuito e o Institucional é sob consulta. Nenhum tem preço em
     * `config/billing.php`, e é isso — e não uma lista de exclusões noutro
     * sítio a ficar desactualizada — que os mantém fora do checkout.
     *
     * @throws CheckoutUnavailable
     */
    protected function listPriceFor(Plan $plan): int
    {
        $tabelado = config('billing.prices.'.$plan->key);

        if ($tabelado === null) {
            throw new CheckoutUnavailable(__('O plano :plan não pode ser subscrito online.', ['plan' => $plan->name]));
        }

        return (int) $tabelado;
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
