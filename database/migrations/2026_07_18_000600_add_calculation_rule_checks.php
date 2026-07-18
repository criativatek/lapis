<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CHECK constraints for the calculation-rule columns on profile versions, now
 * that the allowed values are decided (ADR-0004). Defense in depth (§21.7): the
 * enum sets are guaranteed at the database, not only in the enum/validation layer.
 *
 * MySQL only — SQLite (the test driver) does not support ALTER TABLE ADD
 * CONSTRAINT, so CI (MySQL) is where these are exercised, per ADR-0001.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    protected array $checks = [
        'apv_period_result_mode_check' => "period_result_mode IN ('weighted_domain_average','simple_domain_average','weighted_instrument_average')",
        'apv_accumulated_mode_check' => "accumulated_mode IS NULL OR accumulated_mode IN ('all_valid_year_elements','weighted_period_average','last_period_only','disabled')",
        'apv_absence_mode_check' => "absence_mode IS NULL OR absence_mode IN ('exclude_all','zero_all','zero_unjustified_only','exclude_all_warn')",
        'apv_rounding_mode_check' => "rounding_mode IS NULL OR rounding_mode IN ('half_up','half_down','half_even','ceil','floor','none')",
        'apv_rounding_stage_check' => "rounding_stage IN ('final_only','each_domain','each_stage')",
    ];

    public function up(): void
    {
        if (! $this->onMysql()) {
            return;
        }

        foreach ($this->checks as $name => $expression) {
            DB::statement("ALTER TABLE assessment_profile_versions ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        if (! $this->onMysql()) {
            return;
        }

        foreach (array_keys($this->checks) as $name) {
            DB::statement("ALTER TABLE assessment_profile_versions DROP CONSTRAINT {$name}");
        }
    }

    protected function onMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
