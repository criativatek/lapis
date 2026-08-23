<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instrument_items', function (Blueprint $table): void {
            $table->decimal('points_possible', 8, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('instrument_items', function (Blueprint $table): void {
            $table->decimal('points_possible', 8, 4)->nullable(false)->change();
        });
    }
};
