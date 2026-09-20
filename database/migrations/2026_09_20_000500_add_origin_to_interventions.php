<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance: how an intervention came to exist.
 *
 * A characterisation import can now create an Intervention — see
 * `App\Actions\Interventions\CreateIntervention` and
 * `ApplyCharacterisationImport::writeMeasures()` — and the two are meant to
 * stay distinguishable later. A teacher browsing Estratégias e Medidas, or a
 * report explaining where a measure came from, must be able to say "isto
 * chegou de uma importação" rather than presenting every row as if a teacher
 * had typed it by hand from nothing.
 *
 * NULL AND 'manual' ARE THE SAME FACT. Every row this application has ever
 * written before this migration was created by a teacher through the form, so
 * the column defaults to null and nothing is backfilled — a null origin reads
 * as manual, which is what it always was. There is no ambiguity to resolve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->string('origin', 32)->nullable()->after('description_source');
        });

        $this->addCheck(
            'interventions',
            'interventions_origin_check',
            "origin IS NULL OR origin IN ('manual','characterisation_import')",
        );
    }

    public function down(): void
    {
        $this->dropCheck('interventions', 'interventions_origin_check');

        Schema::table('interventions', function (Blueprint $table) {
            $table->dropColumn('origin');
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
