<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The subscription points at the VERSION it contracted (ADR-0008 §2).
 *
 * `plan_id` deliberately stays. The backoffice filters by plan key, the
 * commercial CSV exports it, `CommercialListing` eager-loads `plan`, the mails
 * read `plan->name`; removing it would touch some fifteen places to gain
 * nothing. What it must never do is disagree with the version — and that is
 * guaranteed BY THE DATABASE rather than by convention: a COMPOSITE foreign key
 * on `(plan_version_id, plan_id)` referencing the unique
 * `plan_versions (id, plan_id)`. A row whose two columns name different plans
 * cannot be inserted, whatever PHP believes.
 *
 * `restrictOnDelete`: a version with subscribers is not deletable. History does
 * not get tidied away.
 *
 * EVERY EXISTING ROW IS BACKFILLED, INCLUDING THE CLOSED ONES. That is the
 * point of the migration, not an afterthought:
 * `Entitlements::retainReadOnlyAfterDowngrade()` reads historical subscriptions
 * to decide what a teacher may still consult, and leaving them NULL would force
 * a fallback path that read the present again — the defect ADR-0008 exists to
 * close.
 *
 * Three steps in one migration, on purpose (nullable column → backfill → NOT
 * NULL + FK): each is meaningless without the next, and stopping between two of
 * them leaves a database nothing can read.
 */
return new class extends Migration
{
    /** Laravel's own convention for `foreign(['plan_version_id', 'plan_id'])`. */
    private const FOREIGN_KEY_NAME = 'organization_subscriptions_plan_version_id_plan_id_foreign';

    public function up(): void
    {
        Schema::table('organization_subscriptions', function (Blueprint $table): void {
            $table->unsignedBigInteger('plan_version_id')->nullable()->after('plan_id');
        });

        $this->backfill();

        Schema::table('organization_subscriptions', function (Blueprint $table): void {
            $table->unsignedBigInteger('plan_version_id')->nullable(false)->change();
        });

        Schema::table('organization_subscriptions', function (Blueprint $table): void {
            // Named by Laravel's own convention rather than by hand, so
            // `dropForeign()` below can name it by COLUMNS — the only form
            // SQLite accepts, and the project's test engine.
            $table->foreign(['plan_version_id', 'plan_id'])
                ->references(['id', 'plan_id'])
                ->on('plan_versions')
                ->restrictOnDelete();

            $table->index(['plan_version_id']);
        });
    }

    /**
     * REVERSIBLE ONLY WHILE THE HISTORY STILL FITS IN THE OLD SCHEMA.
     *
     * `organization_subscriptions.plan_id` alone can say «this organization is
     * on Pro». It cannot say «on Pro v1, while others are on Pro v2» — the old
     * schema had exactly one composition per plan, which is the whole reason
     * this lot exists. So dropping `plan_version_id` is lossless only while
     * every plan has a single version and every subscription of a plan points
     * at the same one: the state immediately after this migration first runs.
     *
     * Once a second version is published, the column IS the contract, and
     * discarding it would silently destroy which offer each customer bought —
     * evidence no rollback could reconstruct and nobody would notice was gone.
     * This refuses instead. Failing loudly is the only honest option: a
     * «downgrade path» that picked a version per subscription would be
     * inventing the very fact it had just deleted.
     */
    public function down(): void
    {
        $this->refuseIfHistoryNoLongerFitsTheLegacySchema();

        Schema::table('organization_subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['plan_version_id', 'plan_id']);
        });

        // MySQL BACKS A FOREIGN KEY WITH AN INDEX OF THE SAME NAME, AND KEEPS
        // IT WHEN THE CONSTRAINT IS DROPPED. Dropping `plan_version_id` next
        // does not take that index with it either — the index also covers
        // `plan_id`, so MySQL narrows it instead of removing it. The leftover
        // then collides with `up()`'s own `add constraint` on the next
        // migrate, and a rollback that cannot be re-applied is not a rollback.
        // SQLite rebuilds the table and leaves nothing behind, hence the
        // existence check rather than a driver check.
        if (Schema::hasIndex('organization_subscriptions', self::FOREIGN_KEY_NAME)) {
            Schema::table('organization_subscriptions', function (Blueprint $table): void {
                $table->dropIndex(self::FOREIGN_KEY_NAME);
            });
        }

        Schema::table('organization_subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['plan_version_id']);
            $table->dropColumn('plan_version_id');
        });
    }

    /**
     * Two independent proofs that the versioned history has outgrown the old
     * schema. Either one is enough to refuse.
     *
     *  - A plan with MORE THAN ONE version: `plans` + `module_plan` can hold
     *    one composition per plan, so a second one has nowhere to go.
     *  - Subscriptions of one plan spread over DIFFERENT versions: the
     *    grandfathering itself, and the fact `plan_id` alone cannot express.
     *
     * The second is not implied by the first in practice — it is the one that
     * names real customers rather than catalogue rows, and it is what an
     * operator reading the error needs to hear.
     *
     * @throws RuntimeException when rolling back would destroy contractual history
     */
    protected function refuseIfHistoryNoLongerFitsTheLegacySchema(): void
    {
        $multiVersioned = DB::table('plan_versions')
            ->select('plan_id')
            ->groupBy('plan_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('plan_id')
            ->all();

        $spreadAcrossVersions = DB::table('organization_subscriptions')
            ->select('plan_id')
            ->groupBy('plan_id')
            ->havingRaw('COUNT(DISTINCT plan_version_id) > 1')
            ->pluck('plan_id')
            ->all();

        $offending = array_values(array_unique([...$multiVersioned, ...$spreadAcrossVersions]));

        if ($offending === []) {
            return;
        }

        $keys = DB::table('plans')->whereIn('id', $offending)->orderBy('id')->pluck('key')->implode(', ');

        throw new RuntimeException(
            'Refusing to roll back: the plan(s) ['.$keys.'] have more than one version, or subscribers spread '
            .'across different versions, and the pre-ADR-0008 schema can only hold one composition per plan. '
            .'Rolling back here would silently discard which offer each customer contracted. '
            .'This migration is reversible only while a single version exists per plan — the state it leaves '
            .'behind when it first runs.'
        );
    }

    /**
     * Version 1 of the row's OWN plan — never a version of another plan, and
     * never the newest version of anything. At this point in the lot exactly
     * one version exists per plan and it is a copy of what the resolvers
     * already read, which is why no organization can gain or lose a single
     * capability here.
     */
    protected function backfill(): void
    {
        foreach (DB::table('plan_versions')->where('version', 1)->orderBy('id')->get() as $version) {
            DB::table('organization_subscriptions')
                ->where('plan_id', $version->plan_id)
                ->whereNull('plan_version_id')
                ->update(['plan_version_id' => $version->id]);
        }

        $orphans = DB::table('organization_subscriptions')->whereNull('plan_version_id')->count();

        if ($orphans > 0) {
            // Never expected: every subscription references a plan by foreign
            // key and the previous migration gave every plan a version 1. If it
            // happens anyway, stopping here is the only honest option — the
            // alternative is inventing a version for a subscription whose plan
            // has none, which is exactly the retroactive guess this lot exists
            // to make impossible.
            throw new RuntimeException(
                "{$orphans} subscription(s) have no version 1 to point at. Aborting rather than guessing one."
            );
        }
    }
};
