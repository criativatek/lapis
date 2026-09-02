<?php

namespace Tests\Feature\Ai;

use App\Models\AiUsageEvent;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Ai\Gateway\AiQuota;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PublishesPlanVersions;
use Tests\TestCase;

/**
 * GOVERNAÇÃO = CONSUMO E CONFIGURAÇÃO. NÃO = VIGILÂNCIA (§20).
 *
 * Two halves, and the second is the one worth testing hardest: it is easy to
 * write a governance screen that answers «which of my teachers is using the AI
 * most», and that screen is the product this module must not become.
 *
 * WHAT THIS FILE PINS DOWN:
 *
 *   the gate         only Institucional reaches it, on its own key.
 *   the isolation    an organization sees its own consumption and nobody
 *                    else's — including the platform's own connection tests.
 *   the silence      no prompt, no answer, no student, no per-member figure.
 *   the numbers      the ceilings shown are the ones actually in force.
 */
class AiGovernanceTest extends TestCase
{
    use PublishesPlanVersions;
    use RefreshDatabase;

    protected User $owner;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withoutOrganization()->create();

        // AN INSTITUTIONAL ORGANIZATION, NOT A PERSONAL ONE, AND THE OWNER OF
        // IT. The module gate knows the plan; the policy underneath knows the
        // organization's TYPE and who is asking, and both have to pass — see
        // `InstitutionAiController`. A personal organization on an
        // institutional plan is a real state (somebody bought the wrong thing)
        // and it correctly gets a 403.
        $this->organization = Organization::factory()->institutional()->create([
            'owner_id' => $this->owner->getKey(),
        ]);
        $this->organization->members()->attach($this->owner, ['joined_at' => now()]);

        $this->seed(EntitlementsSeeder::class);
    }

    /** The tenant is resolved from the session, exactly as a real request does. */
    private function asOwner(): self
    {
        return $this->actingAs($this->owner)->withSession(['organization_id' => $this->organization->getKey()]);
    }

    // ------------------------------------------------------------------ o gate

    #[Test]
    public function a_base_organization_cannot_reach_the_governance_screen(): void
    {
        $this->givePlan('base');

        $this->asOwner()->get('/institution/ia')->assertForbidden();
    }

    #[Test]
    public function a_pro_organization_cannot_reach_the_governance_screen(): void
    {
        $this->givePlan('pro');

        $this->asOwner()->get('/institution/ia')->assertForbidden();
    }

    #[Test]
    public function an_institutional_organization_reaches_it(): void
    {
        $this->givePlan('institutional');

        $this->asOwner()
            ->get('/institution/ia')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('institution/Ai'));
    }

    // ------------------------------------------------------ o que mostra

    #[Test]
    public function it_lists_every_capability_with_whether_the_plan_includes_it(): void
    {
        $this->givePlan('institutional');

        $this->asOwner()
            ->get('/institution/ia')
            ->assertInertia(function (AssertableInertia $page): void {
                $capabilities = collect($page->toArray()['props']['capabilities']);

                // Only the metered ones get a row: governance and the pool are
                // not features anybody spends.
                $this->assertCount(6, $capabilities);

                foreach ($capabilities as $capability) {
                    $this->assertTrue($capability['allowed'], "{$capability['key']} should be included for Institucional.");
                    $this->assertNotSame('', trim((string) $capability['where']));
                    $this->assertArrayHasKey('user_daily', $capability['limits']);
                    $this->assertArrayHasKey('organization_monthly', $capability['limits']);
                }
            });
    }

    #[Test]
    public function it_shows_the_pool_state_including_that_it_is_unlimited_by_default(): void
    {
        $this->givePlan('institutional');

        $this->asOwner()
            ->get('/institution/ia')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pool.applies', true)
                ->where('pool.organization_monthly', null)
                ->where('pool.user_monthly', null)
                ->where('pool.used_this_month', 0));
    }

    #[Test]
    public function a_contract_plafond_is_shown_with_what_has_been_spent(): void
    {
        $this->givePlan('institutional');

        $this->contractNewLimitsFor($this->organization, 'institutional', [
            AiQuota::POOL_LIMIT_KEY => ['organization_monthly' => 1000, 'user_monthly' => 50],
        ]);

        $this->recordUsage(3);

        $this->asOwner()
            ->get('/institution/ia')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pool.organization_monthly', 1000)
                ->where('pool.user_monthly', 50)
                ->where('pool.used_this_month', 3));
    }

    // --------------------------------------------------------- o isolamento

    #[Test]
    public function it_counts_only_this_organizations_consumption(): void
    {
        $this->givePlan('institutional');

        $this->recordUsage(2);

        // Another school's traffic, and the platform's own connection test.
        $other = User::factory()->create()->personalOrganization();
        AiUsageEvent::create([
            'organization_id' => $other->getKey(),
            'user_id' => null,
            'capability' => 'help_assistant',
            'use_case' => 'help_answer',
            'provider' => 'fake',
            'model' => 'modelo-de-teste',
            'status' => AiUsageEvent::SUCCEEDED,
            'created_at' => now(),
        ]);
        AiUsageEvent::create([
            'organization_id' => null,
            'user_id' => null,
            'capability' => 'platform',
            'use_case' => 'admin_connection_test',
            'provider' => 'fake',
            'model' => 'modelo-de-teste',
            'status' => AiUsageEvent::SUCCEEDED,
            'created_at' => now(),
        ]);

        $this->asOwner()
            ->get('/institution/ia')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('usage.totals.calls', 2));
    }

    /**
     * THE ASSERTION THIS WHOLE FILE EXISTS FOR. Nothing on the page, anywhere
     * in the payload, at any depth, may be a prompt, an answer, a student, or a
     * per-member figure.
     */
    #[Test]
    public function the_payload_carries_no_content_and_no_person(): void
    {
        $this->givePlan('institutional');
        $this->recordUsage(2);

        $response = $this->asOwner()->get('/institution/ia')->assertOk();

        // THIS CONTROLLER'S OWN PROPS, not the whole Inertia payload. The
        // shared shell carries the signed-in user's name and the navigation,
        // which every page in the application has and which this page is not
        // responsible for; scanning it would be testing `HandleInertiaRequests`
        // under a misleading name.
        $props = $response->viewData('page')['props'];

        // AN ALLOWLIST OF KEYS, NOT A BLOCKLIST OF WORDS. A substring hunt for
        // «answer» flags `help_answer`, which is a use-case identifier and not
        // an answer; and it would miss a column called `q` entirely. Naming the
        // keys that may appear is the check that actually holds: anything a
        // future change adds to this payload fails here until somebody looks at
        // it.
        $this->assertSame(['name'], array_keys($props['organization']));

        foreach ($props['capabilities'] as $capability) {
            $this->assertSame(['key', 'label', 'where', 'allowed', 'limits'], array_keys($capability));
        }

        $this->assertSame(
            ['applies', 'organization_monthly', 'user_monthly', 'used_this_month'],
            array_keys($props['pool']),
        );

        $this->assertSame(
            ['since', 'totals', 'by_capability', 'by_use_case', 'blocked_reasons'],
            array_keys($props['usage']),
        );

        $countingKeys = ['calls', 'succeeded', 'failed', 'blocked', 'billable', 'total_tokens'];

        $this->assertSame($countingKeys, array_keys($props['usage']['totals']));

        foreach ($props['usage']['by_capability'] as $row) {
            $this->assertSame(['capability', 'label', ...$countingKeys], array_keys($row));
        }

        foreach ($props['usage']['by_use_case'] as $row) {
            $this->assertSame(['use_case', 'label', ...$countingKeys], array_keys($row));
        }

        // NO SUBSTRING PASS, AND THE ABSENCE IS DELIBERATE. The obvious extra
        // check — «the payload must not contain the word "aluno"» — flags
        // «IA no acompanhamento do aluno», which is the NAME OF A FEATURE. A
        // blocklist of Portuguese words cannot tell a label from a leak, and a
        // test that has to be argued with every time somebody names a
        // capability is a test people delete. The key allowlist above is
        // strictly stronger: `user_id`, `subject_hash`, a prompt or an answer
        // cannot appear in this payload without failing one of the
        // `assertSame` calls, whatever they happen to be called.
    }

    // ---------------------------------------------------------------- helpers

    private function recordUsage(int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            AiUsageEvent::create([
                'organization_id' => $this->organization->getKey(),
                'user_id' => $this->owner->getKey(),
                'capability' => 'help_assistant',
                'use_case' => 'help_answer',
                'provider' => 'fake',
                'model' => 'modelo-de-teste',
                'input_tokens' => 100,
                'output_tokens' => 50,
                'total_tokens' => 150,
                'duration_ms' => 120,
                'status' => AiUsageEvent::SUCCEEDED,
                'created_at' => now(),
            ]);
        }
    }

    protected function givePlan(string $key): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->getKey()],
            [
                'plan_id' => Plan::where('key', $key)->firstOrFail()->getKey(),
                'status' => SubscriptionStatus::Active,
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
                'ends_at' => null,
            ],
        );

        app(CurrentOrganization::class)->set($this->organization);
        app(Entitlements::class)->flush();
    }
}
