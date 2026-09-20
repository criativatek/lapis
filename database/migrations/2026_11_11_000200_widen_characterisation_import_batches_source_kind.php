<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
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
 *
 * ORDEM DO NOME DO FICHEIRO: tem de ordenar DEPOIS de
 * 2026_11_11_000100_create_characterisation_tables (que cria a tabela e
 * já reaplica o CHECK antigo no fim do seu up()). O Laravel corre as
 * migrations por ordem lexicográfica do nome do ficheiro, não pela data de
 * commit — um timestamp mais antigo aqui faria este alargamento correr
 * antes de a tabela existir, e o CHECK final ficaria com os três valores
 * antigos em vez dos alargados. Já aconteceu (ver histórico deste ficheiro,
 * que nasceu como 2026_09_20_193503 e foi renomeado por causa disto).
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
     * SQLite (os testes) e MySQL/MariaDB (a produção) não declaram CHECK da
     * mesma maneira, e o SQLite não o adiciona depois de a tabela existir.
     * Mesmo gate usado em 2026_09_20_000500_add_origin_to_interventions.
     */
    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    protected function dropCheck(string $table, string $name): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $clause = $driver === 'mariadb' ? 'DROP CONSTRAINT' : 'DROP CHECK';

        try {
            DB::statement("ALTER TABLE `{$table}` {$clause} `{$name}`");
        } catch (QueryException $exception) {
            if (! str_contains($exception->getMessage(), $name)) {
                throw $exception;
            }
        }
    }
};
