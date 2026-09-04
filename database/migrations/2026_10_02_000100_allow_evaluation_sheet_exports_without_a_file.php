<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guardar uma pauta deixa de exigir um ficheiro.
 *
 * A tabela nasceu a servir UM caso — a exportação para o INOVAR — e por isso
 * `file_path`, `file_checksum` e `original_extension` eram NOT NULL. O registo
 * histórico de uma pauta é o documento congelado (`payload` + `payload_hash`),
 * e esse documento existe e vale por si mesmo sem que nada tenha sido
 * exportado. As três colunas passam a anuláveis; um registo com ficheiro
 * continua a preenchê-las exatamente como antes.
 *
 * `effective_at` é a DATA DE REFERÊNCIA DO MOMENTO — «a pauta tal como estava
 * a 15 de dezembro» — e não se confunde com `exported_at`, que é quando o
 * registo foi criado. Um professor pode guardar em janeiro a fotografia de um
 * momento de dezembro, e as duas datas contam coisas diferentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_sheet_exports', function (Blueprint $table): void {
            $table->string('file_path', 255)->nullable()->change();
            $table->char('file_checksum', 64)->nullable()->change();
            $table->string('original_extension', 8)->nullable()->change();
            $table->date('effective_at')->nullable()->after('moment_label');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_sheet_exports', function (Blueprint $table): void {
            $table->dropColumn('effective_at');
        });

        // Reverter para NOT NULL sem apagar história: os registos guardados sem
        // ficheiro ficam com marcadores vazios em vez de desaparecerem. Perder
        // uma pauta histórica para satisfazer uma restrição de esquema seria
        // trocar o essencial pelo acessório.
        DB::table('evaluation_sheet_exports')->whereNull('file_path')->update([
            'file_path' => '',
            'file_checksum' => '',
            'original_extension' => '',
        ]);

        Schema::table('evaluation_sheet_exports', function (Blueprint $table): void {
            $table->string('file_path', 255)->change();
            $table->char('file_checksum', 64)->change();
            $table->string('original_extension', 8)->change();
        });
    }
};
