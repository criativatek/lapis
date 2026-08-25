<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Exceções letivas» (Fase 5.4) — feriados, interrupções letivas e dias não
 * letivos: as datas em que a aula NÃO acontece.
 *
 * ESTRUTURA, AO LADO DE `academic_periods`, E NÃO AO LADO DE `calendar_events`.
 * A forma desta tabela é deliberadamente a de `academic_periods` e não a de
 * `calendar_events`, porque é isso que ela é: `organization_id` e
 * `academic_year_id`, e NENHUM `user_id`. Um feriado não é de um professor —
 * é do ano letivo inteiro, tal como um semestre — e por isso não há aqui dono
 * nenhum a decidir quem o vê. Quem edita os períodos edita isto, sob a mesma
 * AcademicYearPolicy, no mesmo formulário.
 *
 * `academic_year_id` É `restrictOnDelete()`, exatamente como o de
 * `academic_periods`: a história estrutural de um ano nunca desaparece por
 * arrasto ao apagar o ano — quem apaga tem de dizer explicitamente que também
 * apaga isto (AcademicYearController::destroy faz-lo, como já fazia aos
 * períodos).
 *
 * `title` TEM 200, como o de `calendar_events` e não os 64 do `label` de um
 * período: uma exceção recebe designações livres e compridas — «Interrupção
 * letiva do Natal», «5 de outubro — Implantação da República» — e não o nome
 * curto e regular de um semestre.
 *
 * `ends_on >= starts_on`, E NÃO `>`: uma exceção de um dia só é legítima e é a
 * mais comum de todas (um feriado), o que a distingue de um período, cuja
 * própria CHECK exige `ends_on > starts_on`. É a mesma comparação — e a mesma
 * convenção de «um dia só é starts_on === ends_on» — que `calendar_events` já
 * usa.
 *
 * `source` É UMA PALAVRA E MAIS NADA (§14): «manual», «suggested», «imported».
 * Sem FK para um lote de importação e sem rasto de auditoria — nesta fase tudo
 * o que se escreve é «manual», e os outros dois existem só para que as linhas
 * de hoje já sejam distinguíveis das que a sugestão de feriados e a importação
 * de PDF/Excel virão a escrever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_calendar_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->string('title', 200);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->text('note')->nullable();
            $table->string('source', 16);
            $table->timestamps();

            // A única pergunta que esta tabela existe para responder: «as
            // exceções deste ano letivo que cruzam este intervalo de dias».
            // Nomeado explicitamente — o nome automático passa dos 64 caracteres
            // que o MySQL permite, o mesmo cuidado que `academic_periods` já
            // teve de ter com o seu.
            $table->index(
                ['organization_id', 'academic_year_id', 'starts_on', 'ends_on'],
                'academic_calendar_exceptions_org_year_dates_idx',
            );
        });

        // Enforced in the database as defense-in-depth, on top of the Form
        // Request validation. ADD CONSTRAINT is a no-op on SQLite (the test
        // driver), so these guards are asserted by CI, which runs on MySQL —
        // exactly the split ADR-0001 documents, and the same idiom
        // `calendar_events` and `academic_periods` already use.
        $this->addCheck(
            'academic_calendar_exceptions',
            'academic_calendar_exceptions_type_check',
            "type IN ('holiday','school_break','non_teaching_day')",
        );
        $this->addCheck(
            'academic_calendar_exceptions',
            'academic_calendar_exceptions_source_check',
            "source IN ('manual','suggested','imported')",
        );
        // `>=` e não `>`: ver o cabeçalho — um feriado de um dia é o caso mais
        // comum desta tabela, ao contrário de um período.
        $this->addCheck(
            'academic_calendar_exceptions',
            'academic_calendar_exceptions_dates_check',
            'ends_on >= starts_on',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_calendar_exceptions');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
