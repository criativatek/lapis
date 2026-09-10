<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ARQUIVAR, NUNCA APAGAR, é o gesto por omissão para uma turma — o mesmo que
 * `ClassGroup.archived_at` já é para um grupo. Uma coluna própria, ortogonal
 * ao `status` existente: o `status` diz em que fase pedagógica a turma está
 * (preparação, ativa, encerrada), e arquivar é uma decisão administrativa
 * diferente — uma turma pode ficar arquivada em qualquer status. Restaurar
 * não tem de adivinhar a que status volta: só anula esta coluna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('status');
            $table->index(['organization_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
