<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registos por sessão das «Outras atividades» do horário (0.170.0): o sumário
 * de cada apoio, reunião, clube ou direção de turma numa data concreta.
 *
 * NUNCA SE CHAMA `sessions`: a anonimização de uma conta apaga a tabela do
 * Laravel com esse nome.
 *
 * UMA SESSÃO SÓ EXISTE QUANDO O PROFESSOR GUARDA. Ver o horário ou a semana
 * nunca escreve; esta migração não converte nenhuma atividade existente em
 * sessões nem inventa sumários passados (sem backfill).
 *
 * `timetable_activity_id` é `nullOnDelete`: nunca CASCADE (apagar o bloco
 * apagaria sumários) e nunca RESTRICT (partiria a eliminação da conta e a
 * anonimização). Por isso a sessão leva uma CÓPIA do que a identifica
 * (`activity_kind`, `activity_title`, `class_id`, as horas): continua
 * identificável e legível se o bloco desaparecer ou for alterado. O professor
 * e a organização são CASCADE, como a atividade — é escrita pessoal e não
 * bloqueia a eliminação da conta.
 *
 * `class_id` e `academic_year_id` são referências informativas (nullOnDelete).
 * O ano letivo é o da turma, se houver; senão o da organização que contém a
 * data. É a fronteira da numeração opcional.
 *
 * UNIQUE (atividade, data): um pedido repetido, um duplo clique ou duas abas
 * nunca duplicam a sessão. Em MySQL as linhas órfãs (atividade NULL) não
 * colidem entre si, o que é o pretendido: a data já não pertence a um bloco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_activity_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timetable_activity_id')->nullable()->constrained('timetable_activities')->nullOnDelete();
            $table->string('activity_kind', 32);
            $table->string('activity_title', 60);
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->date('occurs_on');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->text('summary')->nullable();
            $table->string('status', 16)->default('planned');
            $table->unsignedInteger('session_number')->nullable();
            $table->timestamps();

            $table->unique(['timetable_activity_id', 'occurs_on'], 'timetable_activity_sessions_activity_date_unique');
            $table->index(['organization_id', 'user_id', 'occurs_on'], 'timetable_activity_sessions_owner_date_idx');
        });

        $this->addCheck('timetable_activity_sessions', 'timetable_activity_sessions_status_check', "status IN ('planned','done','not_held')");
        $this->addCheck('timetable_activity_sessions', 'timetable_activity_sessions_times_check', 'ends_at > starts_at');
        $this->addCheck('timetable_activity_sessions', 'timetable_activity_sessions_kind_check', "activity_kind IN ('class_direction','student_support','meeting','coordination','club','other')");
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_activity_sessions');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
