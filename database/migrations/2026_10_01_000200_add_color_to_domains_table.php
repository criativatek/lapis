<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            // Hex string, e.g. "#E8D9F3". Optional: without one, the Pauta de
            // Avaliação falls back to a deterministic pastel picked from the
            // domain's own sequence (App\Support\Assessment\DomainColorPalette)
            // — this column only lets an organization override that default.
            $table->string('color', 7)->nullable()->after('sequence');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn('color');
        });
    }
};
