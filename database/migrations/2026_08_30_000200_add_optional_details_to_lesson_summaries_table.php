<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_summaries', function (Blueprint $table): void {
            $table->text('private_notes')->nullable()->after('content');
            $table->text('resources')->nullable()->after('private_notes');
            $table->text('homework')->nullable()->after('resources');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_summaries', function (Blueprint $table): void {
            $table->dropColumn(['private_notes', 'resources', 'homework']);
        });
    }
};
