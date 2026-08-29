<?php

use App\Models\PlanVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A plan stops BEING its composition (ADR-0008).
 *
 * Until now `module_plan` was `sync()`ed by `EntitlementsSeeder` on every run
 * and `plans.limits` was a mutable JSON column, so changing what Pro sells
 * changed — retroactively, silently, and for everyone — what every Pro
 * subscriber had ever been entitled to. `plans` keeps the commercial identity
 * («Pro»); `plan_versions` holds the functional rights, and a published version
 * is never written to again.
 *
 * THE LEGACY TABLES ARE DROPPED, NOT LEFT BEHIND. `module_plan` and
 * `plans.limits` are copied into version 1 and then removed, because a live
 * composition sitting next to a versioned one is the exact defect this
 * migration exists to close: any reader still pointing at it would keep
 * answering, with the wrong value, in silence. Nothing is lost — `down()`
 * rebuilds both from the published version, and refuses outright once there is
 * more than one to rebuild from (see its own docblock).
 *
 * The backfill decides nothing. Version 1 of each plan is a byte-for-byte copy
 * of what every resolver already reads today, which is what makes «a migração
 * não altera o acesso de ninguém» a consequence of the construction rather
 * than a hope (`PlanVersionBackfillTest` proves it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            // Increasing per plan, starting at 1. Not global: «Pro v2» is the
            // second thing Pro sold, and reads as such next to «Base v1».
            $table->unsignedInteger('version');
            // The same flexible-configuration case §21.7 allows JSON for, moved
            // here from `plans.limits` so a contracted cap is frozen beside the
            // composition it was sold with.
            $table->json('limits')->nullable();
            // Ordered module keys plus canonicalized limits, hashed. Turns «did
            // the offer change?» into a comparison instead of a careful read,
            // which is what lets the seeder be idempotent.
            $table->string('composition_hash', 64);
            $table->timestamp('published_at');
            // No longer sellable, without affecting anyone already on it.
            $table->timestamp('retired_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'version']);
            // The target of `organization_subscriptions`' COMPOSITE foreign
            // key. Redundant as an index — `id` is already the primary key —
            // and load-bearing anyway: it is what lets the database itself
            // refuse a subscription whose `plan_id` and `plan_version_id`
            // disagree, instead of trusting PHP to keep them in step.
            $table->unique(['id', 'plan_id']);
            $table->index(['plan_id', 'published_at']);
        });

        Schema::create('module_plan_version', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();

            $table->unique(['plan_version_id', 'module_id']);
        });

        $this->backfillVersionOne();

        Schema::dropIfExists('module_plan');

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('limits');
        });
    }

    /**
     * REVERSIBLE ONLY WHILE EACH PLAN STILL HAS A SINGLE VERSION.
     *
     * `plans` + `module_plan` + `plans.limits` hold exactly one composition per
     * plan — that limitation is the reason this lot exists. So this rollback is
     * lossless only in the state the migration itself leaves behind: one version
     * per plan. Once a second is published, dropping `plan_versions` destroys
     * compositions the old schema has nowhere to put, and `restoreLegacyComposition()`
     * below would quietly keep the newest and discard the rest.
     *
     * The same guard the sibling migration applies to the subscriptions, applied
     * here to the catalogue: the two run as a pair in normal rollbacks (that one
     * first), and each has to be safe when run alone.
     */
    public function down(): void
    {
        $this->refuseIfHistoryNoLongerFitsTheLegacySchema();

        Schema::table('plans', function (Blueprint $table): void {
            $table->json('limits')->nullable()->after('name');
        });

        Schema::create('module_plan', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();

            $table->unique(['plan_id', 'module_id']);
        });

        $this->restoreLegacyComposition();

        Schema::dropIfExists('module_plan_version');
        Schema::dropIfExists('plan_versions');
    }

    /**
     * @throws RuntimeException when rolling back would discard published compositions
     */
    protected function refuseIfHistoryNoLongerFitsTheLegacySchema(): void
    {
        $multiVersioned = DB::table('plan_versions')
            ->select('plan_id')
            ->groupBy('plan_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('plan_id')
            ->all();

        if ($multiVersioned === []) {
            return;
        }

        $keys = DB::table('plans')->whereIn('id', $multiVersioned)->orderBy('id')->pluck('key')->implode(', ');

        throw new RuntimeException(
            'Refusing to roll back: the plan(s) ['.$keys.'] have more than one published version, and the '
            .'pre-ADR-0008 schema can only hold one composition per plan. Rolling back here would discard '
            .'published offers permanently. This migration is reversible only while a single version exists '
            .'per plan — the state it leaves behind when it first runs.'
        );
    }

    /**
     * Version 1 of every plan that exists: today's composition, copied.
     */
    protected function backfillVersionOne(): void
    {
        $now = now();

        foreach (DB::table('plans')->orderBy('id')->get() as $plan) {
            /** @var array<string, mixed>|null $limits */
            $limits = $plan->limits === null ? null : json_decode((string) $plan->limits, true);

            $moduleIds = DB::table('module_plan')
                ->where('plan_id', $plan->id)
                ->orderBy('module_id')
                ->pluck('module_id');

            /** @var list<string> $moduleKeys */
            $moduleKeys = DB::table('modules')
                ->whereIn('id', $moduleIds)
                ->pluck('key')
                ->all();

            // The hash is computed by the model, not re-implemented here, so
            // the value the backfill stores and the value the seeder compares
            // against can never come from two different functions — which
            // would make `db:seed` publish a spurious v2 with an identical
            // composition on the very next run. It is a pure static helper:
            // no query, no schema assumption, safe inside a migration.
            $versionId = DB::table('plan_versions')->insertGetId([
                'plan_id' => $plan->id,
                'version' => 1,
                'limits' => $limits === null ? null : json_encode($limits),
                'composition_hash' => PlanVersion::compositionHash($moduleKeys, $limits),
                'published_at' => $now,
                'retired_at' => null,
                'notes' => 'Composicao da 0.87 (realinhamento Base/Pro), congelada pela ADR-0008.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($moduleIds as $moduleId) {
                DB::table('module_plan_version')->insert([
                    'plan_version_id' => $versionId,
                    'module_id' => $moduleId,
                ]);
            }
        }
    }

    /**
     * Rolling back puts back the composition that is currently on sale.
     *
     * Reached only when the guard above has already established that each plan
     * has exactly one version, so «the current one» and «the only one» are the
     * same row and nothing is being chosen between. The `orderByDesc` and the
     * published/retired filters are kept anyway: they make the intent readable
     * without depending on the guard standing right next to them.
     */
    protected function restoreLegacyComposition(): void
    {
        foreach (DB::table('plans')->orderBy('id')->get() as $plan) {
            $version = DB::table('plan_versions')
                ->where('plan_id', $plan->id)
                ->whereNotNull('published_at')
                ->whereNull('retired_at')
                ->orderByDesc('version')
                ->first();

            if ($version === null) {
                continue;
            }

            DB::table('plans')->where('id', $plan->id)->update(['limits' => $version->limits]);

            $moduleIds = DB::table('module_plan_version')
                ->where('plan_version_id', $version->id)
                ->orderBy('module_id')
                ->pluck('module_id');

            foreach ($moduleIds as $moduleId) {
                DB::table('module_plan')->insert([
                    'plan_id' => $plan->id,
                    'module_id' => $moduleId,
                ]);
            }
        }
    }
};
