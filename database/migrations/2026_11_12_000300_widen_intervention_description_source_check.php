<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Widens `interventions_description_source_check` to accept `import`.
 *
 * `ApplyCharacterisationImport` was stamping the description it composes
 * itself ("Importado da caracterização — o ficheiro indicava: …") as
 * `manual` — the value documented as "Escrita pelo professor". That is
 * dishonest: the enum exists precisely so a report quoting a teacher's own
 * words can be told apart from text this application generated. See
 * `InterventionDescriptionSource::Import`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropCheck('interventions', 'interventions_description_source_check');
        $this->addCheck(
            'interventions',
            'interventions_description_source_check',
            "description_source IN ('manual','template','ai','import')",
        );
    }

    public function down(): void
    {
        $this->dropCheck('interventions', 'interventions_description_source_check');
        $this->addCheck(
            'interventions',
            'interventions_description_source_check',
            "description_source IN ('manual','template','ai')",
        );
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
