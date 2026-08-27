<?php

namespace Tests\Feature\Admin\Commercial;

use App\Actions\Organizations\ActivateProTrial;
use App\Models\AuditEvent;
use App\Models\CommercialCondition;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Commercial\EffectiveSubscriptions;
use App\Support\Commercial\SubscriptionCondition;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Membro Fundador» is a condition of the Pro plan, not a fourth plan.
 *
 * The tests here pin both halves of that sentence: a Fundador is entitled to
 * exactly what a standard Pro is entitled to, and marking one changes nothing
 * about what the account may use.
 */
class CommercialConditionTest extends TestCase
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

    /**
     * The subscription that SPEAKS for the account — not simply the newest row.
     *
     * A trial leaves a dormant Base fallback scheduled to start when the trial
     * ends, so "newest `starts_at`" is the row that is not in force yet. Asking
     * `EffectiveSubscriptions` is asking the same question the application
     * itself asks, which is the only version worth asserting against.
     */
    protected function currentSubscription(Organization $organization): OrganizationSubscription
    {
        return app(EffectiveSubscriptions::class)
            ->current([$organization->getKey()])
            ->get($organization->getKey());
    }

    #[Test]
    public function founder_is_not_a_plan(): void
    {
        // The plans table holds three rows and only ever three. If «founder»
        // were ever added as a plan, this is what would catch it.
        $this->assertSame(
            ['base', 'pro', 'institutional'],
            Plan::orderBy('sort_order')->pluck('key')->all(),
        );
    }

    #[Test]
    public function marking_an_account_as_founder_changes_nothing_it_may_use(): void
    {
        $account = $this->proAccount();

        $entitlements = app(Entitlements::class);
        $before = $entitlements->modulesFor($account);

        $this->actingAs($this->admin())
            ->post("/admin/commercial/{$account->ulid}/condition", [
                'condition' => 'founder',
                'note' => 'Aderiu na campanha de lançamento.',
            ])->assertRedirect();

        $subscription = $this->currentSubscription($account);

        $this->assertSame(CommercialCondition::Founder, $subscription->commercial_condition);
        // The plan did not move.
        $this->assertSame('pro', $subscription->plan->key);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);

        $entitlements->flush();
        $this->assertSame($before, $entitlements->modulesFor($account->fresh()));
    }

    #[Test]
    public function a_founder_and_a_standard_pro_are_entitled_to_the_same_modules(): void
    {
        $founder = $this->proAccount();
        $standard = $this->proAccount();

        $this->actingAs($this->admin())
            ->post("/admin/commercial/{$founder->ulid}/condition", ['condition' => 'founder'])
            ->assertRedirect();
        $this->actingAs($this->admin())
            ->post("/admin/commercial/{$standard->ulid}/condition", ['condition' => 'standard'])
            ->assertRedirect();

        $entitlements = app(Entitlements::class);
        $entitlements->flush();

        $this->assertSame(
            $entitlements->modulesFor($standard->fresh()),
            $entitlements->modulesFor($founder->fresh()),
        );
    }

    #[Test]
    public function an_existing_subscription_has_no_condition_until_somebody_records_one(): void
    {
        // The migration deliberately backfilled nothing. Every account that
        // existed before this slice reads as "origem não registada", and that is
        // the honest answer — not "standard".
        $account = $this->proAccount();
        $subscription = $this->currentSubscription($account);

        $this->assertNull($subscription->commercial_condition);
        $this->assertSame(SubscriptionCondition::UNKNOWN, SubscriptionCondition::keyOf($subscription));
        $this->assertSame('Origem não registada', SubscriptionCondition::labelOf($subscription));
    }

    #[Test]
    public function a_condition_can_be_cleared_back_to_unrecorded(): void
    {
        $account = $this->proAccount();

        $this->actingAs($this->admin())
            ->post("/admin/commercial/{$account->ulid}/condition", ['condition' => 'founder'])
            ->assertRedirect();
        $this->assertSame(CommercialCondition::Founder, $this->currentSubscription($account)->commercial_condition);

        // An operator who marked the wrong thing must be able to say "I do not
        // know" rather than pick another wrong answer.
        $this->actingAs($this->admin())
            ->post("/admin/commercial/{$account->ulid}/condition", ['condition' => ''])
            ->assertRedirect();

        $this->assertNull($this->currentSubscription($account)->commercial_condition);
    }

    #[Test]
    public function a_trial_derives_its_condition_from_its_status_and_refuses_to_be_marked(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();

        app(ActivateProTrial::class)->activate($user, $organization);

        $subscription = $this->currentSubscription($organization->fresh());

        $this->assertSame(SubscriptionStatus::Trial, $subscription->status);
        $this->assertSame(SubscriptionCondition::TRIAL, SubscriptionCondition::keyOf($subscription));

        $this->actingAs($this->admin())
            ->post("/admin/commercial/{$organization->ulid}/condition", ['condition' => 'founder'])
            ->assertSessionHasErrors('condition');

        $this->assertNull($this->currentSubscription($organization->fresh())->commercial_condition);
    }

    #[Test]
    public function the_condition_is_never_inferred_from_the_amount_paid(): void
    {
        // 29,90 is the Fundador figure. Paying it does not make an account a
        // Fundador — only an operator saying so does.
        $account = $this->proAccount();

        $this->actingAs($this->admin())->post("/admin/commercial/{$account->ulid}/payments", [
            'amount' => '29,90',
            'currency' => 'EUR',
            'status' => 'paid',
            'paid_at' => '2026-08-01',
        ])->assertRedirect();

        $this->assertNull($this->currentSubscription($account)->commercial_condition);
    }

    #[Test]
    public function setting_a_condition_is_audited_with_its_author(): void
    {
        $admin = $this->admin();
        $account = $this->proAccount();

        $this->actingAs($admin)
            ->post("/admin/commercial/{$account->ulid}/condition", ['condition' => 'admin_grant', 'note' => 'Oferta de apoio.'])
            ->assertRedirect();

        $event = AuditEvent::withoutGlobalScope('organization')
            ->where('organization_id', $account->getKey())
            ->where('event', 'commercial.condition_set')
            ->firstOrFail();

        $this->assertSame($admin->getKey(), $event->causer_id);
        $this->assertSame('admin_grant', $event->properties['condition']);
        $this->assertSame('Oferta de apoio.', $event->properties['note']);
    }
}
