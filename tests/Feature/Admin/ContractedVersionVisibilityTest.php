<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PublishesPlanVersions;
use Tests\TestCase;

/**
 * «ESTA ORGANIZAÇÃO ESTÁ EM PRO v1 OU PRO v2?» — SEM SQL.
 *
 * ADR-0008 names this among its consequences and it is not decoration: the
 * moment a second Pro can exist, «Pro» stops being a complete answer to an
 * operator. Two accounts on the same plan may be on different offers, and the
 * difference is exactly what a support conversation turns on. Without this,
 * finding out means opening a database client, which nobody does mid-call.
 *
 * READ-ONLY, DELIBERATELY. The page shows which version was contracted and
 * offers no way to change it. Moving a subscription between versions is a
 * migration — a decision with consequences for what a customer may do — and
 * ADR-0008 keeps it an explicit act through `ChangeOrganizationPlan`, not
 * something reachable by clicking a label. The existing plan buttons still
 * change the PLAN, which is what they always did.
 */
class ContractedVersionVisibilityTest extends TestCase
{
    use PublishesPlanVersions;
    use RefreshDatabase;

    #[Test]
    public function the_account_page_says_which_version_the_subscription_contracted(): void
    {
        $organization = $this->account();

        $this->show($organization)->assertInertia(
            fn ($page) => $page->component('admin/AccountShow')
                ->where('account.plan_key', 'base')
                ->where('account.plan_version', 1),
        );
    }

    #[Test]
    public function two_accounts_on_the_same_plan_are_told_apart_by_their_version(): void
    {
        // The whole point. Both say «Pro»; only the version distinguishes what
        // each one actually bought.
        $grandfathered = $this->account();
        app(ChangeOrganizationPlan::class)->to($grandfathered, Plan::where('key', 'pro')->firstOrFail());

        $this->publishNextVersionOf('pro');

        $newcomer = $this->account();
        app(ChangeOrganizationPlan::class)->to($newcomer, Plan::where('key', 'pro')->firstOrFail());

        $this->show($grandfathered)->assertInertia(
            fn ($page) => $page->where('account.plan', 'Pro')->where('account.plan_version', 1),
        );

        $this->show($newcomer)->assertInertia(
            fn ($page) => $page->where('account.plan', 'Pro')->where('account.plan_version', 2),
        );
    }

    #[Test]
    public function an_account_with_no_subscription_reports_no_version_rather_than_a_wrong_one(): void
    {
        $organization = $this->account();

        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->delete();

        $this->show($organization)->assertInertia(
            fn ($page) => $page->where('account.plan', null)->where('account.plan_version', null),
        );
    }

    #[Test]
    public function the_page_offers_no_way_to_change_the_version(): void
    {
        // The plan list the page renders buttons from carries keys and names
        // and nothing else — there is no version to click, by construction.
        $this->show($this->account())->assertInertia(function ($page): void {
            $plans = $page->toArray()['props']['plans'];

            $this->assertNotEmpty($plans);

            foreach ($plans as $plan) {
                $this->assertSame(['key', 'name'], array_keys((array) $plan));
            }
        });
    }

    #[Test]
    public function the_version_shown_is_the_one_in_force_and_not_the_newest_published(): void
    {
        $organization = $this->account();
        app(ChangeOrganizationPlan::class)->to($organization, Plan::where('key', 'pro')->firstOrFail());

        // Two further versions published, and the account moved onto neither.
        $this->publishNextVersionOf('pro');
        $this->publishNextVersionOf('pro');

        $this->show($organization)->assertInertia(
            fn ($page) => $page->where('account.plan_version', 1),
        );
    }

    private function account(): Organization
    {
        return User::factory()->create()->personalOrganization()->fresh();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    private function show(Organization $organization): TestResponse
    {
        return $this->actingAs($this->admin())->get("/admin/accounts/{$organization->ulid}");
    }
}
