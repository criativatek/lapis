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

    #[Test]
    public function a_teacher_is_forbidden_from_every_commercial_endpoint(): void
    {
        $teacher = User::factory()->create();
        $account = $this->account();
        $payment = $this->payment($account);

        $this->actingAs($teacher)->get('/admin/commercial')->assertForbidden();
        $this->actingAs($teacher)->get('/admin/commercial/export')->assertForbidden();
        $this->actingAs($teacher)->get("/admin/commercial/{$account->ulid}")->assertForbidden();
        $this->actingAs($teacher)->post("/admin/commercial/{$account->ulid}/condition", ['condition' => 'founder'])->assertForbidden();
        $this->actingAs($teacher)->post("/admin/commercial/{$account->ulid}/payments", [
            'amount' => '44,90', 'currency' => 'EUR', 'status' => 'paid', 'paid_at' => '2026-08-01',
        ])->assertForbidden();
        $this->actingAs($teacher)->post("/admin/commercial/payments/{$payment->ulid}/refund", ['reason' => 'teste'])->assertForbidden();
        $this->actingAs($teacher)->post("/admin/commercial/payments/{$payment->ulid}/void", ['reason' => 'teste'])->assertForbidden();
    }

    #[Test]
    public function a_teacher_who_owns_the_account_is_still_forbidden_from_its_commercial_record(): void
    {
        // Owning the organization is what grants a teacher everything else in
        // the product. It grants nothing here: the commercial record belongs to
        // the operator, not to the customer it describes.
        $owner = User::factory()->create();
        $account = $owner->personalOrganization();

        $this->actingAs($owner)->get("/admin/commercial/{$account->ulid}")->assertForbidden();
    }

    #[Test]
    public function an_institutional_admin_does_not_reach_the_global_financials(): void
    {
        // `institution_admin` administers a school's own tenant. It is not, and
        // must never become, a route into what every other school pays.
        $admin = User::factory()->create();
        $organization = $admin->personalOrganization();
        app(ChangeOrganizationPlan::class)
            ->to($organization, Plan::where('key', 'institutional')->firstOrFail());

        $this->actingAs($admin)->get('/admin/commercial')->assertForbidden();
    }

    #[Test]
    public function a_guest_is_redirected_rather_than_shown_anything(): void
    {
        $this->get('/admin/commercial')->assertRedirect('/login');
    }

    #[Test]
    public function a_platform_admin_reaches_the_commercial_area(): void
    {
        $this->actingAs($this->admin())->get('/admin/commercial')->assertOk();
    }
}
