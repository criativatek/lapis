<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domains — stable, unversioned pedagogical concepts (§10.1, domain-model.md §2.4).
 *
 * The single most important structural decision: a domain is stable; only its
 * WEIGHT is versioned (in profile_version_domains). "Leitura" is the same concept
 * in v1 and v2 of a profile — what changes is whether it is worth 25% or 30%.
 * This lets results point at the stable domain id, so "Evolução por domínio"
 * compares periods correctly even after a profile is versioned mid-year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('parent_domain_id')->nullable()->constrained('domains')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('code', 32);
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'subject_id', 'code']);
            $table->index(['organization_id', 'parent_domain_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
