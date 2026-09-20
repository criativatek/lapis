<?php

namespace Tests\Unit\Characterisation\Extraction;

use App\Services\Characterisation\Import\Extraction\ExtractedTableSource;
use App\Services\Characterisation\Import\Extraction\TsvTableExtractor;
use Tests\TestCase;

class TsvTableExtractorTest extends TestCase
{
    private function extractor(): TsvTableExtractor
    {
        return new TsvTableExtractor;
    }

    public function test_empty_intermediate_columns_survive(): void
    {
        $tables = $this->extractor()->extract(
            "Nome\tMedidas\tObservações\n".
            "Ana Silva\t\tParticipa\n"
        );

        $this->assertCount(1, $tables);
        $cells = $tables[0]->rows[1]->cells;
        $this->assertSame('Ana Silva', $cells[0]->text);
        $this->assertSame('', $cells[1]->text);
        $this->assertSame('Participa', $cells[2]->text);
    }

    /**
     * A quoted field carrying an embedded newline is one cell, not two rows —
     * str_getcsv already understands quoting, but the extractor must feed it
     * the whole field, not split first on "\n" and corrupt it.
     */
    public function test_a_multiline_quoted_cell_is_read_as_one_cell(): void
    {
        $tables = $this->extractor()->extract(
            "Nome\tObservações\n".
            "Ana Silva\t\"Participa.\nFalta pouco.\"\n"
        );

        $this->assertCount(1, $tables);
        $this->assertSame(ExtractedTableSource::PastedTsv, $tables[0]->sourceType);

        // Exactly two rows — the quoted newline did not become a third row.
        $this->assertCount(2, $tables[0]->rows);
        $this->assertSame("Participa.\nFalta pouco.", $tables[0]->rows[1]->cells[1]->text);
    }

    public function test_it_returns_nothing_for_blank_input(): void
    {
        $this->assertSame([], $this->extractor()->extract('   '));
    }
}
