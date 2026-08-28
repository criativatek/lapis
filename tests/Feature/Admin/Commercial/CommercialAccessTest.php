<?php

namespace Tests\Feature\Admin\Commercial;

use App\Models\Organization;
use App\Models\PaymentStatus;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The commercial area holds the most sensitive information the platform has —
 * what every account pays, and what it does not. It is reserved for the SaaS
 * operator, and the guard is server-side on EVERY endpoint, not on the link.
 *
 * This test walks the whole surface deliberately, one route at a time, instead
 * of trusting that they share a middleware group. A route added outside the
 * group is exactly the mistake that would not show up in a test that only
 * checks the index.
 */
class CommercialAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    protected function account(): Organization
    {
        return User::factory()->create()->personalOrganization();
    }

    protected function payment(Organization $organization): SubscriptionPayment
    {
        return SubscriptionPayment::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'amount_cents' => 4490,
            'currency' => 'EUR',
            'status' => PaymentStatus::Paid,
            'paid_at' => Carbon::now(),
        ]);
    }

    /**
     * EVERY endpoint this area exposes, listed once and swept against every
     * actor below. Written out one route at a time rather than derived from the
     * router: a route that silently escapes the `platform-admin` group is
     * precisely the defect this test exists to catch, and deriving the list from
     * the router would inherit that same mistake instead of catching it.
     *
     * @return list<array{0: string, 1: string, 2: array<string, string>}>
     */
    protected function endpoints(Organization $account, SubscriptionPayment $payment): array
    {
        return [
            ['get', '/admin/commercial', []],
            ['get', '/admin/commercial/export', []],
            ['get', "/admin/commercial/{$account->ulid}", []],
            ['post', "/admin/commercial/{$account->ulid}/condition", ['condition' => 'founder']],
            ['post', "/admin/commercial/{$account->ulid}/payments", [
                'amount' => '44,90', 'currency' => 'EUR', 'status' => 'paid', 'paid_at' => '2026-08-01',
            ]],
            ['post', "/admin/commercial/payments/{$payment->ulid}/refund", ['reason' => 'teste']],
            ['post', "/admin/commercial/payments/{$payment->ulid}/void", ['reason' => 'teste']],
        ];
    }

    #[Test]
    public function a_teacher_is_forbidden_from_every_commercial_endpoint(): void
    {
        $teacher = User::factory()->create();
        $account = $this->account();
        $payment = $this->payment($account);

        foreach ($this->endpoints($account, $payment) as [$method, $url, $payload]) {
            $this->actingAs($teacher)->{$method}($url, $payload)
                ->assertForbidden("{$method} {$url} devia recusar um professor");
        }
    }

    #[Test]
    public function a_teacher_who_owns_the_account_is_forbidden_from_every_commercial_endpoint(): void
    {
        // Owning the organization is what grants a teacher everything else in
        // the product. It grants nothing here: the commercial record belongs to
        // the operator, not to the customer it describes — including on THEIR
        // OWN account, which is the tempting exception to make and the wrong one.
        $owner = User::factory()->create();
        $account = $owner->personalOrganization();
        $payment = $this->payment($account);

        foreach ($this->endpoints($account, $payment) as [$method, $url, $payload]) {
            $this->actingAs($owner)->{$method}($url, $payload)
                ->assertForbidden("{$method} {$url} devia recusar o dono da própria conta");
        }
    }

    #[Test]
    public function an_institutional_admin_is_forbidden_from_every_commercial_endpoint(): void
    {
        // `institution_admin` administers a school's own tenant. It is not, and
        // must never become, a route into what every other school pays — nor
        // into its own school's financial record.
        $admin = User::factory()->create();
        $organization = $admin->personalOrganization();
        app(ChangeOrganizationPlan::class)
            ->to($organization, Plan::where('key', 'institutional')->firstOrFail());

        $payment = $this->payment($organization);

        foreach ($this->endpoints($organization->fresh(), $payment) as [$method, $url, $payload]) {
            $this->actingAs($admin)->{$method}($url, $payload)
                ->assertForbidden("{$method} {$url} devia recusar um administrador institucional");
        }
    }

    #[Test]
    public function a_guest_is_redirected_from_every_commercial_endpoint(): void
    {
        $account = $this->account();
        $payment = $this->payment($account);

        foreach ($this->endpoints($account, $payment) as [$method, $url, $payload]) {
            $this->{$method}($url, $payload)
                ->assertRedirect('/login');
        }
    }

    #[Test]
    public function a_platform_admin_reaches_the_commercial_area(): void
    {
        $this->actingAs($this->admin())->get('/admin/commercial')->assertOk();
    }
}
