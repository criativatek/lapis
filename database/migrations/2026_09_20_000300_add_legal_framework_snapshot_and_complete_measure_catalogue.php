<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two additive changes, both in service of the same thing: an intervention's
 * legal framing must still be readable, exactly as it was meant, after the law
 * changes.
 *
 * 1. `legal_framework_code` — the version of the law the framing was decided
 *    under, stamped on the row. Until now the applicable regime was re-derived
 *    from `started_on` every time it was read. That is correct while one
 *    version exists and stays correct afterwards, but it is a DERIVATION: the
 *    day a second version is encoded, every historical row's meaning depends on
 *    getting the effective dates exactly right, with nothing to check them
 *    against. The stamp is that check. Reading still resolves by date; the
 *    stamp is what makes a wrong resolution detectable instead of silent.
 *
 * 2. The `support_measure_code` CHECK is widened to the full catalogue of
 *    Decreto-Lei n.º 54/2018. The constraint held a literal copy of nine codes;
 *    the catalogue now names all fifteen measures of articles 8.º, 9.º and 10.º.
 *
 * Nothing is dropped, renamed or rewritten. No existing row changes except to
 * gain a stamp it did not have, and the backfill is deterministic.
 */
return new class extends Migration
{
    /**
     * The only framework version that has ever been applied. Hardcoded rather
     * than resolved through the registry on purpose: a migration must produce
     * the same result in five years, and reaching into application code would
     * make its output depend on whatever the registry says the day it runs.
     */
    private const CURRENT_PORTUGUESE_FRAMEWORK = 'pt-inclusive-education-2018';

    /** @var list<string> */
    private const ALL_MEASURE_CODES = [
        'pedagogical_differentiation', 'curricular_accommodation', 'curricular_enrichment',
        'pro_social_behaviour_promotion', 'academic_focus_small_group',
        'differentiated_curricular_paths', 'non_significant_curricular_adaptation',
        'psychopedagogical_support', 'anticipation_learning_reinforcement', 'tutorial_support',
        'subject_by_subject_year_attendance', 'significant_curricular_adaptation',
        'individual_transition_plan', 'structured_teaching_methodologies',
        'personal_social_autonomy_skills',
    ];

    /** The nine codes the constraint allowed before this migration. */
    private const PREVIOUS_MEASURE_CODES = [
        'pedagogical_differentiation', 'curricular_accommodation', 'academic_focus_small_group',
        'non_significant_curricular_adaptation', 'anticipation_learning_reinforcement',
        'psychopedagogical_support', 'tutorial_support', 'significant_curricular_adaptation',
        'personal_social_autonomy_skills',
    ];

    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->string('legal_framework_code', 64)->nullable()->after('legal_mapping_source');
        });

        Schema::table('intervention_support_measures', function (Blueprint $table) {
            $table->string('legal_framework_code', 64)->nullable()->after('legal_mapping_source');
        });

        $this->backfill();

        // Widen, rather than add a second constraint: two CHECKs on one column
        // would both have to be satisfied, so the old narrow one would keep
        // rejecting the new codes.
        $this->dropCheck('intervention_support_measures', 'intervention_support_measures_code_check');
        $this->addCheck(
            'intervention_support_measures',
            'intervention_support_measures_code_check',
            'support_measure_code IN ('.$this->quoted(self::ALL_MEASURE_CODES).')',
        );
    }

    /**
     * Stamps the framework version onto every row that already carries a legal
     * framing.
     *
     * Only Portuguese organizations, and only rows that were actually framed. A
     * row with no measure and no adaptation was never read under any regime, so
     * inventing a stamp for it would assert something nobody decided. An
     * organization in another jurisdiction resolved to NullLegalFramework and
     * cannot have a Portuguese framing; if one somehow does, it is left NULL
     * rather than claimed — NULL keeps the current behaviour of resolving by
     * date, which is exactly what those rows have always done.
     */
    protected function backfill(): void
    {
        // An organization that never stated a jurisdiction follows
        // config('lapis.default_jurisdiction') — LegalFrameworkResolver's rule,
        // not a guess. Where that default is not Portugal, such an
        // organization resolves to NullLegalFramework and has no Portuguese
        // framing to stamp, so it is left alone. Claiming it would be exactly
        // the false legal assertion this backfill is careful not to make.
        $defaultIsPortuguese = strtoupper(trim((string) config('lapis.default_jurisdiction'))) === 'PT';

        $portugueseOrganizationIds = DB::table('organizations')
            ->where(function ($query) use ($defaultIsPortuguese) {
                $query->whereRaw('UPPER(TRIM(jurisdiction)) = ?', ['PT']);

                if ($defaultIsPortuguese) {
                    $query->orWhereNull('jurisdiction')->orWhere('jurisdiction', '');
                }
            })
            ->pluck('id');

        if ($portugueseOrganizationIds->isEmpty()) {
            return;
        }

        DB::table('interventions')
            ->whereIn('organization_id', $portugueseOrganizationIds)
            ->where(function ($query) {
                $query->whereNotNull('support_measure_code')
                    ->orWhereNotNull('evaluation_adaptation_code');
            })
            ->update(['legal_framework_code' => self::CURRENT_PORTUGUESE_FRAMEWORK]);

        DB::table('intervention_support_measures')
            ->whereIn('intervention_id', function ($query) use ($portugueseOrganizationIds) {
                $query->select('id')
                    ->from('interventions')
                    ->whereIn('organization_id', $portugueseOrganizationIds);
            })
            ->update(['legal_framework_code' => self::CURRENT_PORTUGUESE_FRAMEWORK]);
    }

    public function down(): void
    {
        // Restoring the narrow constraint is only safe if nothing uses the six
        // measures it does not know. Those rows are real records of real
        // decisions; dropping the column or deleting them to make the rollback
        // succeed would destroy history to undo a schema change. So the
        // rollback stops and says which codes are in the way — the same
        // contract the 2026_08_14 migration already sets for orphaned
        // class-wide interventions.
        $newCodes = array_values(array_diff(self::ALL_MEASURE_CODES, self::PREVIOUS_MEASURE_CODES));

        $blocking = DB::table('intervention_support_measures')
            ->whereIn('support_measure_code', $newCodes)
            ->distinct()
            ->pluck('support_measure_code')
            ->all();

        if ($blocking !== []) {
            throw new RuntimeException(
                'Rollback abortado: existem medidas de suporte registadas com códigos que a versão anterior do catálogo não conhece ('.
                implode(', ', $blocking).'). Converta ou exporte estes registos antes de reverter esta migração.'
            );
        }

        $this->dropCheck('intervention_support_measures', 'intervention_support_measures_code_check');
        $this->addCheck(
            'intervention_support_measures',
            'intervention_support_measures_code_check',
            'support_measure_code IN ('.$this->quoted(self::PREVIOUS_MEASURE_CODES).')',
        );

        Schema::table('intervention_support_measures', function (Blueprint $table) {
            $table->dropColumn('legal_framework_code');
        });

        Schema::table('interventions', function (Blueprint $table) {
            $table->dropColumn('legal_framework_code');
        });
    }

    /**
     * @param  list<string>  $values
     */
    protected function quoted(array $values): string
    {
        return implode(',', array_map(fn (string $value) => "'".$value."'", $values));
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    /**
     * `DROP CHECK` is MySQL 8.0.16+ syntax; MariaDB spells it `DROP
     * CONSTRAINT`. The sibling migrations only ever drop a check inside
     * `down()`, where a failure is loud and recoverable — this one drops in
     * `up()`, where MySQL and MariaDB run no DDL transaction and a failure
     * would leave the columns added, the backfill done, and the migration
     * unmarked, so re-running it would collide on a duplicate column.
     *
     * The drop is also tolerated when the constraint is absent: on MySQL below
     * 8.0.16 the check this replaces was parsed and ignored, so it was never
     * created and there is nothing to drop.
     */
    protected function dropCheck(string $table, string $name): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $clause = $driver === 'mariadb' ? 'DROP CONSTRAINT' : 'DROP CHECK';

        try {
            DB::statement("ALTER TABLE {$table} {$clause} {$name}");
        } catch (QueryException $exception) {
            if (! str_contains($exception->getMessage(), $name)) {
                throw $exception;
            }
        }
    }
};
