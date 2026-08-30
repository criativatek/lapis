<?php

namespace Tests\Feature\Billing;

use App\Models\CommercialCondition;
use App\Models\FounderSeat;
use App\Models\Organization;
use App\Models\PaymentStatus;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBenefitType;
use App\Models\VoucherRedemption;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Commercial\CommercialTerms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O voucher no checkout: a escolha da condição mais favorável, a exclusão
 * estrutural com o lugar de fundador, e a reserva presa à janela do pedido.
 *
 * A linha condutora: **um voucher é um benefício, e só se consome se
 * beneficiar**. Ganha o preço mais baixo; no empate ganha a oferta normal, que
 * deixa o código na mão do cliente; e nunca — em caminho nenhum — o mesmo
 * pedido leva lugar E resgate.
 */
class VoucherCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.bank_transfer.enabled' => true,
            'billing.bank_transfer.iban' => 'PT50000000000000000000000',
            'billing.bank_transfer.beneficiary' => 'Criativatek',
            'billing.bank_transfer.window_days' => 10,
            'billing.prices.pro' => 4490,
            'billing.currency' => 'EUR',
            'billing.founder.price_cents' => 2990,
            'billing.founder.seats' => 250,
            'billing.founder.deadline' => '2026-12-31',
        ]);

        $this->travelTo(Carbon::parse('2026-06-01 10:00'));
    }

    /** @return array{User, Organization} */
    protected function owner(): array
    {
        $user = User::factory()->create();

        return [$user, $user->personalOrganization()];
    }

    /** @return array<string, string> */
    protected function billingData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Escola de Alvalade',
            'tax_number' => '501234567',
            'address_line1' => 'Rua das Escolas, 12',
            'postal_code' => '1700-001',
            'city' => 'Lisboa',
            'country' => 'PT',
            'email' => 'faturacao@exemplo.pt',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    protected function voucher(array $overrides = []): Voucher
    {
        return Voucher::create(array_merge([
            'code' => 'LPRO-BOAS-2026',
            'label' => 'Campanha de teste',
            'benefit_type' => VoucherBenefitType::PercentDiscount,
            'benefit_percent' => 50,
        ], $overrides));
    }

    protected function paymentOf(Organization $organization): ?SubscriptionPayment
    {
        return SubscriptionPayment::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->where('status', PaymentStatus::Pending)
            ->latest('id')
            ->first();
    }

    protected function checkout(User $user, array $extra = []): TestResponse
    {
        return $this->actingAs($user)->post('/settings/plan/checkout', $this->billingData($extra));
    }

    // -------------------------------------------- a escolha da melhor condição

    #[Test]
    public function a_voucher_that_beats_the_founder_price_wins_and_takes_no_seat(): void
    {
        [$user, $organization] = $this->owner();
        // 50 % de 44,90 € = 22,45 € < 29,90 € de fundador: o voucher ganha.
        $this->voucher();

        $this->checkout($user, ['voucher_code' => 'lpro boas 2026'])->assertRedirect();

        $payment = $this->paymentOf($organization);
        $this->assertSame(2245, $payment->amount_cents);
        $this->assertSame(CommercialCondition::Voucher, $payment->commercial_condition);
        $this->assertSame('LPRO-BOAS-2026', $payment->voucher_code);

        // NENHUM lugar tomado: a exclusão é estrutural.
        $this->assertSame(0, FounderSeat::query()->count());

        // E a reserva existe, viva, presa a este pedido.
        $redemption = VoucherRedemption::query()->sole();
        $this->assertTrue($redemption->isHolding());
        $this->assertSame($payment->getKey(), $redemption->subscription_payment_id);
        $this->assertSame(2245, $redemption->result_price_cents);
    }

    #[Test]
    public function the_founder_price_wins_over_a_weaker_voucher_and_the_code_is_not_consumed(): void
    {
        [$user, $organization] = $this->owner();
        // 10 % de 44,90 € = 40,41 € > 29,90 € de fundador: fundador ganha.
        $this->voucher(['benefit_percent' => 10]);

        $this->checkout($user, ['voucher_code' => 'LPRO-BOAS-2026'])->assertRedirect();

        $payment = $this->paymentOf($organization);
        $this->assertSame(2990, $payment->amount_cents);
        $this->assertSame(CommercialCondition::Founder, $payment->commercial_condition);
        $this->assertNull($payment->voucher_code);

        // O código fica NA MÃO do cliente: nenhum resgate, capacidade intacta.
        $this->assertSame(0, VoucherRedemption::query()->count());
        $this->assertSame(1, FounderSeat::query()->count());
    }

    #[Test]
    public function a_tie_prefers_the_condition_that_does_not_consume_the_voucher(): void
    {
        [$user, $organization] = $this->owner();
        // Preço fixo IGUAL ao de fundador: empate → fundador, código por usar.
        $this->voucher([
            'benefit_type' => VoucherBenefitType::FixedPrice,
            'benefit_percent' => null,
            'benefit_amount_cents' => 2990,
            'benefit_currency' => 'EUR',
        ]);

        $this->checkout($user, ['voucher_code' => 'LPRO-BOAS-2026'])->assertRedirect();

        $this->assertSame(CommercialCondition::Founder, $this->paymentOf($organization)->commercial_condition);
        $this->assertSame(0, VoucherRedemption::query()->count());
    }

    #[Test]
    public function a_fixed_price_at_or_above_the_normal_offer_is_never_consumed(): void
    {
        [$user, $organization] = $this->owner();
        // Fundador fechado: a oferta normal é o preço de tabela.
        config(['billing.founder.seats' => 0]);
        // «Benefício» de 50,00 € sobre uma tabela de 44,90 €: pioraria a oferta.
        $this->voucher([
            'benefit_type' => VoucherBenefitType::FixedPrice,
            'benefit_percent' => null,
            'benefit_amount_cents' => 5000,
            'benefit_currency' => 'EUR',
        ]);

        $this->checkout($user, ['voucher_code' => 'LPRO-BOAS-2026'])->assertRedirect();

        $payment = $this->paymentOf($organization);
        $this->assertSame(4490, $payment->amount_cents);
        $this->assertSame(CommercialCondition::Standard, $payment->commercial_condition);
        $this->assertSame(0, VoucherRedemption::query()->count());
    }

    // ------------------------------------------------------------- as recusas

    #[Test]
    public function an_unknown_or_dead_code_stops_the_checkout_before_any_reference_is_issued(): void
    {
        [$user, $organization] = $this->owner();

        $this->checkout($user, ['voucher_code' => 'LPRO-NADA-AQUI'])
            ->assertSessionHasErrors('voucher_code');

        $this->assertNull($this->paymentOf($organization));
        $this->assertSame(0, FounderSeat::query()->count());
    }

    #[Test]
    public function a_free_until_code_is_sent_to_the_plan_page_not_priced_here(): void
    {
        [$user, $organization] = $this->owner();
        $this->voucher([
            'benefit_type' => VoucherBenefitType::FreeUntil,
            'benefit_percent' => null,
            'benefit_free_until' => Carbon::parse('2027-08-31')->endOfDay(),
        ]);

        $this->checkout($user, ['voucher_code' => 'LPRO-BOAS-2026'])
            ->assertSessionHasErrors('voucher_code');

        $this->assertNull($this->paymentOf($organization));
        $this->assertSame(0, VoucherRedemption::query()->count());
    }

    // ------------------------------------ a reserva vive e morre com o pedido

    #[Test]
    public function when_the_request_lapses_the_reservation_is_released_and_a_new_request_reprices(): void
    {
        [$user, $organization] = $this->owner();
        $this->voucher(['max_redemptions' => 1, 'valid_until' => Carbon::parse('2026-06-05')]);

        $this->checkout($user, ['voucher_code' => 'LPRO-BOAS-2026'])->assertRedirect();
        $primeiro = $this->paymentOf($organization);
        $this->assertSame(2245, $primeiro->amount_cents);

        // A janela de 10 dias passa; o voucher, entretanto, também expirou.
        $this->travel(20)->days();

        // Voltar ao checkout SEM código: o pedido antigo é anulado, a reserva
        // libertada, e o novo pedido leva a condição que vale hoje.
        $this->checkout($user)->assertRedirect();

        $novo = $this->paymentOf($organization);
        $this->assertNotSame($primeiro->getKey(), $novo->getKey());
        $this->assertNotSame(CommercialCondition::Voucher, $novo->commercial_condition);

        // A reserva morreu com o pedido — a capacidade voltou ao código.
        $this->assertSame(0, VoucherRedemption::query()->count());
        $this->assertSame(PaymentStatus::Cancelled, $primeiro->fresh()->status);
    }

    #[Test]
    public function a_second_click_within_the_window_returns_the_same_reference_and_the_same_reservation(): void
    {
        [$user, $organization] = $this->owner();
        $this->voucher();

        $this->checkout($user, ['voucher_code' => 'LPRO-BOAS-2026'])->assertRedirect();
        $primeiro = $this->paymentOf($organization);

        $this->checkout($user, ['voucher_code' => 'LPRO-BOAS-2026'])->assertRedirect();

        $this->assertSame($primeiro->getKey(), $this->paymentOf($organization)->getKey());
        $this->assertSame(1, VoucherRedemption::query()->count());
    }

    // ------------------------------------- confirmação, activação e snapshot

    #[Test]
    public function confirming_the_transfer_makes_the_redemption_definitive_and_the_snapshot_says_voucher(): void
    {
        [$user, $organization] = $this->owner();
        $this->voucher();

        $this->checkout($user, ['voucher_code' => 'LPRO-BOAS-2026'])->assertRedirect();
        $pedido = $this->paymentOf($organization);

        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($admin)
            ->post('/admin/commercial/payments/'.$pedido->ulid.'/confirm', [
                'amount' => '22.45',
                'paid_at' => '2026-06-03',
            ])->assertRedirect();

        $redemption = VoucherRedemption::query()->sole();
        $this->assertTrue($redemption->isConfirmed());

        // O fio passou para o pagamento VERDADEIRO (paid), não o pedido anulado.
        $pago = SubscriptionPayment::withoutGlobalScope('organization')
            ->findOrFail($redemption->subscription_payment_id);
        $this->assertSame(PaymentStatus::Paid, $pago->status);

        // A activação (o acto separado do operador) escreve o snapshot a partir
        // da prova paga: condição voucher, preço do resgate.
        $pro = Plan::where('key', 'pro')->firstOrFail();
        $subscription = app(ChangeOrganizationPlan::class)->to(
            $organization,
            $pro,
            app(CommercialTerms::class)->fromPaidEvidence($organization, $pro->currentVersionOrFail()),
        );

        $this->assertSame(CommercialCondition::Voucher, $subscription->commercial_condition);
        $this->assertSame(2245, $subscription->contracted_price_cents);
        $this->assertSame('EUR', $subscription->contracted_currency);
    }
}
