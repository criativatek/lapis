<?php

namespace Tests\Feature\Admin\Commercial;

use App\Models\Organization;
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
 * THE RULE: revenue is recorded payments, never plans.
 *
 * Every test here exists because the alternative — counting Pro accounts and
 * multiplying by the list price — is both the obvious implementation and wrong
 * for every account this database has ever held.
 */
class CommercialRevenueTest extends TestCase
{
    use RefreshDatabase;

    protected function metrics(): CommercialMetrics
    {
        return app(CommercialMetrics::class);
    }

    protected function account(string $planKey = 'base'): Organization
    {
        $organization = User::factory()->create()->personalOrganization();

        if ($planKey !== 'base') {
            app(ChangeOrganizationPlan::class)->to($organization, Plan::where('key', $planKey)->firstOrFail());
        }

        return $organization->fresh();
    }

    protected function pay(Organization $organization, int $cents, PaymentStatus $status = PaymentStatus::Paid, ?Carbon $paidAt = null): SubscriptionPayment
    {
        return SubscriptionPayment::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'amount_cents' => $cents,
            'currency' => 'EUR',
            'status' => $status,
            'paid_at' => $status === PaymentStatus::Paid ? ($paidAt ?? Carbon::now()) : $paidAt,
        ]);
    }

    #[Test]
    public function a_pro_account_with_no_payment_produces_no_revenue(): void
    {
        $this->account('pro');
        $this->account('pro');
        $this->account('pro');

        $metrics = $this->metrics();

        // Three Pro accounts. The tempting answer is 3 x 44,90 = 134,70.
        $this->assertSame(3, $metrics->accounts()['by_plan']['pro']);
        $this->assertSame(0, $metrics->revenue()['total_cents']);
        $this->assertSame(0, $metrics->revenue()['paid_count']);
        $this->assertNull($metrics->revenue()['average_cents']);
    }

    #[Test]
    public function only_paid_payments_count_as_revenue(): void
    {
        $account = $this->account('pro');

        $this->pay($account, 4490, PaymentStatus::Paid);
        $this->pay($account, 9900, PaymentStatus::Pending);
        $this->pay($account, 9900, PaymentStatus::Failed);
        $this->pay($account, 9900, PaymentStatus::Cancelled);
        $this->pay($account, 9900, PaymentStatus::Refunded);

        $revenue = $this->metrics()->revenue();

        $this->assertSame(4490, $revenue['total_cents']);
        $this->assertSame(1, $revenue['paid_count']);
    }

    #[Test]
    public function a_total_refund_leaves_net_revenue_entirely(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        $account = $this->account('pro');
        $payment = $this->pay($account, 4490);

        $this->assertSame(4490, $this->metrics()->revenue()['total_cents']);

        $this->actingAs($admin)
            ->post("/admin/commercial/payments/{$payment->ulid}/refund", ['reason' => 'Pedido do cliente dentro do prazo.'])
            ->assertRedirect();

        $this->assertSame(0, $this->metrics()->revenue()['total_cents']);
        // And the original figure survives — a refund never rewrites the amount.
        $this->assertSame(4490, $payment->fresh()->amount_cents);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
    }

    #[Test]
    public function revenue_in_a_period_is_grouped_by_when_the_money_arrived(): void
    {
        $account = $this->account('pro');

        $this->pay($account, 2990, PaymentStatus::Paid, Carbon::parse('2026-03-15'));
        $this->pay($account, 4490, PaymentStatus::Paid, Carbon::parse('2026-07-20'));

        $inWindow = $this->metrics()->revenue(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31')->endOfDay(),
        );

        $this->assertSame(4490, $inWindow['period_cents']);
        // The all-time figure is unaffected by the window.
        $this->assertSame(7480, $inWindow['total_cents']);
    }

    #[Test]
    public function a_founder_account_with_no_payment_generates_no_revenue(): void
    {
        // The most seductive wrong number in this whole area: an account marked
        // Membro Fundador looks like 29,90 EUR waiting to be counted. The
        // condition is a fact about HOW the account was sold; it is not evidence
        // that anybody paid, and it carries no figure of its own.
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        $account = $this->account('pro');

        $this->actingAs($admin)
            ->post("/admin/commercial/{$account->ulid}/condition", ['condition' => 'founder'])
            ->assertRedirect();

        $accounts = $this->metrics()->accounts();

        // Visible as a Fundador in the breakdown...
        $this->assertSame(1, $accounts['pro_by_condition']['founder']);
        // ...and worth exactly nothing until a payment is recorded.
        $this->assertSame(0, $this->metrics()->revenue()['total_cents']);
        $this->assertSame(0, $accounts['paying']);
    }

    #[Test]
    public function an_institutional_account_with_no_price_generates_no_revenue(): void
    {
        // Institucional is "sob consulta" and has no self-service adhesion. An
        // institutional account must never acquire an imputed value.
        $this->account('institutional');

        $this->assertSame(1, $this->metrics()->accounts()['by_plan']['institutional']);
        $this->assertSame(0, $this->metrics()->revenue()['total_cents']);
    }

    #[Test]
    public function a_recorded_price_survives_a_later_price_change(): void
    {
        $account = $this->account('pro');
        $payment = $this->pay($account, 2990); // paid under the Fundador condition

        // Whatever happens to the offer afterwards, the payment is what it was.
        $this->assertSame(2990, $payment->fresh()->amount_cents);

        $this->expectException(LogicException::class);

        // The guarantee, made structural: there is no code path that can carry a
        // new price back onto an old payment, because the column refuses to be
        // written twice.
        $payment->forceFill(['amount_cents' => 4490])->save();
    }

    #[Test]
    public function the_date_the_money_arrived_cannot_be_rewritten_either(): void
    {
        $account = $this->account('pro');
        $payment = $this->pay($account, 4490, PaymentStatus::Paid, Carbon::parse('2026-03-15'));

        $this->expectException(LogicException::class);

        $payment->forceFill(['paid_at' => Carbon::parse('2026-07-01')])->save();
    }

    #[Test]
    public function the_average_is_absent_rather_than_zero_when_nothing_was_paid(): void
    {
        $this->account('pro');

        $this->assertNull($this->metrics()->revenue()['average_cents']);
    }

    #[Test]
    public function accounts_with_payments_are_counted_separately_from_accounts_on_pro(): void
    {
        $paying = $this->account('pro');
        $this->account('pro');      // Pro, never paid
        $this->account('base');     // Base

        $this->pay($paying, 4490);

        $accounts = $this->metrics()->accounts();

        $this->assertSame(2, $accounts['by_plan']['pro']);
        $this->assertSame(1, $accounts['paying']);
    }

    #[Test]
    public function the_dashboard_shows_zero_rather_than_an_estimate(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->account('pro');

        $this->actingAs($admin)->get('/admin/commercial')->assertInertia(
            fn ($page) => $page
                ->component('admin/Commercial')
                ->where('metrics.revenue.total_cents', 0)
                ->where('metrics.revenue.paid_count', 0)
                ->where('metrics.accounts.by_plan.pro', 1),
        );
    }
}
