<?php

namespace Tests\Feature\Commercial;

use App\Actions\Commercial\RedeemVoucher;
use App\Actions\Commercial\ReleaseVoucherRedemption;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBenefitType;
use App\Models\VoucherRedemption;
use App\Support\Commercial\VoucherOutcome;
use App\Support\Commercial\Vouchers;
use App\Support\Commercial\VoucherUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O motor de vouchers, peça a peça: o código, o veredicto, a aritmética, o
 * ciclo de vida do resgate e as suas duas imutabilidades.
 *
 * A linha condutora: **a capacidade é derivada das linhas vivas, nunca de um
 * contador**, e a idempotência de uma organização vem ANTES da capacidade — um
 * duplo clique no último lugar recebe a própria reserva de volta, nunca um
 * «esgotado» por um lugar que a própria organização detém.
 */
class VoucherEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.prices.pro' => 4490, 'billing.currency' => 'EUR']);
        $this->travelTo(Carbon::parse('2026-06-01 10:00'));
    }

    /** @return array{User, Organization} */
    protected function owner(): array
    {
        $user = User::factory()->create();

        return [$user, $user->personalOrganization()];
    }

    protected function pro(): Plan
    {
        return Plan::where('key', 'pro')->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    protected function percentVoucher(array $overrides = []): Voucher
    {
        return Voucher::create(array_merge([
            'code' => 'LPRO-TEST-30PC',
            'label' => 'Campanha de teste',
            'benefit_type' => VoucherBenefitType::PercentDiscount,
            'benefit_percent' => 30,
        ], $overrides));
    }

    protected function reserveFor(Voucher $voucher, Organization $organization, User $user): VoucherRedemption
    {
        return app(RedeemVoucher::class)->reserve(
            $voucher, $organization, $user, $this->pro(), 4490, Carbon::now()->addDays(10),
        );
    }

    // ------------------------------------------------------------ o veredicto

    #[Test]
    public function the_resolver_finds_a_code_no_matter_how_it_is_written(): void
    {
        $this->percentVoucher();

        foreach (['LPRO-TEST-30PC', 'lpro test 30pc', 'LPROTEST30PC', ' lpro-test-30pc '] as $spelling) {
            $this->assertTrue(
                app(Vouchers::class)->resolve($spelling)->isValid(),
                "«{$spelling}» devia encontrar o voucher",
            );
        }
    }

    #[Test]
    public function every_refusal_answers_its_own_case(): void
    {
        [, $organization] = $this->owner();
        $resolver = app(Vouchers::class);

        $this->assertSame(VoucherOutcome::Malformed, $resolver->resolve('!!')->outcome);
        $this->assertSame(VoucherOutcome::NotFound, $resolver->resolve('LPRO-NADA-AQUI')->outcome);

        $this->percentVoucher(['code' => 'CEDO', 'valid_from' => Carbon::now()->addDay()]);
        $this->assertSame(VoucherOutcome::NotStarted, $resolver->resolve('CEDO')->outcome);

        $this->percentVoucher(['code' => 'TARDE', 'valid_until' => Carbon::now()->subDay()]);
        $this->assertSame(VoucherOutcome::Expired, $resolver->resolve('TARDE')->outcome);

        $desligado = $this->percentVoucher(['code' => 'MORTO']);
        $desligado->disabled_at = Carbon::now();
        $desligado->save();
        $this->assertSame(VoucherOutcome::Disabled, $resolver->resolve('MORTO')->outcome);

        // Restringido ao Base, pedido para o Pro: o plano-alvo vem do fluxo, e
        // o voucher apenas o recusa — nunca o escolhe.
        $this->percentVoucher(['code' => 'SOBASE', 'plan_id' => Plan::where('key', 'base')->firstOrFail()->getKey()]);
        $this->assertSame(
            VoucherOutcome::WrongPlan,
            $resolver->resolve('SOBASE', $organization, $this->pro())->outcome,
        );
    }

    #[Test]
    public function the_public_categories_flatten_what_must_not_be_enumerable(): void
    {
        $this->assertSame('valid', VoucherOutcome::Valid->publicCategory());
        $this->assertSame('expired', VoucherOutcome::Expired->publicCategory());
        $this->assertSame('expired', VoucherOutcome::Exhausted->publicCategory());

        // «Não existe» e «foi desactivado» têm de ser indistinguíveis cá fora.
        $this->assertSame('invalid', VoucherOutcome::NotFound->publicCategory());
        $this->assertSame('invalid', VoucherOutcome::Disabled->publicCategory());
        $this->assertSame('invalid', VoucherOutcome::Malformed->publicCategory());
    }

    // ------------------------------------------------------------ a aritmética

    #[Test]
    public function the_arithmetic_is_written_once_and_never_goes_negative(): void
    {
        $resolver = app(Vouchers::class);

        $fixo = $this->percentVoucher([
            'code' => 'FIXO', 'benefit_type' => VoucherBenefitType::FixedPrice,
            'benefit_percent' => null, 'benefit_amount_cents' => 1990, 'benefit_currency' => 'EUR',
        ]);
        $this->assertSame(1990, $resolver->resultPriceFor($fixo, 4490));

        // 30 % de 44,90 € → 31,43 € (arredondamento a decidir UMA vez, aqui).
        $this->assertSame(3143, $resolver->resultPriceFor($this->percentVoucher(['code' => 'PC30']), 4490));

        // 100 % é exactamente zero — nunca negativo.
        $cem = $this->percentVoucher(['code' => 'PC100', 'benefit_percent' => 100]);
        $this->assertSame(0, $resolver->resultPriceFor($cem, 4490));
    }

    // ------------------------------------- idempotência ANTES da capacidade

    #[Test]
    public function a_double_submit_on_the_last_slot_gets_its_own_reservation_back_not_exhausted(): void
    {
        [$user, $organization] = $this->owner();
        $voucher = $this->percentVoucher(['max_redemptions' => 1]);

        $primeira = $this->reserveFor($voucher, $organization, $user);

        // O último lugar está tomado — POR ESTA organização. O segundo clique
        // tem de receber a mesma linha, nunca `Exhausted`.
        $segunda = $this->reserveFor($voucher, $organization, $user);

        $this->assertTrue($primeira->is($segunda));
        $this->assertSame(1, VoucherRedemption::query()->count());
    }

    #[Test]
    public function a_confirmed_redemption_answers_already_redeemed_not_exhausted(): void
    {
        [$user, $organization] = $this->owner();
        $voucher = $this->percentVoucher(['max_redemptions' => 1]);

        $redemption = $this->reserveFor($voucher, $organization, $user);
        app(RedeemVoucher::class)->confirm($redemption, $user);

        try {
            $this->reserveFor($voucher, $organization, $user);
            $this->fail('Um código já resgatado por esta organização foi aceite outra vez.');
        } catch (VoucherUnavailable $exception) {
            $this->assertSame(VoucherOutcome::AlreadyRedeemed, $exception->outcome);
        }
    }

    #[Test]
    public function a_second_organization_hits_the_capacity_wall(): void
    {
        [$userA, $organizationA] = $this->owner();
        [$userB, $organizationB] = $this->owner();
        $voucher = $this->percentVoucher(['max_redemptions' => 1]);

        $this->reserveFor($voucher, $organizationA, $userA);

        try {
            $this->reserveFor($voucher, $organizationB, $userB);
            $this->fail('O segundo resgate passou por um teto de 1.');
        } catch (VoucherUnavailable $exception) {
            $this->assertSame(VoucherOutcome::Exhausted, $exception->outcome);
        }
    }

    // ------------------------------------------------- release, retry, limpeza

    #[Test]
    public function an_expired_reservation_returns_capacity_and_the_organization_may_retry(): void
    {
        [$userA, $organizationA] = $this->owner();
        [$userB, $organizationB] = $this->owner();
        $voucher = $this->percentVoucher(['max_redemptions' => 1, 'valid_until' => Carbon::now()->addYear()]);

        $this->reserveFor($voucher, $organizationA, $userA);

        // A janela da reserva passa. Ninguém a limpou ainda.
        $this->travel(11)->days();

        // A organização B resgata: a limpeza oportunista sob lock apaga a
        // reserva morta de A e a capacidade volta.
        $deB = $this->reserveFor($voucher, $organizationB, $userB);
        $this->assertSame($organizationB->getKey(), $deB->organization_id);
        $this->assertSame(1, VoucherRedemption::query()->count());

        // E o RETRY de A também funciona quando há espaço: novo voucher, mesma
        // mecânica — a linha morta saiu, o par (voucher, organização) volta a caber.
        $outro = $this->percentVoucher(['code' => 'RETRY', 'max_redemptions' => 1, 'valid_until' => Carbon::now()->addYear()]);
        $this->reserveFor($outro, $organizationA, $userA);
        $this->travel(11)->days();
        $deNovo = $this->reserveFor($outro, $organizationA, $userA);
        $this->assertFalse($deNovo->isConfirmed());
        $this->assertTrue($deNovo->isHolding());
    }

    #[Test]
    public function releasing_is_only_for_reservations_and_confirmations_are_immutable(): void
    {
        [$user, $organization] = $this->owner();
        $voucher = $this->percentVoucher();

        $redemption = $this->reserveFor($voucher, $organization, $user);
        app(RedeemVoucher::class)->confirm($redemption, $user);
        $confirmada = $redemption->fresh();

        // 1. Libertar uma confirmada: recusado.
        try {
            app(ReleaseVoucherRedemption::class)->release($confirmada, $organization, 'engano', $user);
            $this->fail('Uma confirmação foi libertada.');
        } catch (LogicException) {
        }

        // 2. Apagar uma confirmada: recusado pelo modelo.
        try {
            $confirmada->delete();
            $this->fail('Uma confirmação foi apagada.');
        } catch (LogicException) {
        }

        // 3. Reescrever o que ela provou: recusado.
        try {
            $confirmada->result_price_cents = 1;
            $confirmada->save();
            $this->fail('O resultado de uma confirmação foi reescrito.');
        } catch (LogicException) {
        }

        // 4. Confirmar outra vez: idempotente, e a data não anda.
        $antes = $confirmada->fresh()->confirmed_at;
        $this->travel(1)->day();
        app(RedeemVoucher::class)->confirm($confirmada->fresh(), $user);
        $this->assertTrue($antes->equalTo($confirmada->fresh()->confirmed_at));
    }

    // --------------------------------------------------- o voucher em si

    #[Test]
    public function an_issued_voucher_is_immutable_except_for_its_disabled_state(): void
    {
        $voucher = $this->percentVoucher();

        try {
            $voucher->benefit_percent = 90;
            $voucher->save();
            $this->fail('O benefício de um voucher emitido foi alterado.');
        } catch (LogicException) {
        }

        // Desactivar é a única correcção, e passa.
        $voucher->refresh();
        $voucher->disabled_at = Carbon::now();
        $voucher->save();
        $this->assertTrue($voucher->fresh()->isDisabled());
    }

    #[Test]
    public function an_incoherent_benefit_never_becomes_a_row(): void
    {
        foreach ([
            // percent_discount sem percentagem
            ['benefit_type' => VoucherBenefitType::PercentDiscount, 'benefit_percent' => null],
            // fixed_price sem moeda… coberto pelo próprio create abaixo
            ['benefit_type' => VoucherBenefitType::FixedPrice, 'benefit_amount_cents' => 1990, 'benefit_percent' => null],
            // free_until sem data
            ['benefit_type' => VoucherBenefitType::FreeUntil, 'benefit_percent' => null],
            // família certa com campo de outra família
            ['benefit_type' => VoucherBenefitType::PercentDiscount, 'benefit_percent' => 30, 'benefit_amount_cents' => 100],
        ] as $index => $broken) {
            try {
                Voucher::create(array_merge([
                    'code' => 'QUEBRADO'.$index, 'label' => 'x',
                ], $broken));
                $this->fail('Um benefício incoerente virou linha: '.json_encode($broken));
            } catch (LogicException) {
            }
        }

        $this->assertSame(0, Voucher::query()->count());
    }

    #[Test]
    public function remaining_redemptions_is_derived_from_live_rows_never_stored(): void
    {
        [$userA, $organizationA] = $this->owner();
        [$userB, $organizationB] = $this->owner();
        $voucher = $this->percentVoucher(['max_redemptions' => 2, 'valid_until' => Carbon::now()->addYear()]);

        $this->assertSame(2, $voucher->remainingRedemptions());

        $this->reserveFor($voucher, $organizationA, $userA);
        $this->assertSame(1, $voucher->fresh()->remainingRedemptions());

        $deB = $this->reserveFor($voucher, $organizationB, $userB);
        app(RedeemVoucher::class)->confirm($deB, $userB);
        $this->assertSame(0, $voucher->fresh()->remainingRedemptions());

        // A reserva de A caduca: a capacidade VOLTA na leitura, sem limpeza
        // nenhuma — a contagem só olha para linhas vivas.
        $this->travel(11)->days();
        $this->assertSame(1, $voucher->fresh()->remainingRedemptions());
    }
}
