<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rectifying an already published classification: the decision is not edited,
 * it is SUPERSEDED by a new row that points back at the previous one
 * (`rectifies_id`), with the reason, a note and who did it. The whole history
 * stays in the table.
 *
 * Two guarantees move into the database, because the service that will use them
 * may have a bug and the database does not:
 *
 *  - a row is `superseded` IF AND ONLY IF it has `superseded_by_id`. Until now
 *    the pointer was just a column nothing forced to be filled, and a superseded
 *    row without a successor is a decision that vanished without an heir;
 *  - the rectification facts come together or not at all: the pointer, the
 *    reason and the date. A rectification without a reason or a date is not a
 *    rectification.
 *
 * `rectified_by` is deliberately OUTSIDE that group. Who rectified is always
 * written by the service that rectifies (the acting user, never null), but a
 * RESTORE can bring a rectification whose original author cannot be mapped to
 * an account of the destination. The project rule is that such an author stays
 * empty (the record intact) and is never given to the account that imports,
 * which did not do it. Forcing the column here would force exactly that lie.
 *
 * `<=>` (null-safe) instead of `=`: with `=` a comparison with NULL yields NULL
 * and the CHECK would never fail (see the migration that created the table).
 */
return new class extends Migration
{
    public function up(): void
    {
        // The pointer CHECK is added to a table that has data: if any row breaks
        // it, the ALTER would fail halfway with an engine message. Better to stop
        // here, before touching anything, and say what the problem is.
        $violations = DB::table('classifications')
            ->whereRaw("(status = 'superseded') <> (superseded_by_id IS NOT NULL)")
            ->count();

        if ($violations > 0) {
            throw new RuntimeException(
                "Existem {$violations} classificações incoerentes (substituídas sem sucessora, ou com sucessora sem estarem substituídas). "
                .'Corrija-as antes de migrar: a retificação depende de «substituída» e «tem sucessora» serem a mesma coisa.'
            );
        }

        Schema::table('classifications', function (Blueprint $table) {
            $table->foreignId('rectifies_id')->nullable()->after('superseded_by_id')
                ->constrained('classifications')->restrictOnDelete();
            $table->string('rectification_reason', 32)->nullable()->after('rectifies_id');
            $table->string('rectification_note', 1000)->nullable()->after('rectification_reason');
            $table->foreignId('rectified_by')->nullable()->after('rectification_note')
                ->constrained('users')->restrictOnDelete();
            $table->dateTime('rectified_at')->nullable()->after('rectified_by');
        });

        $this->addCheck(
            'classifications',
            'classifications_superseded_pointer_check',
            "(status = 'superseded') <=> (superseded_by_id IS NOT NULL)",
        );
        $this->addCheck(
            'classifications',
            'classifications_rectification_complete_check',
            '(rectifies_id IS NULL AND rectification_reason IS NULL AND rectified_at IS NULL) '
            .'OR (rectifies_id IS NOT NULL AND rectification_reason IS NOT NULL AND rectified_at IS NOT NULL)',
        );
    }

    public function down(): void
    {
        $this->dropCheck('classifications', 'classifications_rectification_complete_check');
        $this->dropCheck('classifications', 'classifications_superseded_pointer_check');

        Schema::table('classifications', function (Blueprint $table) {
            // The foreign key goes before its column: MySQL refuses to drop a
            // column that still backs an FK.
            $table->dropForeign(['rectifies_id']);
            $table->dropForeign(['rectified_by']);
        });

        Schema::table('classifications', function (Blueprint $table) {
            $table->dropColumn(['rectifies_id', 'rectification_reason', 'rectification_note', 'rectified_by', 'rectified_at']);
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
            DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
        }
    }
};
