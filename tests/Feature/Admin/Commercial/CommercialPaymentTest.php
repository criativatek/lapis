<?php

namespace Tests\Feature\Admin\Commercial;

use App\Models\AuditEvent;
use App\Models\CommercialCondition;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Commercial\CommercialMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Recording money, and the only two ways it may ever be undone.
 *
 * The through-line: a payment's figure and date are written once. "Corrigir"
 * means moving the status with a reason attached, never editing what was
 * recorded — so the history always says what really happened, and revenue stays
 * right without a single number being rewritten.
 */
class CommercialPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    protected function proAccount(): Organization
    {
        $organization = User::factory()->create()->personalOrganization();
        app(ChangeOrganizationPlan::class)->to($organization, Plan::where('key', 'pro')->firstOrFail());

        return $organization->fresh();
    }

    protected function paymentOf(Organization $organization): SubscriptionPayment
    {
        return SubscriptionPayment::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->latest('id')
            ->firstOrFail();
    }

    #[Test]
    public function an_operator_records_a_payment_that_was_actually_received(): void
    {
        $admin = $this->admin();
        $account = $this->proAccount();

        $this->actingAs($admin)->post("/admin/commercial/{$account->ulid}/payments", [
            'amount' => '44,90',
            'currency' => 'eur',
            'status' => 'paid',
            'paid_at' => '2026-08-15',
            'method' => 'bank_transfer',
            'provider_reference' => 'NIB 4821',
            'commercial_condition' => 'standard',
            'period_starts_at' => '2026-09-01',
            'period_ends_at' => '2027-08-31',
        ])->assertRedirect();

        $payment = $this->paymentOf($account);

        // Euros in, cents out — and 44,90 is 4490, not 4489.
        $this->assertSame(4490, $payment->amount_cents);
        $this->assertSame('EUR', $payment->currency);
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(PaymentMethod::BankTransfer, $payment->method);
        $this->assertSame(CommercialCondition::Standard, $payment->commercial_condition);
        $this->assertSame('2026-08-15', $payment->paid_at->toDateString());
        $this->assertSame($admin->getKey(), $payment->recorded_by);
        // Attached to the subscription it paid for, without being asked.
        $this->assertNotNull($payment->organization_subscription_id);
    }

    #[Test]
    public function recording_a_payment_does_not_touch_the_plan(): void
    {
        $account = $this->proAccount();
        $before = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $account->getKey())->get();

        $this->actingAs($this->admin())->post("/admin/commercial/{$account->ulid}/payments", [
            'amount' => '44,90', 'currency' => 'EUR', 'status' => 'paid', 'paid_at' => '2026-08-15',
        ])->assertRedirect();

        $after = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $account->getKey())->get();

        // Paying is not provisioning. No new subscription, nothing superseded.
        $this->assertSame($before->count(), $after->count());
        $this->assertEquals(
            $before->map->only(['plan_id', 'status', 'starts_at', 'ends_at'])->all(),
            $after->map->only(['plan_id', 'status', 'starts_at', 'ends_at'])->all(),
        );
    }

    #[Test]
    public function a_payment_marked_as_paid_must_carry_the_date_the_money_arrived(): void
    {
        $account = $this->proAccount();

        $this->actingAs($this->admin())->post("/admin/commercial/{$account->ulid}/payments", [
            'amount' => '44,90', 'currency' => 'EUR', 'status' => 'paid',
        ])->assertSessionHasErrors('paid_at');

        $this->assertSame(0, SubscriptionPayment::withoutGlobalScope('organization')->count());
    }

    #[Test]
    public function a_voucher_code_is_stored_as_written_and_never_resolved(): void
    {
        // There is no voucher backend. The code is a fact somebody typed, and
        // recording it must not imply that anything validated it.
        $account = $this->proAccount();

        $this->actingAs($this->admin())->post("/admin/commercial/{$account->ulid}/payments", [
            'amount' => '0,50', 'currency' => 'EUR', 'status' => 'paid', 'paid_at' => '2026-08-15',
            'commercial_condition' => 'voucher',
            'voucher_code' => 'LANCAMENTO-2026',
        ])->assertRedirect();

        $payment = $this->paymentOf($account);

        $this->assertSame('LANCAMENTO-2026', $payment->voucher_code);
        $this->assertSame(CommercialCondition::Voucher, $payment->commercial_condition);
    }

    #[Test]
    public function a_refund_requires_a_reason(): void
    {
        $account = $this->proAccount();
        $payment = SubscriptionPayment::withoutGlobalScope('organization')->create([
            'organization_id' => $account->getKey(),
            'amount_cents' => 4490, 'currency' => 'EUR',
            'status' => PaymentStatus::Paid, 'paid_at' => Carbon::now(),
        ]);

        $this->actingAs($this->admin())
            ->post("/admin/commercial/payments/{$payment->ulid}/refund", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    #[Test]
    public function a_refund_records_who_did_it_and_why(): void
    {
        $admin = $this->admin();
        $account = $this->proAccount();
        $payment = SubscriptionPayment::withoutGlobalScope('organization')->create([
            'organization_id' => $account->getKey(),
            'amount_cents' => 4490, 'currency' => 'EUR',
            'status' => PaymentStatus::Paid, 'paid_at' => Carbon::now(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/commercial/payments/{$payment->ulid}/refund", ['reason' => 'Desistiu no prazo legal.'])
            ->assertRedirect();

        $refunded = $payment->fresh();

        $this->assertSame(PaymentStatus::Refunded, $refunded->status);
        $this->assertSame('Desistiu no prazo legal.', $refunded->status_reason);
        $this->assertSame($admin->getKey(), $refunded->status_changed_by);
        $this->assertNotNull($refunded->status_changed_at);
        // The original figure and date are untouched.
        $this->assertSame(4490, $refunded->amount_cents);

        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $account->getKey(),
            'event' => 'commercial.payment_refunded',
            'causer_id' => $admin->getKey(),
        ]);
    }

    #[Test]
    public function a_payment_recorded_in_error_is_voided_rather_than_edited(): void
    {
        $admin = $this->admin();
        $account = $this->proAccount();

        // Typed with an extra zero.
        $this->actingAs($admin)->post("/admin/commercial/{$account->ulid}/payments", [
            'amount' => '449,00', 'currency' => 'EUR', 'status' => 'paid', 'paid_at' => '2026-08-15',
        ])->assertRedirect();

        $wrong = $this->paymentOf($account);

        $this->actingAs($admin)
            ->post("/admin/commercial/payments/{$wrong->ulid}/void", ['reason' => 'Valor errado: 449,00 em vez de 44,90.'])
            ->assertRedirect();

        // Then the right one is recorded alongside it.
        $this->actingAs($admin)->post("/admin/commercial/{$account->ulid}/payments", [
            'amount' => '44,90', 'currency' => 'EUR', 'status' => 'paid', 'paid_at' => '2026-08-15',
        ])->assertRedirect();

        $this->assertSame(PaymentStatus::Cancelled, $wrong->fresh()->status);
        // The wrong figure is still in the history, and still says 449,00.
        $this->assertSame(44900, $wrong->fresh()->amount_cents);
        $this->assertSame(2, SubscriptionPayment::withoutGlobalScope('organization')->count());
        $this->assertSame(4490, app(CommercialMetrics::class)->revenue()['total_cents']);
    }

    #[Test]
    public function a_payment_already_corrected_cannot_be_corrected_again(): void
    {
        $admin = $this->admin();
        $account = $this->proAccount();
        $payment = SubscriptionPayment::withoutGlobalScope('organization')->create([
            'organization_id' => $account->getKey(),
            'amount_cents' => 4490, 'currency' => 'EUR',
            'status' => PaymentStatus::Paid, 'paid_at' => Carbon::now(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/commercial/payments/{$payment->ulid}/refund", ['reason' => 'Primeiro motivo.'])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post("/admin/commercial/payments/{$payment->ulid}/void", ['reason' => 'Segundo motivo.'])
            ->assertSessionHasErrors('reason');

        // The first correction still stands, unamended.
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame('Primeiro motivo.', $payment->fresh()->status_reason);
    }

    #[Test]
    public function only_a_paid_payment_can_be_refunded(): void
    {
        $account = $this->proAccount();
        $pending = SubscriptionPayment::withoutGlobalScope('organization')->create([
            'organization_id' => $account->getKey(),
            'amount_cents' => 4490, 'currency' => 'EUR',
            'status' => PaymentStatus::Pending,
        ]);

        $this->actingAs($this->admin())
            ->post("/admin/commercial/payments/{$pending->ulid}/refund", ['reason' => 'Enganei-me.'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(PaymentStatus::Pending, $pending->fresh()->status);
    }

    #[Test]
    public function the_organization_of_a_payment_can_never_be_moved(): void
    {
        $account = $this->proAccount();
        $other = $this->proAccount();

        $payment = SubscriptionPayment::withoutGlobalScope('organization')->create([
            'organization_id' => $account->getKey(),
            'amount_cents' => 4490, 'currency' => 'EUR',
            'status' => PaymentStatus::Paid, 'paid_at' => Carbon::now(),
        ]);

        $this->expectException(LogicException::class);

        $payment->forceFill(['organization_id' => $other->getKey()])->save();
    }

    #[Test]
    public function recording_a_payment_lands_in_the_target_accounts_audit_trail(): void
    {
        $admin = $this->admin();
        $account = $this->proAccount();

        $this->actingAs($admin)->post("/admin/commercial/{$account->ulid}/payments", [
            'amount' => '29,90', 'currency' => 'EUR', 'status' => 'paid', 'paid_at' => '2026-08-15',
        ])->assertRedirect();

        $event = AuditEvent::withoutGlobalScope('organization')
            ->where('organization_id', $account->getKey())
            ->where('event', 'commercial.payment_recorded')
            ->firstOrFail();

        $this->assertSame($admin->getKey(), $event->causer_id);
        $this->assertSame(2990, $event->properties['amount_cents']);
    }
}
