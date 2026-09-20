<?php

namespace Tests\Feature\Characterisation;

use Tests\TestCase;

/**
 * Regressão para F1: o Laravel corre as migrations por ordem lexicográfica
 * do NOME DO FICHEIRO, não pela ordem em que foram escritas. A migration que
 * alarga o CHECK de `source_kind` tem de ordenar DEPOIS da migration que cria
 * a tabela `characterisation_import_batches` (que reaplica o CHECK antigo no
 * fim do seu up()) — caso contrário o alargamento corre contra uma tabela que
 * ainda não existe e o CHECK final fica com os três valores antigos.
 *
 * Isto já aconteceu: a migration de alargamento nasceu com o timestamp
 * 2026_09_20_193503, que ordena ANTES de 2026_11_11_000100 (a que cria a
 * tabela). Este teste garante que não volta a acontecer.
 */
class CharacterisationImportBatchSourceKindMigrationOrderTest extends TestCase
{
    public function test_widen_source_kind_migration_sorts_after_create_tables_migration(): void
    {
        $files = collect(glob(database_path('migrations/*.php')))
            ->map(fn (string $path) => basename($path))
            ->values();

        $createTables = $files->first(fn (string $name) => str_contains($name, 'create_characterisation_tables'));
        $widenSourceKind = $files->first(fn (string $name) => str_contains($name, 'widen_characterisation_import_batches_source_kind'));

        $this->assertNotNull($createTables, 'Migration que cria characterisation_import_batches não foi encontrada.');
        $this->assertNotNull($widenSourceKind, 'Migration que alarga o source_kind não foi encontrada.');

        $this->assertGreaterThan(
            0,
            strcmp($widenSourceKind, $createTables),
            "A migration '{$widenSourceKind}' tem de ordenar DEPOIS de '{$createTables}' para o CHECK alargado sobreviver.",
        );
    }
}
