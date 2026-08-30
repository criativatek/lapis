<?php

namespace Tests\Feature\Billing;

use App\Actions\Commercial\ConfirmBankTransferRequest;
use App\Actions\Commercial\RequestBankTransferPayment;
use App\Models\CommercialCondition;
use App\Models\Organization;
use App\Models\PaymentStatus;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\Commercial\CheckoutUnavailable;
use App\Support\Commercial\FounderAvailability;
use App\Support\Commercial\FounderSeats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O QUE ACONTECE À REFERÊNCIA QUANDO A RESERVA CAI.
 *
 * O buraco que este ficheiro fecha: um pedido de fundador guardava a
 * referência e o preço de 29,90 €, e nada voltava a perguntar se o lugar
 * continuava lá. Quem voltasse ao checkout no 20.º dia recebia de volta a mesma
 * referência ao preço de fundador — sem lugar por trás — transferia o dinheiro,
 * e só na confirmação, dias depois, é que alguém descobria que a condição já
 * não existia. Com o dinheiro na conta.
 *
 * A REGRA: **o preço de fundador só continua válido enquanto existir um
 * `FounderSeat` a sustentá-lo**, ao mesmo preço e na mesma moeda. O contrato
 * fica determinado ANTES de o comprador transferir, e não depois. «Quem
 * confirma decide» deixou de ser resposta.
 *
 * Três desfechos, e este ficheiro fixa os três:
 *
 *  1. O lugar aguenta-se → mesma referência, mesma condição. Nada muda para
 *     quem compra, que continua a ser o caso normal.
 *  2. A reserva caiu mas ainda há lugar → toma-se outro e a referência
 *     mantém-se coerente.
 *  3. Não há lugar → o pedido antigo é ANULADO com o motivo registado e emitido
 *     um novo com a condição em vigor. Uma referência a um preço que já ninguém
 *     pode honrar não sobrevive a este ponto.
 */
class FounderReservationLapseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config([
            'billing.bank_transfer.enabled' => true,
            'billing.bank_transfer.iban' => 'PT50000000000000000000000',
            'billing.bank_transfer.window_days' => 14,
            'billing.prices.pro' => 4490,
            'billing.founder.price_cents' => 2990,
            'billing.founder.seats' => 250,
            'billing.founder.deadline' => '2026-12-31',
        ]);

        $this->travelTo(Carbon::parse('2026-06-01 10:00'));
    }

    // ------------------------------------------------------ 1. aguenta-se

    #[Test]
    public function a_live_reservation_keeps_the_same_reference_and_the_same_price(): void
    {
        [$user, $organization] = $this->owner();

        $primeiro = $this->checkout($user);

        $this->travelTo(Carbon::parse('2026-06-08 10:00'));

        $segundo = $this->checkout($user);

        $this->assertSame($primeiro->provider_reference, $segundo->provider_reference);
        $this->assertSame(2990, $segundo->amount_cents);
        $this->assertSame(CommercialCondition::Founder, $segundo->commercial_condition);
        $this->assertSame(1, $this->paymentsOf($organization)->count(), 'não se emite uma segunda referência para a mesma compra');
    }

    // ------------------------------- 2. reserva cai, ainda há lugar

    #[Test]
    public function a_lapsed_reservation_is_recovered_while_seats_remain(): void
    {
        // O caso em que a janela do PEDIDO ainda está de pé mas o LUGAR caiu —
        // um operador libertou-o, ou a reserva venceu primeiro.
        [$user, $organization] = $this->owner();

        $primeiro = $this->checkout($user);
        $this->assertNotNull(app(FounderSeats::class)->seatOf($organization));

        app(FounderSeats::class)->release($organization, 'Libertado à mão durante o teste.');
        $this->assertNull(app(FounderSeats::class)->seatOf($organization));

        $segundo = $this->checkout($user);

        // Recuperado: lugar novo, mesma referência, condição coerente.
        $seat = app(FounderSeats::class)->seatOf($organization);
        $this->assertNotNull($seat, 'havia lugares e nenhum foi tomado');
        $this->assertSame(2990, $seat->price_cents);
        $this->assertSame($primeiro->provider_reference, $segundo->provider_reference);
        $this->assertSame(CommercialCondition::Founder, $segundo->commercial_condition);
        $this->assertSame(PaymentStatus::Pending, $segundo->fresh()->status);
    }

    // ------------------------------------ 3. reserva cai, esgotou

    #[Test]
    public function a_lapsed_reservation_with_no_seats_left_never_reissues_the_founder_price(): void
    {
        config(['billing.founder.seats' => 1]);

        [$user, $organization] = $this->owner();

        $antigo = $this->checkout($user);
        $this->assertSame(2990, $antigo->amount_cents);
        $this->assertSame(1, app(FounderSeats::class)->seatOf($organization)?->seat_number);

        // O lugar sai — e entretanto outra pessoa leva o único que havia.
        app(FounderSeats::class)->release($organization, 'Conta de teste.');
        [$outro] = $this->owner();
        $this->checkout($outro);
        $this->assertSame(0, app(FounderAvailability::class)->remaining());

        $novo = $this->checkout($user);

        // O pedido antigo foi ANULADO, com motivo, e não ficou pendente a
        // prometer 29,90 €.
        $antigo = $antigo->fresh();
        $this->assertSame(PaymentStatus::Cancelled, $antigo->status);
        $this->assertStringContainsString('deixou de estar disponível', (string) $antigo->status_reason);

        // E o novo pedido tem a condição que vale hoje: preço de tabela.
        $this->assertNotSame($antigo->provider_reference, $novo->provider_reference);
        $this->assertSame(4490, $novo->amount_cents);
        $this->assertSame(CommercialCondition::Standard, $novo->commercial_condition);
        $this->assertNull(app(FounderSeats::class)->seatOf($organization));

        // Exactamente um pedido pendente: o novo.
        $pendentes = $this->paymentsOf($organization)->where('status', PaymentStatus::Pending);
        $this->assertCount(1, $pendentes);
        $this->assertSame($novo->provider_reference, $pendentes->first()->provider_reference);
    }

    #[Test]
    public function the_screen_stops_showing_a_reference_the_seat_no_longer_backs(): void
    {
        config(['billing.founder.seats' => 1]);

        [$user, $organization] = $this->owner();
        $this->checkout($user);

        app(FounderSeats::class)->release($organization, 'Conta de teste.');
        [$outro] = $this->owner();
        $this->checkout($outro);

        // O GET não pode continuar a pôr a referência antiga à frente de quem
        // compra: já não representa nada que possa ser honrado. E não muta —
        // quem anula é o POST.
        $this->actingAs($user)->get('/settings/plan/checkout')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pendingReference', null)
                ->where('isFounderPrice', false)
                ->where('price', '44,90 €'));

        $this->assertSame(PaymentStatus::Pending, $this->paymentsOf($organization)->first()->status);
    }

    // ---------------------------------- a janela de 14 dias, aplicada

    #[Test]
    public function an_expired_transfer_window_reissues_even_when_a_seat_is_available(): void
    {
        // `metadata.expires_at` sempre existiu e nunca ninguém o lia: um pedido
        // de há dois meses reaparecia como se fosse de ontem. Passada a janela,
        // a referência que o comprador tem na mão já não vale — e tomar um
        // lugar novo não a ressuscita.
        [$user, $organization] = $this->owner();

        $antigo = $this->checkout($user);

        $this->travelTo(Carbon::parse('2026-07-01 10:00'));

        $novo = $this->checkout($user);

        $this->assertSame(PaymentStatus::Cancelled, $antigo->fresh()->status);
        $this->assertNotSame($antigo->provider_reference, $novo->provider_reference);
        $this->assertSame(PaymentStatus::Pending, $novo->fresh()->status);

        // Ainda há lugares, por isso a condição nova continua a ser Fundador —
        // o que caducou foi a referência, não a promoção.
        $this->assertSame(CommercialCondition::Founder, $novo->commercial_condition);
        $this->assertSame(2990, $novo->amount_cents);
    }

    // ------------------------ 4. a confirmação honra o que foi acordado

    #[Test]
    public function confirming_honours_the_condition_that_was_valid_when_the_buyer_committed(): void
    {
        [$user, $organization] = $this->owner();
        $operator = $this->admin();

        $pedido = $this->checkout($user);

        // A condição fecha para toda a gente depois de este comprador se ter
        // comprometido — prazo ultrapassado. O lugar dele já está tomado e
        // continua a valer: foi tomado enquanto a condição estava aberta.
        $this->travelTo(Carbon::parse('2026-06-10 10:00'));

        $pago = app(ConfirmBankTransferRequest::class)->confirm(
            $pedido,
            $organization,
            $operator,
            $pedido->amount_cents,
            Carbon::now(),
        );

        $this->assertSame(2990, $pago->amount_cents);
        $this->assertSame(CommercialCondition::Founder, $pago->commercial_condition);

        $seat = app(FounderSeats::class)->seatOf($organization);
        $this->assertTrue($seat?->isConfirmed());
        $this->assertNull($seat?->reserved_until, 'um lugar pago não expira');

        // E depois disso o tempo passar não desfaz nada.
        $this->travelTo(Carbon::parse('2027-06-01 10:00'));
        $this->assertTrue(app(FounderSeats::class)->seatOf($organization)?->isConfirmed());
    }

    #[Test]
    public function a_cancelled_request_can_never_become_a_founder_contract_afterwards(): void
    {
        config(['billing.founder.seats' => 1]);

        [$user, $organization] = $this->owner();
        $operator = $this->admin();

        $antigo = $this->checkout($user);

        app(FounderSeats::class)->release($organization, 'Conta de teste.');
        [$outro] = $this->owner();
        $this->checkout($outro);

        // Reemitido a preço de tabela; o antigo ficou anulado.
        $this->checkout($user);

        // A referência antiga já não se confirma: não há por onde ela se
        // transformar num contrato de fundador sem lugar válido.
        $this->expectException(CheckoutUnavailable::class);

        app(ConfirmBankTransferRequest::class)->confirm(
            $antigo->fresh(),
            $organization,
            $operator,
            2990,
            Carbon::now(),
        );
    }

    // ------------------------------------------------------------ fixtures

    /** @return array{User, Organization} */
    private function owner(): array
    {
        $user = User::factory()->create();

        return [$user, $user->personalOrganization()];
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    private function checkout(User $user): SubscriptionPayment
    {
        return app(RequestBankTransferPayment::class)->request(
            $user->personalOrganization(),
            $user,
            Plan::where('key', 'pro')->firstOrFail(),
            [
                'name' => 'Escola Teste',
                'tax_number' => null,
                'address_line1' => 'Rua Um, 1',
                'address_line2' => null,
                'postal_code' => '2400-000',
                'city' => 'Leiria',
                'country' => 'PT',
                'email' => 'faturacao@exemplo.pt',
            ],
        );
    }

    /** @return Collection<int, SubscriptionPayment> */
    private function paymentsOf(Organization $organization)
    {
        return SubscriptionPayment::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->orderBy('id')
            ->get();
    }
}
