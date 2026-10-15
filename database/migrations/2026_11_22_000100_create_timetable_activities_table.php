<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Atividades não letivas do horário de um professor (0.168.0): direção de
 * turma, apoio a alunos, reuniões, coordenação.
 *
 * TABELA PRÓPRIA, DE PROPÓSITO. `recurring_lesson_slots` produz aulas — é o
 * único sítio de onde `MaterializeLessonsForRange` as faz nascer — e uma
 * atividade não letiva não é uma aula: não tem sumário, assiduidade nem
 * número. Metê-la ali obrigaria cada leitor do horário a saber distingui-la.
 * Aqui nada lê estas linhas para criar aulas, numerar ou sumariar.
 *
 * É privada de UM professor (`user_id`): o horário de cada pessoa é seu. Por
 * isso `user_id` e `organization_id` são CASCADE — uma configuração pessoal
 * não pode bloquear a eliminação da conta nem da organização, ao contrário
 * do conteúdo pedagógico. `class_id` é só uma referência informativa
 * (a «DT 6.º D» aponta para a 6.º D): NULL ON DELETE, nunca bloqueia nada.
 *
 * Sem `academic_year_id`: o horário não é por ano letivo; a vigência
 * (`starts_on`/`ends_on`, NULL = aberto) é a mesma regra dos tempos de aula.
 *
 * Sem backfill: não toca em nenhuma linha existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_activities', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('title', 60);
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->index(
                ['organization_id', 'user_id', 'day_of_week'],
                'timetable_activities_org_user_weekday_idx',
            );
        });

        $this->addCheck('timetable_activities', 'timetable_activities_kind_check', "kind IN ('class_direction','student_support','meeting','coordination','other')");
        $this->addCheck('timetable_activities', 'timetable_activities_weekday_check', 'day_of_week BETWEEN 1 AND 7');
        $this->addCheck('timetable_activities', 'timetable_activities_times_check', 'ends_at > starts_at');
        $this->addCheck(
            'timetable_activities',
            'timetable_activities_dates_check',
            'starts_on IS NULL OR ends_on IS NULL OR ends_on >= starts_on',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_activities');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
