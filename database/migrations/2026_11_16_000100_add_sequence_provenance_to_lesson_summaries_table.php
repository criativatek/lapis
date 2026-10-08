<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DE ONDE VEIO ESTE SUMÁRIO — a memória que faltava a «aplicar uma sequência».
 *
 * Sem ela, reaplicar uma sequência já aplicada voltava a emparelhar elementos e
 * aulas por posição, a partir de «agora», e duplicava ou deslocava o que lá
 * estava. Com ela, uma REAPLICAÇÃO explícita reconhece as suas próprias
 * colocações: o elemento X está na aula Y.
 *
 * Isto NÃO faz da sequência uma ligação viva. Editar uma sequência continua a
 * não chegar às aulas sozinho; a proveniência só é lida quando o professor pede
 * para aplicar de novo.
 *
 * `sequence_content_hash` é a impressão digital do conteúdo NO MOMENTO em que a
 * sequência o escreveu. Se o sumário de hoje já não bate com ela, o professor
 * mexeu-lhe, e uma reaplicação nunca o reescreve sem confirmação.
 *
 * As chaves são `nullOnDelete`: eliminar a sequência (ou um elemento) deixa o
 * sumário intacto e apenas sem proveniência. Sem backfill — o que foi aplicado
 * antes desta migração fica sem origem conhecida e é tratado como conteúdo do
 * professor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_summaries', function (Blueprint $table): void {
            $table->foreignId('lesson_sequence_id')->nullable()->constrained('lesson_sequences')->nullOnDelete();
            $table->foreignId('lesson_sequence_item_id')->nullable()->constrained('lesson_sequence_items')->nullOnDelete();
            $table->char('sequence_content_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lesson_summaries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lesson_sequence_item_id');
            $table->dropConstrainedForeignId('lesson_sequence_id');
            $table->dropColumn('sequence_content_hash');
        });
    }
};
