<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UMA CLASSIFICAÇÃO OBTIDA NOUTRO SÍTIO — para que nunca seja confundida com
 * uma que este sistema calculou.
 *
 * Existe para isso e só para isso. NUNCA é escrita em `student_overall_results`,
 * `student_domain_results` ou `calculation_snapshots`: essas três tabelas são o
 * resultado do motor de cálculo desta aplicação (docs/domain-model.md, cadeia
 * REGRA → RECOLHA → RESULTADO), e uma nota de PLNM que vem de outra escola, de
 * um exame externo, ou de qualquer origem que não seja o nosso motor, não
 * percorreu essa cadeia. Não tem critérios, não tem instrumentos, não tem
 * domínios e não tem evidência — e nada nesta tabela pode alguma vez sugerir
 * que tem.
 *
 * `origin` É TEXTO LIVRE (VARCHAR), NÃO UM ENUM. Pela mesma razão que
 * `subject_participations.reason_detail`: de onde vem uma nota externa é
 * currículo e prática de cada escola, e cada nova origem não pode exigir uma
 * migração.
 *
 * `period_id` É ANULÁVEL — um resultado externo pode ser do ano completo, sem
 * se prender a um período do perfil de avaliação em vigor. E É AQUI QUE A
 * UNIQUE NÃO CHEGA: o MySQL trata cada `NULL` como distinto, por isso
 * `UNIQUE(enrollment_id, period_id)` NÃO impede duas linhas de ano completo
 * (`period_id = NULL`) para a mesma inscrição — a mesma armadilha que
 * `enrollments` documenta para `UNIQUE(class_id, student_id)` com `left_on`
 * anulável (Q9, docs/domain-model.md). A ação
 * (App\Actions\SubjectParticipation\RecordExternalSubjectResult) verifica essa
 * linha explicitamente antes de escrever; a UNIQUE só cobre os períodos
 * concretos.
 *
 * PELO MENOS UM VALOR. Uma linha sem `scale_level_id`, sem `level_code` e sem
 * `numeric_value` não diz nada que a ausência de linha não diga melhor — a
 * mesma razão que `domain_appreciation_decisions.scale_level_id` é NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_subject_results', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // RESTRICT: um resultado externo é história pedagógica registada
            // sobre a inscrição, tal como qualquer outra em
            // App\Services\EnrollmentHistory — não desaparece porque a
            // inscrição foi apagada.
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('profile_version_periods')->restrictOnDelete();
            $table->string('origin', 64);
            $table->foreignId('scale_level_id')->nullable()->constrained('scale_levels')->restrictOnDelete();
            $table->string('level_code', 16)->nullable();
            $table->decimal('numeric_value', 6, 3)->nullable();
            $table->date('recorded_on');
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(
                ['enrollment_id', 'period_id'],
                'external_subject_results_enrollment_period_unique',
            );
            $table->index(['organization_id', 'enrollment_id']);
        });

        $this->addCheck(
            'external_subject_results',
            'external_subject_results_value_present_check',
            'scale_level_id IS NOT NULL OR level_code IS NOT NULL OR numeric_value IS NOT NULL',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('external_subject_results');
    }

    /**
     * SQLite (os testes) não declara CHECK constraints por ALTER TABLE — só o
     * MySQL/MariaDB de produção o fazem aqui. O mesmo padrão que
     * `class_group_memberships` e `subject_participations` já usam.
     */
    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
