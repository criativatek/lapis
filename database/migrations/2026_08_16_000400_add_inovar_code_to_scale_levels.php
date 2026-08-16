<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a band of a scale is called in INOVAR.
 *
 * The export has to write F, I, S, B or MB, and neither of the two things the
 * band already carries can supply that. `code` is «1»…«5» — the scale's own
 * short name for the band, which happens to be a number here and would be
 * something else on another scale; reading it as an INOVAR code would be
 * deciding, silently, that «4» means «B». `label` is authored text somebody
 * will one day translate or rephrase, and a mapping keyed on «Bom» loses its
 * meaning the moment they do.
 *
 * So the correspondence is stated, once, per band. Nullable because most scales
 * have no correspondence at all and inventing one is worse than having none:
 * an export against a scale that lacks it is refused, with the reason.
 *
 * NOT a general-purpose external-code table. One integration exists; when a
 * second one does, the column it needs can be added beside this without either
 * pretending to be the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scale_levels', function (Blueprint $table): void {
            $table->string('inovar_code', 8)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('scale_levels', function (Blueprint $table): void {
            $table->dropColumn('inovar_code');
        });
    }
};
