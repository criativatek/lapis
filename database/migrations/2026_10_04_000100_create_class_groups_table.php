<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Os grupos fixos em que uma turma se desdobra em certos tempos do horário —
 * «8.º F · T1», «8.º F · T2».
 *
 * A TURMA CONTINUA A SER UMA SÓ. Um grupo não é uma turma artificial: não tem
 * disciplina, não tem perfil de avaliação, não entra em nenhum cálculo. É uma
 * partição da pauta que só o horário e o sumário conhecem (§19 do briefing).
 *
 * OS NOMES SÃO LIVRES. T1/T2 é o caso comum, mas «A»/«B», «PL1»/«PL2» e
 * qualquer outro rótulo são igualmente válidos, e nenhum deles está escrito em
 * código — a unicidade é por turma, não global.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_groups', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->string('label', 40);
            $table->unsignedSmallInteger('position')->default(0);
            // Arquivar, nunca apagar, assim que o grupo tem história: um grupo
            // que já governou pertenças ou tempos do horário continua a ser
            // preciso para as ler (§24 do briefing).
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['class_id', 'label'], 'class_groups_class_label_unique');
            $table->index(['organization_id', 'class_id', 'position'], 'class_groups_org_class_position_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_groups');
    }
};
