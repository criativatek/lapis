<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A assiduidade de uma aula — RASCUNHO antes de lecionada, INSTANTÂNEO
 * consolidado depois.
 *
 * `lessons.attendance_recorded_at` NULL é o estado por omissão de TODA A
 * HISTÓRIA anterior a esta fatia, e não só das aulas novas: nenhuma migração
 * de dados o define, porque não há nada a inferir — «assiduidade não
 * registada» é a leitura correta de uma aula lecionada antes de esta
 * funcionalidade existir, nunca «toda a gente esteve presente».
 *
 * ANTES DA CONSOLIDAÇÃO só existem linhas `absent`: um rascunho de faltas que
 * o professor vai ajustando enquanto a aula ainda não é lecionada. Nunca uma
 * linha `present` nesse estado — presença não registada é a ausência da
 * linha, não um valor. DEPOIS DA CONSOLIDAÇÃO (MarkLessonAsTaught /
 * RecordLessonAttendance, mesmo lock) a linha vira o INSTANTÂNEO: cada aluno
 * elegível nesse dia tem exatamente uma linha, `absent` ou `present`, e essa
 * fotografia nunca é recalculada a partir do roster — só CorrectLessonAttendance
 * a reescreve, uma linha de cada vez, com auditoria.
 *
 * `enrollment_id` E `student_id`, os dois: a inscrição é o eixo do domínio de
 * avaliação (§11.4 do domain model) e é por ela que StudentAttendanceHistory
 * lê o histórico por matrícula; `student_id` fica ao lado porque um índice por
 * (organização, aluno) sem juntar a `enrollments` é o que a fatia de relatórios
 * usa para uma pesquisa rápida. RESTRICT nos dois — apagar uma inscrição ou um
 * aluno com assiduidade registada apagaria história pedagógica, exatamente
 * como as outras chaves que EnrollmentHistory já protege.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->timestamp('attendance_recorded_at')->nullable()->after('status');
            $table->foreignId('attendance_recorded_by')->nullable()->after('attendance_recorded_at')
                ->constrained('users')->nullOnDelete();
        });

        Schema::create('lesson_attendances', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // cascade: eliminar a aula (só possível enquanto não é lecionada —
            // ver DeleteLesson) elimina o rascunho de faltas com ela. Não há
            // aqui história a preservar fora da própria aula.
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->string('status', 16);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['lesson_id', 'enrollment_id'], 'lesson_attendances_lesson_enrollment_unique');
            $table->index(['organization_id', 'student_id'], 'lesson_attendances_org_student_idx');
            $table->index('enrollment_id', 'lesson_attendances_enrollment_idx');
        });

        $this->addCheck(
            'lesson_attendances',
            'lesson_attendances_status_check',
            "status IN ('present','absent')",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_attendances');

        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('attendance_recorded_by');
            $table->dropColumn('attendance_recorded_at');
        });
    }

    /**
     * SQLite (os testes) não declara CHECK depois de a tabela existir; MySQL
     * (produção e CI) sim. O mesmo padrão que as outras migrations desta base
     * de código usam.
     */
    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }
};
