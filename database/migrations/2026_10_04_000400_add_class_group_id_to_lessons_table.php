<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O grupo com que esta aula NASCEU — um instantâneo, não uma leitura.
 *
 * Copiado do tempo do horário no momento da materialização e nunca mais
 * tocado. É por isso que mudar o slot de «turma inteira» para T1 em janeiro
 * não transforma retroativamente as aulas de novembro (§13 e §23 do briefing):
 * elas guardam o que na altura era verdade, na sua própria linha.
 *
 * DELIBERADAMENTE FORA DA CHAVE `lessons_class_slot_start_unique`. A
 * identidade de uma aula continua a ser (turma, tempo do horário, início): o
 * grupo é uma consequência do tempo, não parte da sua identidade, e metê-lo na
 * chave faria uma revisão do slot criar uma segunda aula em cima da que já
 * existe naquele instante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->foreignId('class_group_id')->nullable()->after('class_id')
                ->constrained('class_groups')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('class_group_id');
        });
    }
};
