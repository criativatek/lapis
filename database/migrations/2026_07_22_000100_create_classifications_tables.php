<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The decision boundary (§7, domain-model.md §7): where Lapispro stops calculating
 * and the teacher takes over (§3.3). Nothing crosses into a grade without a row
 * in `classifications`.
 *
 * `classifications` holds proposal → confirmation → publication → manual override
 * as successive states of ONE decision about one (enrollment, period, scope), so
 * "what grade does this student have?" is a single-row read, not a join.
 *
 * `calculation_snapshots` freezes how a confirmed value was reached: literal
 * copies of inputs + the rule version + the result. Copies, never FKs into
 * scores — a later score correction must not rewrite what the past says happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_period_id')->constrained()->restrictOnDelete();
            $table->string('scope', 16);
            $table->foreignId('assessment_profile_version_id')->constrained()->restrictOnDelete();
            $table->string('trigger', 24);
            $table->string('engine_version', 16);
            // Immutable frozen document (§13.6): literal value copies, no live FKs.
            $table->json('payload');
            $table->char('payload_hash', 64); // SHA-256 — detects tampering / no-op recompute.
            // Scalars lifted out of the JSON because they ARE searched (A4 impact preview).
            $table->decimal('result_normalized_value', 9, 6)->nullable();
            $table->decimal('result_value', 6, 3)->nullable();
            $table->foreignId('result_scale_level_id')->nullable()->constrained('scale_levels')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at'); // No updated_at: a snapshot is never updated.

            $table->index(['organization_id', 'enrollment_id', 'academic_period_id', 'created_at'], 'snap_org_enroll_period_idx');
            $table->index('assessment_profile_version_id');
        });
        $this->addCheck('calculation_snapshots', 'snap_scope_check', "scope IN ('period','accumulated')");
        // `trigger` is a reserved word in MySQL — it must be quoted in raw SQL.
        $this->addCheck('calculation_snapshots', 'snap_trigger_check', "`trigger` IN ('proposal_confirmed','period_closed','year_closed','profile_migration')");

        Schema::create('classifications', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_period_id')->constrained()->restrictOnDelete();
            $table->string('scope', 16);
            $table->foreignId('assessment_profile_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('calculation_snapshot_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 16);

            // The deterministic proposal — never overwritten once written (A10).
            $table->decimal('proposed_normalized_value', 9, 6)->nullable();
            $table->decimal('proposed_value', 6, 3)->nullable();
            $table->foreignId('proposed_scale_level_id')->nullable()->constrained('scale_levels')->restrictOnDelete();

            // The teacher's decision — differs from the proposal only with a reason.
            $table->decimal('final_value', 6, 3)->nullable();
            $table->foreignId('final_scale_level_id')->nullable()->constrained('scale_levels')->restrictOnDelete();
            $table->string('override_reason', 1000)->nullable();
            $table->foreignId('overridden_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('overridden_at')->nullable();

            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('classifications')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            // One live classification per (enrollment, period, scope): the flag is
            // 1 while alive, NULL once superseded, so N superseded rows coexist in
            // history but at most one is active (NULLs don't collide in a unique).
            $table->tinyInteger('status_active_flag')
                ->storedAs("CASE WHEN status <> 'superseded' THEN 1 ELSE NULL END");
            $table->unique(
                ['enrollment_id', 'academic_period_id', 'scope', 'status_active_flag'],
                'classifications_one_live_unique',
            );
            $table->index(['organization_id', 'academic_period_id', 'status'], 'classifications_org_period_status_idx');
        });
        $this->addCheck('classifications', 'classifications_status_check', "status IN ('proposed','confirmed','published','superseded')");
        $this->addCheck('classifications', 'classifications_scope_check', "scope IN ('period','accumulated')");
        // A10, in the database: a final value that differs from the proposal is
        // refused unless a reason is written. `<=>` (null-safe) is essential —
        // with plain `=`, a NULL comparison yields NULL and the CHECK never fails.
        //
        // The first clause admits the `proposed` state (no decision made yet):
        // without it, a proposal row — final_* NULL, proposed_value set — would be
        // rejected because NULL <=> 80 is false. "No final decision" means BOTH
        // final columns are null; once either is set, the A10 rule applies.
        $this->addCheck(
            'classifications',
            'classifications_override_reason_check',
            '(final_value IS NULL AND final_scale_level_id IS NULL) '
            .'OR override_reason IS NOT NULL '
            .'OR (final_value <=> proposed_value AND final_scale_level_id <=> proposed_scale_level_id)',
        );
        $this->addCheck('classifications', 'classifications_confirmed_has_author_check', "status <> 'confirmed' OR confirmed_by IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('classifications');
        Schema::dropIfExists('calculation_snapshots');
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
