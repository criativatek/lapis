<?php

namespace Tests\Feature\Ai;

use App\Models\Module;
use App\Services\Ai\Gateway\AiCapability;
use App\Services\Ai\Gateway\AiQuota;
use App\Services\Ai\Gateway\AiUseCase;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ONE CATALOGUE, AND IT AGREES WITH ITSELF.
 *
 * `AiCapability` says what an AI capability is; `EntitlementsSeeder` says what
 * a module is; `AiUseCase` says what a call is; `config('lapis.ai.quotas')`
 * says what a ceiling is; and the `ai_usage_events` columns say what fits. Five
 * places, and every one of them can drift from the others silently — a
 * capability with no module row denies everybody and says nothing; a use case
 * whose value is longer than its column truncates on write; a metered
 * capability with no quota entry is uncappable.
 *
 * This file is where those five are made to agree, mechanically, on every run.
 */
class AiCapabilityCatalogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_capability_is_a_real_catalogued_module(): void
    {
        $this->seed(EntitlementsSeeder::class);

        foreach (AiCapability::cases() as $capability) {
            $this->assertContains(
                $capability->value,
                EntitlementsSeeder::moduleKeys(),
                "{$capability->value} is an AiCapability with no entry in the module catalogue.",
            );

            $this->assertNotNull(
                Module::where('key', $capability->value)->first(),
                "{$capability->value} has no `modules` row after seeding.",
            );
        }
    }

    /**
     * A legacy key has to be a real module too — an alias pointing at nothing
     * would silently grant nothing, which is the failure mode the alias exists
     * to prevent.
     */
    #[Test]
    public function every_legacy_alias_is_a_real_catalogued_module(): void
    {
        $this->seed(EntitlementsSeeder::class);

        foreach (AiCapability::cases() as $capability) {
            foreach ($capability->legacyModuleKeys() as $legacy) {
                $this->assertContains(
                    $legacy,
                    EntitlementsSeeder::moduleKeys(),
                    "{$capability->value} aliases {$legacy}, which is not in the catalogue.",
                );
            }
        }
    }

    /**
     * THE ALIAS ONLY EVER WIDENS. A legacy key that also appeared as a
     * capability's own value would mean the alias and the current key were the
     * same string, which would make `moduleKeys()` return duplicates and the
     * transition meaningless.
     */
    #[Test]
    public function no_legacy_alias_collides_with_a_current_capability_key(): void
    {
        $current = array_map(fn (AiCapability $capability): string => $capability->value, AiCapability::cases());

        foreach (AiCapability::cases() as $capability) {
            foreach ($capability->legacyModuleKeys() as $legacy) {
                $this->assertNotContains($legacy, $current, "{$legacy} is both a capability and an alias.");
            }
        }
    }

    /**
     * The reserved key the platform settings use for the pool must never be a
     * capability — `AppServiceProvider` routes it to `lapis.ai.pool` instead of
     * to `lapis.ai.quotas`, and a collision would silently lose one of them.
     */
    #[Test]
    public function the_reserved_pool_key_is_not_a_capability(): void
    {
        $this->assertNull(
            AiCapability::tryFrom(AiQuota::POOL_LIMIT_KEY),
            AiQuota::POOL_LIMIT_KEY.' is reserved for the organizational pool and cannot also be a capability.',
        );
    }

    /**
     * Every metered capability has a configured default ceiling, in both
     * windows. A capability whose config entry is missing is one an operator
     * cannot cap from the backoffice, and it would look exactly like a
     * capability with no ceiling — which is a different, much more expensive
     * thing.
     */
    #[Test]
    public function every_metered_capability_has_both_quota_windows_configured(): void
    {
        foreach (AiCapability::metered() as $capability) {
            foreach (AiQuota::CAPABILITY_WINDOWS as $window) {
                $this->assertTrue(
                    config()->has('lapis.ai.quotas.'.$capability->value.'.'.$window),
                    "config('lapis.ai.quotas.{$capability->value}.{$window}') is missing.",
                );
            }
        }
    }

    /**
     * The two administrative capabilities never reach an engine, so nothing
     * should be trying to meter them.
     */
    #[Test]
    public function no_use_case_points_at_an_unmetered_capability(): void
    {
        foreach (AiUseCase::cases() as $useCase) {
            $capability = $useCase->capability();

            if ($capability === null) {
                continue;
            }

            $this->assertTrue(
                $capability->isMetered(),
                "{$useCase->value} is billed to {$capability->value}, which is not a metered capability.",
            );
        }
    }

    /**
     * Every metered capability is REACHABLE — something can actually ask for
     * it. A capability with no use case is a plan entry nobody can spend.
     */
    #[Test]
    public function every_metered_capability_has_at_least_one_use_case(): void
    {
        $reached = [];

        foreach (AiUseCase::cases() as $useCase) {
            $capability = $useCase->capability();

            if ($capability !== null) {
                $reached[$capability->value] = true;
            }
        }

        foreach (AiCapability::metered() as $capability) {
            $this->assertArrayHasKey(
                $capability->value,
                $reached,
                "{$capability->value} is metered but no AiUseCase points at it — nothing can spend it.",
            );
        }
    }

    /**
     * THE COLUMNS ARE 48 CHARACTERS. A value longer than that is truncated on
     * write in MySQL and the meter quietly starts grouping two capabilities
     * together — the kind of failure that is discovered from an invoice.
     */
    #[Test]
    public function every_key_fits_the_columns_that_store_it(): void
    {
        foreach (AiCapability::cases() as $capability) {
            $this->assertLessThanOrEqual(48, strlen($capability->value), "{$capability->value} will not fit ai_usage_events.capability.");
        }

        foreach (AiUseCase::cases() as $useCase) {
            $this->assertLessThanOrEqual(48, strlen($useCase->value), "{$useCase->value} will not fit ai_usage_events.use_case.");
            $this->assertLessThanOrEqual(48, strlen($useCase->capabilityColumn()), "{$useCase->value} writes a capability column value that will not fit.");
        }
    }

    /**
     * Every capability and use case has a Portuguese label and, where it makes
     * sense, a place. An operator screen that fell back to a raw key would be
     * showing the source to somebody who cannot read it.
     */
    #[Test]
    public function every_capability_and_use_case_is_named_in_portuguese(): void
    {
        foreach (AiCapability::cases() as $capability) {
            $this->assertNotSame('', trim($capability->label()));
            $this->assertNotSame($capability->value, $capability->label());
            $this->assertNotSame('', trim($capability->whereItLives()));
        }

        foreach (AiUseCase::cases() as $useCase) {
            $this->assertNotSame('', trim($useCase->label()));
            $this->assertNotSame($useCase->value, $useCase->label());
        }
    }
}
