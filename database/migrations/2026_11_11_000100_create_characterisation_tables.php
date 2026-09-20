<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A caracterização pedagógica (docs/superpowers/specs/2026-09-20-class-pedagogical-characterisation.md):
 * o que um professor sabe sobre uma turma e, sobretudo, sobre cada aluno dela,
 * para além do que a avaliação e as intervenções formais já guardam.
 *
 * Cinco tabelas, cada uma com uma razão de existir separada:
 *
 *  - `class_characterisations` — 1:1 com a turma, um único `summary`. O
 *    acessório: nunca substitui a caracterização por aluno.
 *  - `enrollment_characterisations` — 1:1 com a INSCRIÇÃO, nunca com o aluno
 *    (§2.1 do desenho): o facto pertence ao par turma/aluno, e ancorar aqui dá
 *    de graça o âmbito do ano letivo.
 *  - `characterisation_import_batches` — proveniência de uma importação
 *    confirmada. Não guarda o ficheiro nem as linhas rejeitadas (§4.5):
 *    guarda só «de onde veio isto, quando e por quem».
 *  - `characterisation_revisions` — histórico polimórfico, no mesmo molde de
 *    `audit_events`: escrito uma vez, nunca atualizado, e só com o valor
 *    anterior das secções que mudaram (não uma fotografia completa).
 *  - `enrollment_characterisation_source_measures` — o destino (B) da
 *    importação (§4.4): pares (nível, código) tipados que o resolvedor de
 *    siglas reconheceu, com o token original ao lado. `CASCADE` para a
 *    caracterização porque vive inteiramente dentro desse agregado e não tem
 *    história própria — a história de uma importação é a revisão, não a
 *    medida em si.
 *
 * Ordem de criação e de remoção espelham a dependência: os batches de
 * importação existem antes de qualquer coisa que os referencie, e o down()
 * desfaz-se na ordem inversa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_characterisations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            // O índice único é declarado à parte, e tem de ser: `->unique()`
            // encadeado num `foreignId()->constrained()` aplica-se ao
            // ForeignKeyDefinition e não à coluna, pelo que não cria índice
            // nenhum — a turma passaria a poder ter duas caracterizações sem
            // que nada se queixasse.
            $table->unique('class_id', 'class_characterisations_class_unique');
            $table->text('summary')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('last_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('enrollment_characterisations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            // Ver a nota em `class_characterisations`: o único tem de ser
            // declarado à parte para existir de facto.
            $table->unique('enrollment_id', 'enrollment_characterisations_enrollment_unique');
            // As quatro do meio são deliberadamente as palavras que a proposta
            // de educação inclusiva usa (§2.1) — reutilizáveis por um futuro
            // instrumento legal, sem ser em si um PDI nem documento legal.
            $table->text('summary')->nullable();
            $table->text('strengths')->nullable();
            $table->text('interests')->nullable();
            $table->text('needs')->nullable();
            $table->text('barriers')->nullable();
            $table->text('participation')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('last_updated_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'enrollment_id'], 'enrollment_characterisations_org_enrollment_idx');
        });

        Schema::create('characterisation_import_batches', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->string('source_kind', 16);
            // Nulo quando a origem é texto colado (§4.1) — não há ficheiro nenhum.
            $table->string('original_filename', 255)->nullable();
            $table->unsignedSmallInteger('row_count');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at');
            $table->timestamps();

            $table->index(['organization_id', 'class_id'], 'characterisation_import_batches_org_class_idx');
        });

        Schema::create('characterisation_revisions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // Polimórfico como audit_events: aponta a ClassCharacterisation ou
            // EnrollmentCharacterisation. Sem FK, de propósito — o mesmo
            // padrão do subject_* de AuditEvent.
            $table->string('characterisable_type', 255);
            $table->unsignedBigInteger('characterisable_id');
            // Só as secções que mudaram, e só o texto que lá estava — nunca
            // uma fotografia completa (§2.3).
            $table->json('changed_sections');
            $table->json('previous_values');
            $table->foreignId('author_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('source', 16);
            $table->foreignId('import_batch_id')->nullable()
                ->constrained('characterisation_import_batches')->restrictOnDelete();
            $table->dateTime('created_at'); // Sem updated_at: uma revisão não se edita.

            $table->index(
                ['organization_id', 'characterisable_type', 'characterisable_id', 'created_at'],
                'characterisation_revisions_subject_idx',
            );
        });

        Schema::create('enrollment_characterisation_source_measures', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // Nomes de FK explícitos em toda esta tabela: o nome da tabela já
            // é longo, e o identificador automático do Laravel excederia o
            // limite de 64 carateres do MySQL.
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'ecsm_organization_fk')->restrictOnDelete();
            // Cascade: esta linha vive inteiramente dentro do agregado da
            // caracterização da inscrição e não tem história própria — a
            // proveniência fica na revisão, não aqui.
            $table->foreignId('enrollment_characterisation_id')
                ->constrained('enrollment_characterisations', 'id', 'ecsm_enrollment_characterisation_fk')
                ->cascadeOnDelete();
            // Nulo quando o resolvedor só reconheceu o nível, ou só o código,
            // ou nem isso — uma anotação por resolver ainda assim chega aqui
            // (§3.4).
            $table->string('support_measure_level', 32)->nullable();
            $table->string('support_measure_code', 64)->nullable();
            $table->string('raw_token', 255);
            $table->json('unresolved_annotations')->nullable();
            $table->foreignId('import_batch_id')->nullable()
                ->constrained('characterisation_import_batches', 'id', 'ecsm_import_batch_fk')
                ->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()
                ->constrained('users', 'id', 'ecsm_confirmed_by_fk')->restrictOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'enrollment_characterisation_id'], 'ecsm_org_characterisation_idx');
        });

        $this->addCheck(
            'characterisation_import_batches',
            'characterisation_import_batches_source_kind_check',
            "source_kind IN ('paste','csv','xlsx')",
        );
        $this->addCheck(
            'characterisation_revisions',
            'characterisation_revisions_source_check',
            "source IN ('manual','import')",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_characterisation_source_measures');
        Schema::dropIfExists('characterisation_revisions');
        Schema::dropIfExists('characterisation_import_batches');
        Schema::dropIfExists('enrollment_characterisations');
        Schema::dropIfExists('class_characterisations');
    }

    /**
     * SQLite (os testes) e MySQL (a produção) não declaram CHECK da mesma
     * maneira, e o SQLite não o adiciona depois de a tabela existir. O mesmo
     * padrão que `create_domain_appreciation_decisions_table` já usa.
     */
    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
    }
};
