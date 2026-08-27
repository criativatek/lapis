<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHY a subscription is on the plan it is on — separately from WHICH plan.
 *
 * «Membro Fundador» is not a fourth plan. It is a condition of adhesion to the
 * Pro plan, exactly like a voucher, a trial or an operator's grant. Modelling
 * it as a plan would put a commercial arrangement into the entitlement
 * resolver, where it does not belong: two accounts on the Fundador condition
 * and on the standard condition are entitled to byte-for-byte the same
 * modules. So the plan answers «what may this organization use», and this
 * column answers «on what terms did it get there» — and nothing reads this
 * column to decide access.
 *
 * NULL IS A REAL, INTENDED VALUE, AND EVERY EXISTING ROW KEEPS IT. It means
 * «origem não registada»: nobody recorded why this subscription exists, and
 * this migration deliberately does not guess. Backfilling would be inventing
 * commercial history — a Pro account today could have arrived by an operator's
 * grant, a trial that converted, or a launch condition, and the database has
 * never held the evidence to tell them apart. A wrong-but-confident value is
 * worse than an honest unknown, so the operator marks each one explicitly.
 *
 * `trial` is the one condition that is NEVER stored here and never needs to
 * be: `organization_subscriptions.status = 'trial'` already records it, as an
 * immutable historical fact that `ChangeOrganizationPlan::supersede()` goes out
 * of its way to preserve forever. Deriving it from the status it already has
 * is reading the data; writing it a second time into this column would be
 * duplicating it, with two places to disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_subscriptions', function (Blueprint $table): void {
            $table->string('commercial_condition', 20)->nullable()->after('status');
            // The operator's own words. Same role as
            // `organization_module_overrides.reason`: a condition without a
            // «porquê» is a label nobody can audit a year later.
            $table->string('commercial_condition_note', 255)->nullable()->after('commercial_condition');

            $table->index(['commercial_condition']);
        });

        $this->addCheck(
            'organization_subscriptions',
            'organization_subscriptions_commercial_condition_check',
            "commercial_condition IS NULL OR commercial_condition IN ('standard','founder','voucher','admin_grant','institutional','legacy','other')",
        );
    }

    public function down(): void
    {
        $this->dropCheck('organization_subscriptions', 'organization_subscriptions_commercial_condition_check');

        Schema::table('organization_subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['commercial_condition']);
            $table->dropColumn(['commercial_condition', 'commercial_condition_note']);
        });
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    protected function dropCheck(string $table, string $name): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
        }
    }
};
