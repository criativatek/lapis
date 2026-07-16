<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entitlements are persisted, not hardcoded (§4.3, §8.2): which modules a plan
 * carries has to be changeable through data, so the commercial composition of
 * Base/Pro/Institucional can move without touching the architecture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name');
            // Flexible, versioned configuration — the one case §21.7 allows JSON for.
            $table->json('limits')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // module_plan, not plan_module: Eloquent derives a belongsToMany pivot
        // name by sorting the model names alphabetically.
        Schema::create('module_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();

            $table->unique(['plan_id', 'module_id']);
        });

        // An organization's entitlement period. No payment provider is involved
        // (§8.2): the MVP records what an organization is entitled to and when,
        // and billing can be attached later without reshaping this.
        Schema::create('organization_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        // Per-organization exceptions: sell a Pro module to a Base school, or
        // withdraw one, without inventing a bespoke plan for them.
        Schema::create('organization_module_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled');
            $table->string('reason')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_module_overrides');
        Schema::dropIfExists('organization_subscriptions');
        Schema::dropIfExists('module_plan');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('modules');
    }
};
