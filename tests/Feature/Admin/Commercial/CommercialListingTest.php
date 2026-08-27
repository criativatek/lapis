<?php

namespace Tests\Feature\Admin\Commercial;

use App\Actions\Organizations\ActivateProTrial;
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
 * The listing and its filters.
 *
 * The filters run in SQL while the labels are computed in PHP
 * (`SubscriptionCondition::keyOf()`), which makes them two expressions of one
 * rule in two languages. `filtering_by_condition_agrees_with_the_label_shown`
 * exists specifically to catch them drifting apart.
 */
class CommercialListingTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    protected function account(string $name, string $planKey = 'base'): Organization
    {
        $user = User::factory()->create(['name' => $name]);
        $organization = $user->personalOrganization();
        $organization->forceFill(['name' => $name])->save();

        if ($planKey !== 'base') {
            app(ChangeOrganizationPlan::class)->to($organization, Plan::where('key', $planKey)->firstOrFail());
        }

        return $organization->fresh();
    }

    /**
     * @return list<string> organization names on the page, in order
     */
    protected function rows(array $query = []): array
    {
        $names = [];

        $this->actingAs($this->admin())
            ->get('/admin/commercial?'.http_build_query($query))
            ->assertInertia(function ($page) use (&$names) {
                $names = collect($page->toArray()['props']['subscriptions']['data'])
                    ->pluck('organization')->all();
            });

        return $names;
    }

    #[Test]
    public function the_listing_shows_one_row_per_account(): void
    {
        $account = $this->account('Escola A', 'base');

        // Three plan changes, four subscription rows, one account.
        app(ChangeOrganizationPlan::class)->to($account, Plan::where('key', 'pro')->firstOrFail());
        app(ChangeOrganizationPlan::class)->to($account, Plan::where('key', 'institutional')->firstOrFail());

        $this->assertSame(['Escola A'], array_values(array_filter($this->rows(), fn ($n) => $n === 'Escola A')));
    }

    #[Test]
    public function it_filters_by_plan(): void
    {
        $this->account('Conta Base', 'base');
        $this->account('Conta Pro', 'pro');

        $names = $this->rows(['plan' => 'pro']);

        $this->assertContains('Conta Pro', $names);
        $this->assertNotContains('Conta Base', $names);
    }

    #[Test]
    public function filtering_by_condition_agrees_with_the_label_shown(): void
    {
        $founder = $this->account('Fundadora', 'pro');
        $this->account('Sem origem', 'pro');

        $this->actingAs($this->admin())
            ->post("/admin/commercial/{$founder->ulid}/condition", ['condition' => 'founder'])
            ->assertRedirect();

        $rows = [];
        $this->actingAs($this->admin())
            ->get('/admin/commercial?condition=founder')
            ->assertInertia(function ($page) use (&$rows) {
                $rows = $page->toArray()['props']['subscriptions']['data'];
            });

        $this->assertCount(1, $rows);
        $this->assertSame('Fundadora', $rows[0]['organization']);
        // The row the SQL filter selected carries the label the PHP resolver
        // produces. If the two rules ever diverge, this fails.
        $this->assertSame('founder', $rows[0]['condition']);
        $this->assertSame('Membro Fundador', $rows[0]['condition_label']);
    }

    #[Test]
    public function unrecorded_origins_are_filterable_as_such(): void
    {
        $founder = $this->account('Fundadora', 'pro');
        $this->account('Sem origem', 'pro');

        $this->actingAs($this->admin())
            ->post("/admin/commercial/{$founder->ulid}/condition", ['condition' => 'founder'])
            ->assertRedirect();

        $names = $this->rows(['condition' => 'unknown']);

        $this->assertContains('Sem origem', $names);
        $this->assertNotContains('Fundadora', $names);
    }

    #[Test]
    public function a_trial_is_filterable_as_a_condition_of_its_own(): void
    {
        $user = User::factory()->create(['name' => 'Em experiência']);
        $organization = $user->personalOrganization();
        $organization->forceFill(['name' => 'Em experiência'])->save();
        app(ActivateProTrial::class)->activate($user, $organization);

        $this->account('Pro normal', 'pro');

        $names = $this->rows(['condition' => 'trial']);

        $this->assertContains('Em experiência', $names);
        $this->assertNotContains('Pro normal', $names);
    }

    #[Test]
    public function it_filters_by_whether_an_account_has_payments(): void
    {
        $paying = $this->account('Pagou', 'pro');
        $this->account('Não pagou', 'pro');

        SubscriptionPayment::withoutGlobalScope('organization')->create([
            'organization_id' => $paying->getKey(),
            'amount_cents' => 4490, 'currency' => 'EUR',
            'status' => PaymentStatus::Paid, 'paid_at' => Carbon::now(),
        ]);

        $this->assertContains('Pagou', $this->rows(['payment' => 'paid']));
        $this->assertNotContains('Pagou', $this->rows(['payment' => 'unpaid']));
        $this->assertContains('Não pagou', $this->rows(['payment' => 'unpaid']));
    }

    #[Test]
    public function a_refunded_payment_does_not_make_an_account_count_as_paying(): void
    {
        $account = $this->account('Reembolsada', 'pro');

        SubscriptionPayment::withoutGlobalScope('organization')->create([
            'organization_id' => $account->getKey(),
            'amount_cents' => 4490, 'currency' => 'EUR',
            'status' => PaymentStatus::Refunded, 'paid_at' => Carbon::now(),
        ]);

        $this->assertNotContains('Reembolsada', $this->rows(['payment' => 'paid']));
        $this->assertContains('Reembolsada', $this->rows(['payment' => 'unpaid']));
    }

    #[Test]
    public function it_searches_by_organization_name_and_by_owner_email(): void
    {
        $account = $this->account('Agrupamento do Norte', 'pro');
        $this->account('Outra conta', 'base');

        $this->assertContains('Agrupamento do Norte', $this->rows(['search' => 'Norte']));
        $this->assertNotContains('Outra conta', $this->rows(['search' => 'Norte']));

        $email = $account->owner->email;
        $this->assertContains('Agrupamento do Norte', $this->rows(['search' => $email]));
    }

    #[Test]
    public function the_amount_paid_on_a_row_counts_only_revenue_bearing_payments(): void
    {
        $account = $this->account('Mista', 'pro');

        foreach ([
            [4490, PaymentStatus::Paid],
            [9900, PaymentStatus::Pending],
            [9900, PaymentStatus::Refunded],
        ] as [$cents, $status]) {
            SubscriptionPayment::withoutGlobalScope('organization')->create([
                'organization_id' => $account->getKey(),
                'amount_cents' => $cents, 'currency' => 'EUR',
                'status' => $status, 'paid_at' => Carbon::now(),
            ]);
        }

        $row = null;
        $this->actingAs($this->admin())
            ->get('/admin/commercial?search=Mista')
            ->assertInertia(function ($page) use (&$row) {
                $row = $page->toArray()['props']['subscriptions']['data'][0];
            });

        $this->assertSame(4490, $row['paid_cents']);
        $this->assertSame(1, $row['payment_count']);
    }

    #[Test]
    public function a_malformed_date_filter_does_not_break_the_page(): void
    {
        $this->account('Qualquer', 'pro');

        $this->actingAs($this->admin())->get('/admin/commercial?from=nao-e-uma-data')->assertOk();
    }
}
