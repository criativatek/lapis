<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pointer to the student's photo file on the private disk — never a public
 * URL, never the bytes themselves. Nullable: most students still have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_identities', function (Blueprint $table) {
            $table->string('photo_path', 255)->nullable()->after('birth_date');
        });
    }

    public function down(): void
    {
        Schema::table('student_identities', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
