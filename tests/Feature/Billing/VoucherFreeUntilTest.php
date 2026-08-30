<?php

namespace Tests\Feature\Billing;

use App\Models\BillingPeriod;
use App\Models\CommercialCondition;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBenefitType;
use App\Models\VoucherRedemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O voucher `free_until`, resgatado na página do plano: nasce confirmado, muda
 * o plano pelo mecanismo normal, e congela `Voucher / 0 / EUR / none /
 * free_until` no snapshot — sem tocar em versões de plano nem em módulos.
 *
 * E O PLANO-ALVO É DO PEDIDO, NUNCA DO CÓDIGO: `plan_key` viaja explícito, o
 * servidor valida-o, e o `plan_id` do voucher apenas o recusa quando não
 * coincide. Nada aqui está hardcoded ao Pro.
 */
class VoucherFreeUntilTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.currency' => 'EUR', 'billing.prices.pro' => 4490]);
        $this->travelTo(Carbon::parse('2026-06-01 10:00'));
    }

    /** @return array{User, Organization} */
    protected function owner(): array
    {
        $user = User::factory()->create();

        return [$user, $user->personalOrganization()];
    }

    /** @param array<string, mixed> $overrides */
    protected function freeUntilVoucher(array $overrides = []): Voucher
    {
        return Voucher::create(array_merge([
            'code' => 'LPRO-GRAT-2027',
            'label' => 'Piloto gratuito',
            'benefit_type' => VoucherBenefitType::FreeUntil,
            'benefit_free_until' => Carbon::parse('2027-08-31')->endOfDay(),
        ], $overrides));
    }

    protected function redeem(User $user, string $code, string $planKey = 'pro'): TestResponse
    {
        return $this->actingAs($user)->post('/settings/plan/voucher', [
            'voucher_code' => $code,
            'plan_key' => $planKey,
        ]);
    }

    #[Test]
    public function redeeming_grants_the_target_plan_with_the_term_frozen_in_the_snapshot(): void
    {
        [$user, $organization] = $this->owner();
        $this->freeUntilVoucher();

        $versionsBefore = PlanVersion::query()->count();

        $this->redeem($user, 'lpro grat 2027')->assertRedirect('/settings/plan');

        $subscription = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('pro', $subscription->plan->key);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(CommercialCondition::Voucher, $subscription->commercial_condition);
        // Zero CONTRATADO, não «preço por registar»: acordou-se que não custa nada.
        $this->assertSame(0, $subscription->contracted_price_cents);
        $this->assertSame('EUR', $subscription->contracted_currency);
        $this->assertSame(BillingPeriod::None, $subscription->billing_period);
        $this->assertSame('2027-08-31', $subscription->commercial_term_ends_at->toDateString());
        // O termo é COMERCIAL: `ends_at` fica NULL — o acesso não caduca em agosto.
        $this->assertNull($subscription->ends_at);

        // A subscrição contrata a v1 EXISTENTE: nenhuma versão nasceu disto.
        $this->assertSame(1, $subscription->planVersion->version);
        $this->assertSame($versionsBefore, PlanVersion::query()->count());

        // O resgate nasce confirmado — não há dinheiro a esperar — e aponta
        // para o contrato que materializou.
        $redemption = VoucherRedemption::query()->sole();
        $this->assertTrue($redemption->isConfirmed());
        $this->assertSame($subscription->getKey(), $redemption->organization_subscription_id);
        $this->assertSame(0, $redemption->result_price_cents);
        $this->assertSame('2027-08-31', $redemption->result_term_ends_at->toDateString());
    }

    #[Test]
    public function the_target_plan_comes_from_the_request_and_the_voucher_only_restricts_it(): void
    {
        [$user] = $this->owner();
        // Restringido ao PRO. Pedi-lo para o Base tem de ser recusado — é o
        // voucher a restringir o alvo, nunca a escolhê-lo.
        $this->freeUntilVoucher(['plan_id' => Plan::where('key', 'pro')->firstOrFail()->getKey()]);

        $this->redeem($user, 'LPRO-GRAT-2027', 'base')->assertSessionHasErrors('voucher_code');
        $this->assertSame(0, VoucherRedemption::query()->count());

        // Para o alvo certo, passa.
        $this->redeem($user, 'LPRO-GRAT-2027', 'pro')->assertRedirect('/settings/plan');
        $this->assertSame(1, VoucherRedemption::query()->count());
    }

    #[Test]
    public function an_unrestricted_voucher_serves_whatever_purchasable_target_the_request_names(): void
    {
        [$user, $organization] = $this->owner();
        // plan_id NULL: serve o alvo que o contexto pedir — aqui, o Base.
        $this->freeUntilVoucher();

        $this->redeem($user, 'LPRO-GRAT-2027', 'base')->assertRedirect('/settings/plan');

        $subscription = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('base', $subscription->plan->key);
        $this->assertSame(CommercialCondition::Voucher, $subscription->commercial_condition);
    }

    #[Test]
    public function the_institutional_plan_stays_out_with_or_without_a_code(): void
    {
        [$user] = $this->owner();
        $this->freeUntilVoucher();

        $this->redeem($user, 'LPRO-GRAT-2027', 'institutional')->assertSessionHasErrors('voucher_code');
        $this->assertSame(0, VoucherRedemption::query()->count());
    }

    #[Test]
    public function a_priced_code_is_refused_here_and_pointed_at_the_checkout(): void
    {
        [$user] = $this->owner();
        Voucher::create([
            'code' => 'LPRO-DESC-30PC',
            'label' => 'Desconto',
            'benefit_type' => VoucherBenefitType::PercentDiscount,
            'benefit_percent' => 30,
        ]);

        $this->redeem($user, 'LPRO-DESC-30PC')->assertSessionHasErrors('voucher_code');
        $this->assertSame(0, VoucherRedemption::query()->count());
    }

    #[Test]
    public function only_the_owner_may_redeem_for_the_organization(): void
    {
        [, $organization] = $this->owner();
        $this->freeUntilVoucher();

        // Outro utilizador, outra organização — a dele, não a alheia. O gate
        // `subscribe` é o mesmo do checkout.
        $intruso = User::factory()->create();
        $membro = $intruso->personalOrganization(); // garante organização própria

        $this->assertNotSame($organization->getKey(), $membro->getKey());
        // O resgate acontece SEMPRE na organização corrente do próprio — nunca
        // na de terceiros: não há forma de nomear outra organização no pedido.
        $this->redeem($intruso, 'LPRO-GRAT-2027')->assertRedirect('/settings/plan');

        $redemption = VoucherRedemption::query()->sole();
        $this->assertSame($membro->getKey(), $redemption->organization_id);
    }

    #[Test]
    public function each_organization_redeems_a_code_once_ever(): void
    {
        [$user] = $this->owner();
        $this->freeUntilVoucher();

        $this->redeem($user, 'LPRO-GRAT-2027')->assertRedirect('/settings/plan');
        $this->redeem($user, 'LPRO-GRAT-2027')->assertSessionHasErrors('voucher_code');

        $this->assertSame(1, VoucherRedemption::query()->count());
    }
}
