<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two new logbook kinds under "Comportamento e atitudes" (§14, SUP-RQZPAJ /
 * SUP-5LNLBF): "Atraso" and "Falta de material". The closed list in
 * `evidence_kind_check` has to widen to admit them.
 *
 * MySQL only, same as the check's own creation migration — SQLite (the test
 * driver) never had this CHECK in the first place, so there is nothing to
 * replace there; only CI's MySQL exercises it (ADR-0001).
 */
return new class extends Migration
{
    protected const string TABLE = 'evidence_records';

    protected const string CHECK_NAME = 'evidence_kind_check';

    protected const string OLD_EXPRESSION = "kind IN ('homework','incident','positive_behaviour','participation','progress','difficulty','support','contact','activity','note')";

    protected const string NEW_EXPRESSION = "kind IN ('homework','incident','positive_behaviour','participation','progress','difficulty','support','contact','activity','note','lateness','missing_material')";

    public function up(): void
    {
        if (! $this->onMysql()) {
            return;
        }

        DB::statement('ALTER TABLE '.self::TABLE.' DROP CHECK '.self::CHECK_NAME);
        DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.self::CHECK_NAME.' CHECK ('.self::NEW_EXPRESSION.')');
    }

    public function down(): void
    {
        if (! $this->onMysql()) {
            return;
        }

        DB::statement('ALTER TABLE '.self::TABLE.' DROP CHECK '.self::CHECK_NAME);
        DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.self::CHECK_NAME.' CHECK ('.self::OLD_EXPRESSION.')');
    }

    protected function onMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
