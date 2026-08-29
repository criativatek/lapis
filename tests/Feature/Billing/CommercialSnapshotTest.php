<?php

namespace Tests\Feature\Billing;

use App\Models\BillingPeriod;
use App\Models\CommercialCondition;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Entitlements\Entitlements;
use App\Support\Limits\LimitKey;
use App\Support\Limits\Limits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WHAT WAS AGREED, PROVED — AND KEPT OUT OF THE ENTITLEMENT RESOLVER.
 *
 * ADR-0008 §8. The audit behind it is blunt: the system already preserves
 * MONEY well (`SubscriptionPayment` cannot have its amount rewritten) and the
 * PROMISE badly — nothing anywhere recorded what was agreed when no payment
 * yet existed, which is exactly the free Base, the trial, the operator's grant
 * and the fortnight between a bank-transfer request and its confirmation.
 *
 * Four columns close that, and this file holds them to three rules that are
 * easy to state and easy to break:
 *
 *  1. NULL IS NOT ZERO. NULL is «no price was ever agreed or recorded»; `0` is
 *     «somebody agreed this costs nothing». Collapsing them would turn every
 *     unknown into a free account.
 *  2. THEY ARE IMMUTABLE. Written once, at creation. Proof, not settings.
 *  3. NOTHING IN THE ACCESS PATH READS THEM. `commercial_term_ends_at` is
 *     until when the CONDITION holds; `ends_at` is until when the ACCESS runs.
 *     Wiring the first into `isInForce()` would silently convert «the
 *     promotional price ended» into «the account was cut off».
 */
class CommercialSnapshotTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------- 1. NULL vs zero

    #[Test]
    public function null_and_zero_are_different_recorded_facts(): void
    {
        $unknown = $this->subscription();

        $agreedFree = $this->subscription([
            'contracted_price_cents' => 0,
            'contracted_currency' => 'EUR',
            'billing_period' => BillingPeriod::None,
        ]);

        $this->assertNull($unknown->contracted_price_cents, 'no price recorded is NULL, never 0');
        $this->assertNull($unknown->contracted_currency);
        $this->assertNull($unknown->billing_period);

        $this->assertSame(0, $agreedFree->contracted_price_cents);
        $this->assertNotNull($agreedFree->contracted_price_cents, '0 is a recorded price, and must not read as absent');
        $this->assertSame('EUR', $agreedFree->contracted_currency);

        // The distinction survives a round trip through the database, which is
        // where a nullable integer column most often loses it.
        $this->assertNull(DB::table('organization_subscriptions')->where('id', $unknown->id)->value('contracted_price_cents'));
        $this->assertSame(0, (int) DB::table('organization_subscriptions')->where('id', $agreedFree->id)->value('contracted_price_cents'));
    }

    #[Test]
    public function the_promotion_is_never_applied_retroactively(): void
    {
        // A row that predates the columns keeps all four NULL — for ever, and
        // through everything. Backfilling one would assert that an account
        // created earlier adhered under the 2026/27 promotional condition,
        // which the database has never held the evidence to say (§5 of the
        // commercial-conditions brief, and the reason the 2026_09_11_000300
        // migration deliberately backfilled nothing).
        //
        // WRITTEN STRAIGHT TO THE TABLE, not through the actions: the point is
        // to reproduce a row from before any of this existed, and going
        // through `SubscribeOrganization` would now — correctly — record the
        // promotion on it.
        $legacy = $this->subscription();
        $this->assertNull($legacy->contracted_price_cents);

        // Meanwhile the world moves: new accounts are created, and they DO
        // record the promotion. That is exactly the pressure this test exists
        // to resist — a well-meaning backfill sweeping the old rows in with
        // the new ones.
        User::factory()->count(3)->create();

        $fresh = DB::table('organization_subscriptions')->where('id', $legacy->id)->first();

        $this->assertNull($fresh->contracted_price_cents, 'an account that predates the promotion was given a price');
        $this->assertNull($fresh->contracted_currency);
        $this->assertNull($fresh->billing_period);
        $this->assertNull($fresh->commercial_term_ends_at);
        $this->assertNull($fresh->commercial_condition, 'an account that predates the promotion was labelled promotional');
    }

    #[Test]
    public function a_new_base_account_records_the_2026_27_promotion(): void
    {
        // The other half of the same rule: what is refused for old rows is
        // REQUIRED for new ones. «Gratuito no ano letivo 2026/27» was a promise
        // the landing made on every visit and the database could not evidence.
        $organization = $this->organization();

        $subscription = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())->firstOrFail();

        $this->assertSame(CommercialCondition::Promotional, $subscription->commercial_condition);
        $this->assertSame(0, $subscription->contracted_price_cents, '0 is a recorded price; NULL would mean nobody knew');
        $this->assertSame('EUR', $subscription->contracted_currency);
        $this->assertSame(BillingPeriod::None, $subscription->billing_period);
        $this->assertSame('2027-08-31', $subscription->commercial_term_ends_at?->toDateString());

        // And the condition ending is not the access ending.
        $this->assertNull($subscription->ends_at);
        $this->assertTrue($subscription->isInForce());
    }

    // ------------------------------------------------------ 2. immutability

    #[Test]
    public function the_contracted_condition_cannot_be_rewritten_after_the_fact(): void
    {
        $subscription = $this->subscription([
            'contracted_price_cents' => 4490,
            'contracted_currency' => 'EUR',
            'billing_period' => BillingPeriod::Annual,
            'commercial_term_ends_at' => Carbon::parse('2027-08-31'),
        ]);

        $changes = [
            ['contracted_price_cents' => 9990],
            ['contracted_currency' => 'USD'],
            ['billing_period' => BillingPeriod::None],
            ['commercial_term_ends_at' => Carbon::parse('2030-01-01')],
        ];

        foreach ($changes as $change) {
            try {
                $subscription->fresh()->update($change);
                $this->fail('a recorded commercial condition accepted a change to '.array_key_first($change));
            } catch (LogicException $exception) {
                $this->assertStringContainsString('immutable', $exception->getMessage());
            }
        }

        $fresh = $subscription->fresh();
        $this->assertSame(4490, $fresh->contracted_price_cents);
        $this->assertSame('EUR', $fresh->contracted_currency);
        $this->assertSame(BillingPeriod::Annual, $fresh->billing_period);
        $this->assertSame('2027-08-31', $fresh->commercial_term_ends_at?->toDateString());
    }

    #[Test]
    public function the_guard_does_not_collide_with_the_lifecycle_it_shares_a_row_with(): void
    {
        // `ChangeOrganizationPlan` does `forceFill()->save()` on `starts_at`,
        // `ends_at` and `status` constantly — superseding, suspending,
        // reactivating. A guard that caught those would have made the whole
        // service unusable, so it names exactly four columns and no more.
        $organization = $this->organization();
        $plans = app(ChangeOrganizationPlan::class);

        $plans->to($organization, Plan::where('key', 'pro')->firstOrFail());
        $plans->suspend($organization->fresh());
        $this->assertNotNull($plans->reactivate($organization->fresh()));
        $plans->to($organization->fresh(), Plan::where('key', 'base')->firstOrFail());

        $this->assertSame('base', $plans->inForce($organization->fresh())?->plan->key);
    }

    // ------------------------------------- 3. never an entitlement, ever

    #[Test]
    public function an_expired_commercial_term_does_not_end_access(): void
    {
        $subscription = $this->subscription([
            'contracted_price_cents' => 0,
            'contracted_currency' => 'EUR',
            'billing_period' => BillingPeriod::None,
            'commercial_term_ends_at' => Carbon::now()->subYear(),
            'commercial_condition' => CommercialCondition::Promotional,
        ]);

        $organization = Organization::withoutGlobalScope('organization')->findOrFail($subscription->organization_id);

        // The condition lapsed a year ago; `ends_at` is still NULL. The account
        // owes a conversation, not a lock-out.
        $this->assertTrue($subscription->fresh()->isInForce());
        $this->assertNull($subscription->fresh()->ends_at);

        app(Entitlements::class)->flush();
        $this->assertTrue(app(Entitlements::class)->allowsFor($organization->fresh(), 'classes'));
        $this->assertSame(8, app(Limits::class)->limitFor($organization->fresh(), LimitKey::ActiveClasses)->value());
    }

    #[Test]
    public function two_organizations_on_the_same_version_resolve_identically_whatever_they_paid(): void
    {
        $paid = $this->subscription([
            'contracted_price_cents' => 4490,
            'contracted_currency' => 'EUR',
            'billing_period' => BillingPeriod::Annual,
            'commercial_condition' => CommercialCondition::Standard,
        ]);

        $free = $this->subscription([
            'contracted_price_cents' => 0,
            'contracted_currency' => 'EUR',
            'billing_period' => BillingPeriod::None,
            'commercial_condition' => CommercialCondition::Promotional,
        ]);

        $entitlements = app(Entitlements::class);
        $entitlements->flush();

        $mapOf = function (OrganizationSubscription $subscription) use ($entitlements): array {
            $organization = Organization::withoutGlobalScope('organization')->findOrFail($subscription->organization_id);
            $states = $entitlements->accessStatesFor($organization->fresh());
            ksort($states);

            return $states;
        };

        // Price is not entitlement. The same version answers the same thing.
        $this->assertSame($mapOf($paid), $mapOf($free));
    }

    // --------------------------------------------- the enum additions

    #[Test]
    public function promotional_is_its_own_condition_and_expects_no_payment(): void
    {
        // Not `Standard`, which is documented as «Pro at list price» — using it
        // would make `normallyPaid()` answer true for accounts that owe nothing.
        $this->assertFalse(CommercialCondition::Promotional->normallyPaid());
        $this->assertNotSame(CommercialCondition::Standard, CommercialCondition::Promotional);
        $this->assertContains(
            'promotional',
            array_column(CommercialCondition::options(), 'value'),
        );
    }

    #[Test]
    public function there_is_no_monthly_billing_period_because_there_is_no_monthly_product(): void
    {
        $this->assertSame(
            ['annual', 'none'],
            array_map(fn (BillingPeriod $period): string => $period->value, BillingPeriod::cases()),
        );
    }

    #[Test]
    public function a_null_billing_period_is_not_the_same_fact_as_none(): void
    {
        // THE THIRD STATE, and the reason the column is nullable rather than
        // defaulted. NULL means nobody recorded a periodicity — the honest
        // answer for every row that predates the column, and what the backfill
        // deliberately leaves. `None` means somebody looked and there is no
        // billing cycle at all, as with an operator's grant. Defaulting the
        // unknown rows to `none` would have asserted the second where only the
        // first is true.
        $unrecorded = $this->subscription();
        $knownToHaveNoCycle = $this->subscription(['billing_period' => BillingPeriod::None]);

        $this->assertNull($unrecorded->billing_period);
        $this->assertSame(BillingPeriod::None, $knownToHaveNoCycle->billing_period);

        // Distinguishable in the column itself, not merely in the cast.
        $this->assertNull(DB::table('organization_subscriptions')->where('id', $unrecorded->id)->value('billing_period'));
        $this->assertSame('none', DB::table('organization_subscriptions')->where('id', $knownToHaveNoCycle->id)->value('billing_period'));

        // And queryable apart, which is what an operator eventually needs:
        // «which accounts still have no recorded periodicity?».
        $this->assertSame(
            [$unrecorded->id],
            OrganizationSubscription::withoutGlobalScope('organization')
                ->whereNull('billing_period')
                ->pluck('id')
                ->all(),
        );
    }

    #[Test]
    public function a_new_contract_may_record_either_real_periodicity(): void
    {
        // The column is not write-once-NULL: what the backfill refuses to
        // invent for old rows, a new sale records for itself.
        foreach ([BillingPeriod::Annual, BillingPeriod::None] as $period) {
            $subscription = $this->subscription(['billing_period' => $period]);

            $this->assertSame($period, $subscription->fresh()->billing_period);
        }
    }

    #[Test]
    public function the_schema_supports_the_conditions_the_adr_names_without_implementing_them(): void
    {
        // FUNDADOR: an agreed price, annual, with a term. PROMOCIONAL: zero,
        // with a currency and a term. Neither the 250 counter, nor the
        // retroactive 2026/27 application, nor renewal, nor recurring billing
        // is implemented here — the point is only that the schema would not
        // have to change to record them.
        $founder = $this->subscription([
            'contracted_price_cents' => 2990,
            'contracted_currency' => 'EUR',
            'billing_period' => BillingPeriod::Annual,
            'commercial_term_ends_at' => Carbon::parse('2027-08-31'),
            'commercial_condition' => CommercialCondition::Founder,
        ]);

        $this->assertSame(2990, $founder->fresh()->contracted_price_cents);
        $this->assertSame(BillingPeriod::Annual, $founder->fresh()->billing_period);
        $this->assertSame(CommercialCondition::Founder, $founder->fresh()->commercial_condition);
    }

    // ------------------------------------------------------------- fixtures

    private function organization(): Organization
    {
        return User::factory()->create()->personalOrganization()->fresh();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function subscription(array $snapshot = []): OrganizationSubscription
    {
        $organization = $this->organization();

        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())->delete();

        return OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => Plan::where('key', 'base')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now()->subDay(),
            ...$snapshot,
        ]);
    }
}
