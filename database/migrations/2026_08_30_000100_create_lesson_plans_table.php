<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_plans', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_id')->unique()->constrained()->restrictOnDelete();
            $table->text('planned_summary');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'lesson_id'], 'lesson_plans_org_lesson_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_plans');
    }
};
