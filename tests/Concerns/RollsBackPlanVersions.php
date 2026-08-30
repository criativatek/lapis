<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Rolling the ADR-0008 lot back, in the two ways it is easy to get wrong.
 *
 * THE STEP COUNT DRIFTS. Every one of these tests used `--step => 3`, which was
 * right on the day it was written and silently wrong the moment a fourth
 * migration landed on top: the rollback would take the newest three and leave
 * `create_plan_versions_tables` standing, so «the old world» would quietly stop
 * being the old world while every assertion still passed. Counting from the
 * name of the boundary migration cannot drift. Migration filenames sort
 * chronologically, which is the order the ledger holds them in and the order
 * `migrate:rollback` undoes them in, so «everything at or after this name» is
 * exactly the set that has to come off.
 *
 * THE COMMERCIAL SNAPSHOT NOW EXISTS. The snapshot migration refuses to roll
 * back once any row carries a contracted price, because those four columns are
 * proof of what was agreed with a person and no later `migrate` can bring them
 * back. That guard is right and stays right — what it protects against is a
 * production rollback. These tests reconstruct the world BEFORE the columns
 * existed, in which no row had a snapshot to lose; fixtures acquire one today
 * only because `SubscribeOrganization` correctly records the 2026/27 promotion
 * on a Base adhesion. So the snapshot is cleared first, deliberately and
 * visibly, rather than the guard being weakened to let tests through.
 *
 * `commercial_condition` is NOT cleared: it predates this lot, survives the
 * rollback, and is one of the columns the backfill test compares across the two
 * worlds.
 */
trait RollsBackPlanVersions
{
    /** The first migration of the lot. Everything from here forward comes off. */
    private const FIRST_MIGRATION_OF_THE_LOT = '2026_09_11_000100_create_plan_versions_tables';

    /**
     * Rolls the whole lot back, having first put the subscriptions into the
     * state the lot itself leaves behind.
     */
    protected function rollBackPlanVersionLot(): void
    {
        $this->clearCommercialSnapshots();

        $this->artisan('migrate:rollback', ['--step' => $this->stepsBackToPlanVersions()])->run();
    }

    /** @return int how many migrations stand between here and the start of the lot, inclusive */
    protected function stepsBackToPlanVersions(): int
    {
        return DB::table('migrations')
            ->where('migration', '>=', self::FIRST_MIGRATION_OF_THE_LOT)
            ->count();
    }

    protected function clearCommercialSnapshots(): void
    {
        DB::table('organization_subscriptions')->update([
            'contracted_price_cents' => null,
            'contracted_currency' => null,
            'billing_period' => null,
            'commercial_term_ends_at' => null,
        ]);
    }
}
