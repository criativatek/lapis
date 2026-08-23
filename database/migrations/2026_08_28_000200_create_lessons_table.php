<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('recurring_lesson_slot_id')->nullable()
                ->constrained('recurring_lesson_slots')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('status', 16)->default('preparation');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['class_id', 'recurring_lesson_slot_id', 'starts_at'],
                'lessons_class_slot_start_unique',
            );
            $table->index(['organization_id', 'class_id', 'starts_at'], 'lessons_org_class_start_idx');
            $table->index(['organization_id', 'starts_at'], 'lessons_org_start_idx');
        });

        $this->addCheck('lessons', 'lessons_status_check', "status IN ('preparation','prepared','taught')");
        $this->addCheck('lessons', 'lessons_times_check', 'ends_at IS NULL OR ends_at > starts_at');
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
