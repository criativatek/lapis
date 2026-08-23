<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_lesson_slots', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->index(
                ['organization_id', 'class_id', 'day_of_week', 'starts_on', 'ends_on'],
                'lesson_slots_org_class_weekday_dates_idx',
            );
        });

        $this->addCheck('recurring_lesson_slots', 'lesson_slots_weekday_check', 'day_of_week BETWEEN 1 AND 7');
        $this->addCheck('recurring_lesson_slots', 'lesson_slots_times_check', 'ends_at > starts_at');
        $this->addCheck(
            'recurring_lesson_slots',
            'lesson_slots_dates_check',
            'starts_on IS NULL OR ends_on IS NULL OR ends_on >= starts_on',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_lesson_slots');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
