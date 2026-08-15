<?php

namespace Tests\Feature\Admin;

use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\StudentItemScore;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Repairing databases that already carry the overlap.
 *
 * Two rules decide everything here. The subscription the teacher is already on
 * survives — the repair must be invisible from the product, changing no
 * entitlement for anybody — and nothing is deleted, because the overlap is the
 * problem and the history is not.
 *
 * The third rule is the one that matters most: a shape the command was not
 * taught about is reported and left exactly as it is. A batch job that guesses
 * at subscription history is worse than one that stops.
 */
class RepairOverlappingSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function account(): Organization
    {
        return User::factory()->create()->personalOrganization();
    }

    protected function addSubscription(
        Organization $organization,
        string $planKey,
        SubscriptionStatus $status = SubscriptionStatus::Active,
        ?string $startsAt = null,
        ?string $endsAt = null,
    ): OrganizationSubscription {
        return OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => Plan::where('key', $planKey)->firstOrFail()->getKey(),
            'status' => $status,
            'starts_at' => $startsAt ?? now()->subDay(),
            'ends_at' => $endsAt,
        ]);
    }

    /**
     * @return Collection<int, OrganizationSubscription>
     */
    protected function subscriptions(Organization $organization)
    {
        return OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->with('plan')->orderBy('id')->get();
    }

    /**
     * @return list<string>
     */
    protected function inForce(Organization $organization): array
    {
        return $this->subscriptions($organization)
            ->filter(fn (OrganizationSubscription $s): bool => $s->isInForce())
            ->map(fn (OrganizationSubscription $s): string => $s->plan->key)
            ->values()->all();
    }

    /**
     * The exact shape found in the wild: Base created with the organization and
     * Institutional laid on top in the same request, same instant, both open.
     */
    protected function withTheKnownOverlap(): Organization
    {
        $organization = $this->account();
        $base = $this->subscriptions($organization)->first();

        $this->addSubscription($organization, 'institutional', startsAt: $base->starts_at->toDateTimeString());

        $this->assertCount(2, $this->inForce($organization), 'Cenário: duas em vigor.');

        return $organization;
    }

    // ------------------------------------------------------------ 1, 3. apply

    #[Test]
    public function it_keeps_the_subscription_entitlements_already_resolved_to(): void
    {
        $organization = $this->withTheKnownOverlap();

        $entitlements = app(Entitlements::class);
        $entitlements->flush();
        $antes = $entitlements->modulesFor($organization);

        $this->artisan('lapis:repair-overlapping-subscriptions --apply')->assertSuccessful();

        $this->assertSame(['institutional'], $this->inForce($organization));

        $entitlements->flush();

        // The whole point of choosing this winner: nobody's plan changes.
        $this->assertSame($antes, $entitlements->modulesFor($organization->fresh()));
    }

    #[Test]
    public function the_superseded_row_is_closed_and_never_deleted(): void
    {
        $organization = $this->withTheKnownOverlap();
        $institutional = $this->subscriptions($organization)->last();

        $this->artisan('lapis:repair-overlapping-subscriptions --apply')->assertSuccessful();

        $base = $this->subscriptions($organization)->first();

        $this->assertCount(2, $this->subscriptions($organization), 'O histórico fica.');
        $this->assertSame('base', $base->plan->key);
        $this->assertSame(SubscriptionStatus::Expired, $base->status);
        $this->assertSame(
            $institutional->starts_at->toDateTimeString(),
            $base->ends_at->toDateTimeString(),
            'Encerra no instante em que a outra começou.',
        );
    }

    // ---------------------------------------------------------------- 2. dry run

    #[Test]
    public function without_apply_it_writes_nothing(): void
    {
        $organization = $this->withTheKnownOverlap();

        $antes = $this->subscriptions($organization)
            ->map(fn (OrganizationSubscription $s): array => [$s->id, $s->status->value, (string) $s->ends_at])->all();

        $this->artisan('lapis:repair-overlapping-subscriptions')
            ->expectsOutputToContain('Simulação')
            ->assertSuccessful();

        $depois = $this->subscriptions($organization)
            ->map(fn (OrganizationSubscription $s): array => [$s->id, $s->status->value, (string) $s->ends_at])->all();

        $this->assertSame($antes, $depois);
        $this->assertCount(2, $this->inForce($organization), 'Continua por reparar.');
    }

    #[Test]
    public function the_dry_run_names_what_it_would_keep_and_what_it_would_close(): void
    {
        $this->withTheKnownOverlap();

        // In output order: the closed one is listed before the kept one.
        $this->artisan('lapis:repair-overlapping-subscriptions')
            ->expectsOutputToContain('ENCERRA')
            ->expectsOutputToContain('MANTÉM')
            ->assertSuccessful();
    }

    // -------------------------------------------------- 4. legitimate history

    #[Test]
    public function an_organization_whose_history_does_not_overlap_is_untouched(): void
    {
        $organization = $this->account();
        $base = $this->subscriptions($organization)->first();

        // A perfectly ordinary upgrade: Base closed, Pro in force.
        $base->forceFill(['status' => SubscriptionStatus::Expired, 'ends_at' => now()->subHour()])->save();
        $this->addSubscription($organization, 'pro', startsAt: now()->subHour()->toDateTimeString());

        $antes = $this->subscriptions($organization)
            ->map(fn (OrganizationSubscription $s): array => [$s->id, $s->status->value, (string) $s->ends_at])->all();

        $this->artisan('lapis:repair-overlapping-subscriptions --apply')
            ->expectsOutputToContain('Nenhuma sobreposição')
            ->assertSuccessful();

        $this->assertSame($antes, $this->subscriptions($organization)
            ->map(fn (OrganizationSubscription $s): array => [$s->id, $s->status->value, (string) $s->ends_at])->all());
    }

    #[Test]
    public function a_suspended_subscription_is_not_an_overlap(): void
    {
        $organization = $this->account();
        $this->subscriptions($organization)->first()->forceFill(['status' => SubscriptionStatus::Suspended])->save();
        $this->addSubscription($organization, 'pro');

        $this->artisan('lapis:repair-overlapping-subscriptions --apply')
            ->expectsOutputToContain('Nenhuma sobreposição')
            ->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Suspended, $this->subscriptions($organization)->first()->status);
    }

    // ------------------------------------------------------ 5. the unknown case

    #[Test]
    public function a_shape_it_was_not_taught_about_is_reported_and_left_alone(): void
    {
        $organization = $this->account();
        $base = $this->subscriptions($organization)->first();

        // Overlapping, and the OLDER one declares its own end in the future.
        // Somebody set that window deliberately; overwriting it would destroy a
        // decision this command has not been told about.
        $base->forceFill(['starts_at' => now()->subDays(2), 'ends_at' => now()->addMonth()])->save();
        $this->addSubscription($organization, 'pro', startsAt: now()->subDay()->toDateTimeString());

        $this->assertCount(2, $this->inForce($organization), 'Cenário: duas em vigor.');

        $antes = $this->subscriptions($organization)
            ->map(fn (OrganizationSubscription $s): array => [$s->id, $s->status->value, (string) $s->ends_at])->all();

        $this->artisan('lapis:repair-overlapping-subscriptions --apply')
            ->expectsOutputToContain('NÃO REPARADO')
            ->assertFailed();

        // Nothing touched, and a non-zero exit so a deploy script stops.
        $this->assertSame($antes, $this->subscriptions($organization)
            ->map(fn (OrganizationSubscription $s): array => [$s->id, $s->status->value, (string) $s->ends_at])->all());
        $this->assertCount(2, $this->inForce($organization));
    }

    #[Test]
    public function a_staggered_overlap_closes_at_the_moment_the_newer_one_started(): void
    {
        // Not the provisioning defect: here the older subscription really was the
        // plan for a day before the newer one started without closing it. Closing
        // it at that moment is exactly right, and the history keeps both periods.
        $organization = $this->account();
        $base = $this->subscriptions($organization)->first();
        $base->forceFill(['starts_at' => now()->subDays(2)])->save();

        $pro = $this->addSubscription($organization, 'pro', startsAt: now()->subDay()->toDateTimeString());

        $this->assertCount(2, $this->inForce($organization));

        $this->artisan('lapis:repair-overlapping-subscriptions --apply')->assertSuccessful();

        $this->assertSame(['pro'], $this->inForce($organization));
        $this->assertSame(
            $pro->starts_at->toDateTimeString(),
            $this->subscriptions($organization)->first()->ends_at->toDateTimeString(),
        );
    }

    // ------------------------------------------------------------ 6. idempotent

    #[Test]
    public function running_it_twice_changes_nothing_the_second_time(): void
    {
        $organization = $this->withTheKnownOverlap();

        $this->artisan('lapis:repair-overlapping-subscriptions --apply')->assertSuccessful();

        $depoisDaPrimeira = $this->subscriptions($organization)
            ->map(fn (OrganizationSubscription $s): array => [$s->id, $s->status->value, (string) $s->ends_at])->all();

        $this->artisan('lapis:repair-overlapping-subscriptions --apply')
            ->expectsOutputToContain('Nenhuma sobreposição')
            ->assertSuccessful();

        $this->assertSame($depoisDaPrimeira, $this->subscriptions($organization)
            ->map(fn (OrganizationSubscription $s): array => [$s->id, $s->status->value, (string) $s->ends_at])->all());
    }

    #[Test]
    public function it_repairs_every_affected_organization_not_just_the_first(): void
    {
        $primeira = $this->withTheKnownOverlap();
        $segunda = $this->withTheKnownOverlap();
        $intacta = $this->account();

        $this->artisan('lapis:repair-overlapping-subscriptions --apply')->assertSuccessful();

        $this->assertSame(['institutional'], $this->inForce($primeira));
        $this->assertSame(['institutional'], $this->inForce($segunda));
        $this->assertSame(['base'], $this->inForce($intacta));
    }

    #[Test]
    public function the_command_never_touches_academic_data(): void
    {
        $organization = $this->withTheKnownOverlap();

        $antes = [
            Instrument::withoutGlobalScopes()->count(),
            StudentItemScore::withoutGlobalScopes()->count(),
            Enrollment::withoutGlobalScopes()->count(),
            Organization::query()->withoutGlobalScope('organization')->count(),
        ];

        $this->artisan('lapis:repair-overlapping-subscriptions --apply')->assertSuccessful();

        $depois = [
            Instrument::withoutGlobalScopes()->count(),
            StudentItemScore::withoutGlobalScopes()->count(),
            Enrollment::withoutGlobalScopes()->count(),
            Organization::query()->withoutGlobalScope('organization')->count(),
        ];

        $this->assertSame($antes, $depois);
        $this->assertSame($organization->getKey(), $organization->fresh()->getKey());
    }
}
