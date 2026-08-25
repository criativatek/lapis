<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Acontecimentos» do Calendário do Ano Letivo (Fase 5.3) — a reunião, uma
 * atividade, uma visita de estudo, ou outra coisa datada que simplesmente não
 * tem casa nenhuma no resto da aplicação.
 *
 * THE FIRST TABLE THE CALENDAR OWNS, and it exists precisely because these four
 * things are the ones that have nowhere else to live: uma avaliação já é um
 * Instrument, a estrutura do ano já é um AcademicPeriod, e o horário já é um
 * RecurringLessonSlot. Nothing here duplicates any of the three, and deleting a
 * row here touches none of them.
 *
 * PERSONAL, NOT SHARED — exactly the shape `lesson_sequences` already
 * established: `organization_id` draws the tenant boundary, `user_id` is the
 * owning teacher, and CalendarEventPolicy is the only place that decides who
 * may see or change a row. This is deliberately NOT an institutional calendar:
 * a colleague never sees these, and that is a product decision for this first
 * version, not an omission.
 *
 * NO academic_year_id, ON PURPOSE. An acontecimento is purely dated, and the
 * calendar already narrows everything it reads to a [from, to] range anchored
 * to the selected year — the same way it already treats `Instrument`, which
 * likewise carries no «is this in the selected year» flag, only `applied_on`.
 * A column added merely for symmetry with a pattern that does not need it would
 * be one more thing to keep true.
 *
 * `ends_on` IS NOT NULL and always carries a real date. The product spec says
 * «data final opcional; conceptualmente igual à inicial se ausente», and the
 * cleanest reading of «conceptually equal» is to write it down once, in
 * SaveCalendarEvent, rather than to store a null and oblige every read site to
 * repeat a `?? starts_on` fallback that one of them would eventually forget.
 * The API stays optional; the column stays honest. The CHECK below is what
 * guarantees the pair can never be stored inverted.
 *
 * `starts_at`/`ends_at` ARE nullable, and their absence is real information:
 * an acontecimento with no hour is «dia inteiro», not one whose hour is unknown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 16);
            $table->string('title', 200);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            // The one query this table exists to answer: «os acontecimentos
            // deste professor que cruzam este intervalo de dias».
            $table->index(
                ['organization_id', 'user_id', 'starts_on', 'ends_on'],
                'calendar_events_org_user_dates_idx',
            );
        });

        // A CLOSED SET OF FOUR, and the database says so too. The same
        // MySQL-only idiom `recurring_lesson_slots` already uses for its own
        // invariants — SQLite, where the suite runs, ignores it, so the enum
        // cast and the Form Request remain the enforcement that is always on.
        $this->addCheck(
            'calendar_events',
            'calendar_events_type_check',
            "type IN ('meeting','activity','field_trip','other')",
        );
        $this->addCheck('calendar_events', 'calendar_events_dates_check', 'ends_on >= starts_on');
        $this->addCheck(
            'calendar_events',
            'calendar_events_times_check',
            'ends_at IS NULL OR (starts_at IS NOT NULL AND ends_at > starts_at)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
