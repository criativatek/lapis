<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O acompanhamento dos registos à 3.ª ocorrência (spec
 * 2026-10-12-student-records-followup-design).
 *
 * `record_followup_cases` — UMA linha por chave (turma × inscrição × período ×
 * categoria). Nasce quando uma operação faz a contagem passar de < 3 para ≥ 3
 * (`alerted_at`/`alerted_count`: o alerta) ou quando o professor confirma um
 * caso que já vinha de antes desta versão (`alerted_at` nulo). A contagem NÃO
 * é guardada aqui: lê-se sempre ao vivo dos registos, para que uma correção ou
 * uma eliminação se reflita sem nada a sincronizar. O índice único é o que
 * impede um segundo alerta para a mesma chave, também com dois pedidos ao
 * mesmo tempo.
 *
 * `record_followup_confirmations` — a declaração do professor de que registou
 * a ocorrência no Inovar: quem, quando e QUAIS registos abrangeu (`occurrences`
 * = lista de {ulid, date}). Desfazer não apaga: marca `undone_at`/`undone_by`,
 * e a história fica. A aplicação nunca verifica o Inovar.
 *
 * Chaves estrangeiras RESTRICT para a turma, a inscrição e o período, como os
 * próprios registos: um caso é história pedagógica, e as listas de
 * SchoolClassHistory / EnrollmentHistory / AcademicYearService passam a
 * conhecê-lo, para dizer «não» com uma frase e não com um erro da base.
 *
 * `evidence_followup_idx` serve as contagens agrupadas (turma × tipo × data).
 *
 * Sem backfill: nenhum registo existente é tocado e nenhum alerta nasce para
 * os casos que já tinham três ou mais ocorrências.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_followup_cases', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_period_id')->constrained()->restrictOnDelete();
            $table->string('category', 24);
            $table->dateTime('alerted_at')->nullable();
            $table->unsignedSmallInteger('alerted_count')->nullable();
            $table->timestamps();

            $table->unique(['class_id', 'enrollment_id', 'academic_period_id', 'category'], 'record_followup_cases_key_unique');
            $table->index(['organization_id', 'class_id', 'alerted_at'], 'record_followup_cases_alerts_idx');
        });

        $this->addCheck('record_followup_cases', 'record_followup_cases_category_check', "category IN ('homework_not_done','lateness','missing_material')");

        Schema::create('record_followup_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('record_followup_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at');
            $table->unsignedSmallInteger('occurrence_count');
            $table->json('occurrences');
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('undone_at')->nullable();
            $table->timestamps();

            $table->index(['record_followup_case_id', 'undone_at'], 'record_followup_confirmations_case_idx');
        });

        Schema::table('evidence_records', function (Blueprint $table): void {
            $table->index(['organization_id', 'class_id', 'kind', 'occurred_at'], 'evidence_followup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('evidence_records', function (Blueprint $table): void {
            $table->dropIndex('evidence_followup_idx');
        });

        Schema::dropIfExists('record_followup_confirmations');
        Schema::dropIfExists('record_followup_cases');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
