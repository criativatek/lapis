<?php

namespace Tests\Feature\Billing;

use App\Mail\BankTransferConfirmedMail;
use App\Models\CommercialCondition;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O dinheiro entrou: o que acontece, e sobretudo o que NÃO acontece.
 *
 * Confirmar produz **duas linhas**, não uma alterada: o pedido é anulado com
 * razão e o pagamento verdadeiro é registado de novo. É a consequência directa
 * de um `SubscriptionPayment` ser imutável excepto no estado — `paid_at` não se
 * escreve depois da criação, e uma linha `paid` sem essa data cairia fora de
 * todos os totais por período enquanto contava para o total de sempre.
 *
 * E confirmar **não activa o plano**. Aprovisionar e cobrar são factos
 * independentes neste domínio; juntá-los seria o princípio de «conta Pro»
 * passar a implicar «pagou».
 */
class ConfirmBankTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config([
            'billing.bank_transfer.enabled' => true,
            'billing.bank_transfer.iban' => 'PT50000000000000000000000',
            'billing.prices.pro' => 4490,
            'billing.founder.price_cents' => 2990,
            'billing.founder.seats' => 250,
            'billing.founder.deadline' => '2026-12-31',
        ]);

        $this->travelTo(Carbon::parse('2026-06-01 10:00'));
    }

    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    /** Uma conta com um pedido de transferência já aberto. */
    protected function accountWithRequest(): Organization
    {
        $buyer = User::factory()->create();
        $organization = $buyer->personalOrganization();

        $this->actingAs($buyer)->post('/settings/plan/checkout', [
            'name' => 'Escola de Alvalade',
            'tax_number' => '501234567',
            'address_line1' => 'Rua das Escolas, 12',
            'postal_code' => '1700-001',
            'city' => 'Lisboa',
            'country' => 'PT',
            'email' => 'faturacao@exemplo.pt',
        ])->assertSessionHasNoErrors();

        return $organization->fresh();
    }

    /** @return Collection<int, SubscriptionPayment> */
    protected function paymentsOf(Organization $organization)
    {
        return SubscriptionPayment::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->orderBy('id')
            ->get();
    }

    protected function requestOf(Organization $organization): SubscriptionPayment
    {
        return $this->paymentsOf($organization)->firstOrFail();
    }

    #[Test]
    public function confirming_cancels_the_request_and_records_the_real_payment(): void
    {
        $organization = $this->accountWithRequest();
        $pedido = $this->requestOf($organization);

        $this->actingAs($this->admin())
            ->post('/admin/commercial/payments/'.$pedido->ulid.'/confirm', [
                'amount' => '29.90',
                'paid_at' => '2026-06-03',
            ])->assertRedirect();

        $pagamentos = $this->paymentsOf($organization);

        $this->assertCount(2, $pagamentos);

        $this->assertSame(PaymentStatus::Cancelled, $pagamentos[0]->status);
        $this->assertNotNull($pagamentos[0]->status_reason);

        $recebido = $pagamentos[1];
        $this->assertSame(PaymentStatus::Paid, $recebido->status);
        $this->assertSame(2990, $recebido->amount_cents);
        $this->assertSame('2026-06-03', $recebido->paid_at->toDateString());
        $this->assertSame(PaymentMethod::BankTransfer, $recebido->method);

        // A mesma referência nas duas linhas: é o que liga o extrato ao pedido.
        $this->assertSame($pedido->provider_reference, $recebido->provider_reference);
    }

    /**
     * O valor é o do extrato, não o do pedido. Quem confirma está a olhar para
     * o banco; se lá estiver outro número, é esse que é receita.
     */
    #[Test]
    public function the_amount_recorded_is_the_one_that_actually_arrived(): void
    {
        $organization = $this->accountWithRequest();
        $pedido = $this->requestOf($organization);

        $this->assertSame(2990, $pedido->amount_cents);

        $this->actingAs($this->admin())
            ->post('/admin/commercial/payments/'.$pedido->ulid.'/confirm', [
                'amount' => '44.90',
                'paid_at' => '2026-06-03',
            ]);

        $this->assertSame(4490, $this->paymentsOf($organization)->last()->amount_cents);
    }

    #[Test]
    public function confirming_does_not_activate_the_plan(): void
    {
        $organization = $this->accountWithRequest();

        $this->actingAs($this->admin())
            ->post('/admin/commercial/payments/'.$this->requestOf($organization)->ulid.'/confirm', [
                'amount' => '29.90',
                'paid_at' => '2026-06-03',
            ]);

        $emVigor = app(ChangeOrganizationPlan::class)->inForce($organization->fresh());

        $this->assertNotSame('pro', $emVigor?->plan?->key);
    }

    /** A condição que foi mostrada ao cliente segue para a linha registada. */
    #[Test]
    public function the_offered_condition_carries_over(): void
    {
        $organization = $this->accountWithRequest();

        $this->actingAs($this->admin())
            ->post('/admin/commercial/payments/'.$this->requestOf($organization)->ulid.'/confirm', [
                'amount' => '29.90',
                'paid_at' => '2026-06-03',
            ]);

        $this->assertSame(CommercialCondition::Founder, $this->paymentsOf($organization)->last()->commercial_condition);
    }

    #[Test]
    public function the_period_is_a_year_from_the_day_the_money_arrived(): void
    {
        $organization = $this->accountWithRequest();

        $this->actingAs($this->admin())
            ->post('/admin/commercial/payments/'.$this->requestOf($organization)->ulid.'/confirm', [
                'amount' => '29.90',
                'paid_at' => '2026-06-03',
            ]);

        $recebido = $this->paymentsOf($organization)->last();

        $this->assertSame('2026-06-03', $recebido->period_starts_at->toDateString());
        $this->assertSame('2027-06-03', $recebido->period_ends_at->toDateString());
    }

    /** Confirmar duas vezes cobraria duas — e é a segunda que tem de falhar. */
    #[Test]
    public function the_same_request_cannot_be_confirmed_twice(): void
    {
        $organization = $this->accountWithRequest();
        $pedido = $this->requestOf($organization);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/commercial/payments/'.$pedido->ulid.'/confirm', [
            'amount' => '29.90', 'paid_at' => '2026-06-03',
        ]);

        $this->actingAs($admin)->post('/admin/commercial/payments/'.$pedido->ulid.'/confirm', [
            'amount' => '29.90', 'paid_at' => '2026-06-03',
        ])->assertSessionHasErrors('amount');

        $this->assertCount(2, $this->paymentsOf($organization));
    }

    /**
     * O silêncio entre transferir e ver o plano mudado é indistinguível de o
     * pagamento se ter perdido — e a primeira coisa que uma pessoa faz nesse
     * silêncio é transferir outra vez.
     */
    #[Test]
    public function the_customer_is_told_the_money_arrived(): void
    {
        $organization = $this->accountWithRequest();

        $this->actingAs($this->admin())
            ->post('/admin/commercial/payments/'.$this->requestOf($organization)->ulid.'/confirm', [
                'amount' => '29.90',
                'paid_at' => '2026-06-03',
            ]);

        Mail::assertSent(
            BankTransferConfirmedMail::class,
            fn ($mail) => $mail->hasTo('faturacao@exemplo.pt'),
        );
    }

    /** Um pedido que nunca chega a ser confirmado não avisa ninguém de nada. */
    #[Test]
    public function nothing_is_sent_while_the_request_is_still_pending(): void
    {
        $this->accountWithRequest();

        Mail::assertNotSent(BankTransferConfirmedMail::class);
    }

    #[Test]
    public function a_date_is_required_because_revenue_is_counted_by_period(): void
    {
        $organization = $this->accountWithRequest();

        $this->actingAs($this->admin())
            ->post('/admin/commercial/payments/'.$this->requestOf($organization)->ulid.'/confirm', [
                'amount' => '29.90',
            ])->assertSessionHasErrors('paid_at');

        $this->assertCount(1, $this->paymentsOf($organization));
    }

    #[Test]
    public function only_a_platform_admin_may_confirm(): void
    {
        $organization = $this->accountWithRequest();
        $pedido = $this->requestOf($organization);

        $this->actingAs(User::factory()->create())
            ->post('/admin/commercial/payments/'.$pedido->ulid.'/confirm', [
                'amount' => '29.90', 'paid_at' => '2026-06-03',
            ])->assertForbidden();

        $this->assertCount(1, $this->paymentsOf($organization));
    }

    /** Só um pedido do checkout se confirma assim. */
    #[Test]
    public function a_hand_written_pending_payment_is_not_a_transfer_request(): void
    {
        $organization = User::factory()->create()->personalOrganization();
        $admin = $this->admin();

        $mao = SubscriptionPayment::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'amount_cents' => 4490,
            'currency' => 'EUR',
            'status' => PaymentStatus::Pending,
            'provider' => null,
            'recorded_by' => $admin->getKey(),
        ]);

        $this->actingAs($admin)->post('/admin/commercial/payments/'.$mao->ulid.'/confirm', [
            'amount' => '44.90', 'paid_at' => '2026-06-03',
        ])->assertSessionHasErrors('amount');
    }
}
