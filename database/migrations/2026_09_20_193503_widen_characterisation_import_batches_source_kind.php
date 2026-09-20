<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A tabela original só conhecia três origens (`paste`, `csv`, `xlsx`,
 * criadas em 2026_11_11_000100_create_characterisation_tables). Este slice
 * acrescenta a extração de tabelas — HTML colado do Word/Excel/Google
 * Sheets, TSV colado, .docx e, reservados para uma fatia futura de imagem,
 * `pasted_image`/`image_upload` — e `source_kind` tem de aceitar os nomes
 * que `ExtractedTableSource::value` já usa, para os dois lados falarem o
 * mesmo vocabulário.
 *
 * ADITIVA, NÃO SUBSTITUTIVA: nenhuma linha existente muda de valor, e os
 * três valores antigos continuam válidos. `string(16)` já chegava para o
 * maior valor novo (`image_upload`, 12 carateres) mas o CHECK antigo
 * rejeitava-o — por isso só o CHECK muda, não o comprimento da coluna.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropCheck('characterisation_import_batches', 'characterisation_import_batches_source_kind_check');

        $this->addCheck(
            'characterisation_import_batches',
            'characterisation_import_batches_source_kind_check',
            "source_kind IN ('paste','csv','xlsx','pasted_html','pasted_tsv','docx','pasted_image','image_upload')",
        );
    }

    public function down(): void
    {
        $this->dropCheck('characterisation_import_batches', 'characterisation_import_batches_source_kind_check');

        $this->addCheck(
            'characterisation_import_batches',
            'characterisation_import_batches_source_kind_check',
            "source_kind IN ('paste','csv','xlsx')",
        );
    }

    /**
     * SQLite (os testes) e MySQL (a produção) não declaram CHECK da mesma
     * maneira, e o SQLite não o adiciona depois de a tabela existir. O mesmo
     * padrão que a migração original já usa.
     */
    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
    }

    protected function dropCheck(string $table, string $name): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
    }
};
