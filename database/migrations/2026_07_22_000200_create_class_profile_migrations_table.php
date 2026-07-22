<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The explicit, auditable record of moving a class from one profile version to
 * another (§10.2, A4). Without it, a migration would be a bare update of
 * `classes.assessment_profile_version_id` — invisible and unauditable. The
 * teacher sees an impact preview (per student, value before/after) and confirms
 * with a reason; history is never recalculated silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_profile_migrations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('from_version_id')->nullable()->constrained('assessment_profile_versions')->restrictOnDelete();
            $table->foreignId('to_version_id')->constrained('assessment_profile_versions')->restrictOnDelete();
            // The preview shown before confirming, frozen as an immutable document.
            $table->json('impact_preview');
            $table->unsignedSmallInteger('affected_enrollment_count');
            $table->unsignedInteger('recalculated_result_count');
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at');
            $table->string('reason', 500); // Mandatory — a migration must be justified.
            $table->timestamps();

            $table->index(['organization_id', 'class_id', 'confirmed_at'], 'cpm_org_class_confirmed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_profile_migrations');
    }
};
