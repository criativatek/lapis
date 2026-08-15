<?php

namespace App\Services\Import\Tabular;

use App\Domain\Import\Tabular\TabularCell;
use App\Domain\Import\Tabular\TabularNumber;
use App\Domain\Import\Tabular\TabularSheet;
use App\Domain\Import\Tabular\TabularSourceSnapshot;

/**
 * A delimited text file, read as one sheet.
 *
 * Three things a CSV does not tell you, and how each is answered:
 *
 *  - WHICH DELIMITER. Answered by measurement, not by preference. Every
 *    candidate is parsed and the one that produces a consistent number of fields
 *    across the sampled lines wins; nothing is assumed about commas because a
 *    Portuguese export separated by semicolons is at least as common (§26).
 *  - WHICH ENCODING. Answered by refusing to guess. UTF-8 is verified, a BOM is
 *    stripped, and anything else is refused with instructions — because the
 *    difference between Windows-1252 and UTF-8 shows up as a mangled accent in a
 *    child's name, and silently choosing wrong is worse than asking.
 *  - WHERE THE DECIMAL POINT IS. Answered after the delimiter is known, which is
 *    the only order in which «12,5» is not also two fields (§25).
 *
 * A CSV is text and stays text. A field beginning with `=` is flagged as a
 * formula and refused as a mark, not because this reader would evaluate it — it
 * has no evaluator — but because a spreadsheet that exported `=B2*2` into a CSV
 * did not export a value, and importing the literal string as a number is a
 * silent corruption (§26, §28).
 */
class CsvTabularReader implements TabularReader
{
    /** The single sheet a CSV has. Named, not numbered, so the mapping can name it. */
    public const SHEET = 'Folha 1';

    /** Tried in this order; ties are impossible in practice and refused if they happen. */
    protected const DELIMITERS = [';', ',', "\t", '|'];

    protected const MAX_ROWS = 2000;

    protected const MAX_COLUMNS = 200;

    /** Lines sampled to decide the delimiter. Enough to be sure, cheap on a big file. */
    protected const SAMPLE_LINES = 20;

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        // Just `.csv`. `.txt` is a common alias, but every extension added here
        // widens the upload allowlist for every source, and the point of the
        // allowlist is that it widens exactly when something is supported (§8).
        return ['csv'];
    }

    /**
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        // A CSV of plain ASCII is detected as text/plain, not text/csv, so both
        // are accepted — the same reasoning the Plickers upload rule already
        // carries. The extension is the claim; this is the evidence.
        return ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
    }

    public function supports(string $absolutePath, string $originalFilename): bool
    {
        if (! in_array(strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION)), $this->extensions(), true)) {
            return false;
        }

        $contents = @file_get_contents($absolutePath, false, null, 0, 4096);

        if (! is_string($contents) || trim($contents) === '') {
            return false;
        }

        // A zip signature behind a .csv name is not a CSV, whatever it claims.
        return ! str_starts_with($contents, "PK\x03\x04");
    }

    public function read(string $absolutePath): TabularSourceSnapshot
    {
        $contents = @file_get_contents($absolutePath);

        if (! is_string($contents) || trim($contents) === '') {
            throw new UnreadableSpreadsheet(__('O ficheiro está vazio.'));
        }

        $contents = $this->withoutByteOrderMark($contents, $hadBom);

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new UnreadableSpreadsheet(__('O ficheiro não está em UTF-8. Volte a guardá-lo como CSV UTF-8 e tente de novo — a codificação não é adivinhada, para não trocar os acentos dos nomes.'));
        }

        $delimiter = $this->detectDelimiter($contents);

        if ($delimiter === null) {
            throw new UnreadableSpreadsheet(__('Não foi possível perceber como as colunas estão separadas. Guarde o ficheiro como CSV separado por ponto e vírgula ou por vírgula.'));
        }

        $rows = $this->rows($contents, $delimiter);

        if ($rows === []) {
            throw new UnreadableSpreadsheet(__('O ficheiro não tem linhas com dados.'));
        }

        $columnCount = 0;

        foreach ($rows as $row) {
            $columnCount = max($columnCount, count($row));
        }

        return new TabularSourceSnapshot(
            sheets: [new TabularSheet(
                name: self::SHEET,
                rows: $this->padded($rows, $columnCount),
                columnCount: $columnCount,
            )],
            // How it was read, and nothing about who is in it.
            metadata: [
                'kind' => 'csv',
                'delimiter' => $delimiter === "\t" ? 'tab' : $delimiter,
                'encoding' => 'UTF-8',
                'byte_order_mark' => $hadBom,
            ],
        );
    }

    protected function withoutByteOrderMark(string $contents, ?bool &$hadBom = null): string
    {
        $hadBom = str_starts_with($contents, "\xEF\xBB\xBF");

        return $hadBom ? substr($contents, 3) : $contents;
    }

    /**
     * The delimiter that makes the file rectangular.
     *
     * The test is the COMMONEST field count, not a uniform one, because real
     * files are not uniform: a teacher's export routinely opens with a title
     * spanning one cell — «Fichas de avaliação — 7.º Z» — above the table
     * proper. Demanding that every sampled line agree would refuse that file
     * outright, so what is required instead is that a clear majority of lines
     * agree on a count of two or more.
     *
     * Where several delimiters qualify, the one yielding more columns wins:
     * splitting further can only be right, since a delimiter that produced FEWER
     * fields left some of them joined together.
     */
    protected function detectDelimiter(string $contents): ?string
    {
        $best = null;
        $bestColumns = 1;

        foreach (self::DELIMITERS as $delimiter) {
            $counts = [];

            foreach ($this->sample($contents, $delimiter) as $fields) {
                $counts[] = count($fields);
            }

            if ($counts === []) {
                continue;
            }

            $frequencies = array_count_values($counts);
            arsort($frequencies);

            $columns = (int) array_key_first($frequencies);

            if ($columns < 2 || count($counts) > $frequencies[$columns] * 2) {
                continue;
            }

            if ($columns > $bestColumns) {
                $best = $delimiter;
                $bestColumns = $columns;
            }
        }

        return $best;
    }

    /**
     * @return list<list<string>>
     */
    protected function sample(string $contents, string $delimiter): array
    {
        $handle = $this->stream($contents);
        $rows = [];

        try {
            while (count($rows) < self::SAMPLE_LINES && ($fields = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                if ($fields === [null] || $this->isBlankRow($fields)) {
                    continue;
                }

                $rows[] = array_map(fn ($field): string => (string) $field, $fields);
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @return list<list<TabularCell>>
     */
    protected function rows(string $contents, string $delimiter): array
    {
        $handle = $this->stream($contents);
        $rows = [];

        try {
            while (($fields = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                if (count($rows) >= self::MAX_ROWS) {
                    throw new UnreadableSpreadsheet(__('O ficheiro tem mais de :linhas linhas. Divida-o antes de importar.', ['linhas' => self::MAX_ROWS]));
                }

                if ($fields === [null]) {
                    // A genuinely empty line. Kept as a blank row so that row
                    // numbers keep matching what the teacher sees in Excel.
                    $rows[] = [];

                    continue;
                }

                if (count($fields) > self::MAX_COLUMNS) {
                    throw new UnreadableSpreadsheet(__('O ficheiro tem mais de :colunas colunas.', ['colunas' => self::MAX_COLUMNS]));
                }

                $rows[] = array_map(fn ($field): TabularCell => $this->cell((string) $field), $fields);
            }
        } finally {
            fclose($handle);
        }

        return $this->withoutTrailingBlankRows($rows);
    }

    protected function cell(string $field): TabularCell
    {
        $text = trim($field);

        if ($text === '') {
            return TabularCell::empty();
        }

        if (str_starts_with($text, '=')) {
            return new TabularCell(text: $text, isFormula: true);
        }

        return new TabularCell(
            text: $text,
            number: TabularNumber::normalise($text),
            isPercentage: TabularNumber::looksLikeAPercentage($text),
        );
    }

    /**
     * @param  list<string|null>  $fields
     */
    protected function isBlankRow(array $fields): bool
    {
        foreach ($fields as $field) {
            if (trim((string) $field) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<list<TabularCell>>  $rows
     * @return list<list<TabularCell>>
     */
    protected function withoutTrailingBlankRows(array $rows): array
    {
        while ($rows !== [] && $this->rowIsEmpty(end($rows))) {
            array_pop($rows);
        }

        // array_pop only ever removes from the end, so the keys stay a list.
        return $rows;
    }

    /**
     * @param  list<TabularCell>  $row
     */
    protected function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (! $cell->isBlank()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every row the same width, so a column letter means the same column on
     * every line even when a row ended early.
     *
     * @param  list<list<TabularCell>>  $rows
     * @return list<list<TabularCell>>
     */
    protected function padded(array $rows, int $columnCount): array
    {
        return array_map(function (array $row) use ($columnCount): array {
            while (count($row) < $columnCount) {
                $row[] = TabularCell::empty();
            }

            return $row;
        }, $rows);
    }

    /**
     * @return resource
     */
    protected function stream(string $contents)
    {
        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            throw new UnreadableSpreadsheet(__('Não foi possível ler o ficheiro.'));
        }

        fwrite($handle, $contents);
        rewind($handle);

        return $handle;
    }
}
