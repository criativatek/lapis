<?php

namespace App\Services\Characterisation\Import\Extraction;

use App\Services\Characterisation\Import\SniffDelimiter;

/**
 * Plain pasted text, delimiter-separated. What a teacher gets when the
 * clipboard has no HTML representation — a plain-text paste, or a paste from
 * an application that never put a `<table>` on the clipboard in the first
 * place.
 *
 * Delimiter sniffing is SHARED with ReadCharacterisationTable's original
 * pasted-text path via SniffDelimiter, rather than re-measured here: two
 * copies of "which delimiter is this" is two things to keep in sync, and
 * they answer the exact same question on the exact same shape of input.
 *
 * Empty cells survive: str_getcsv turns an unquoted empty trailing field
 * into null, which is converted to '' rather than dropped, and intermediate
 * empty fields are ordinary elements of the returned array — nothing here
 * collapses them.
 */
class TsvTableExtractor implements TableExtractor
{
    public function __construct(
        private readonly SniffDelimiter $sniffer = new SniffDelimiter,
    ) {}

    public function supports(mixed $source): bool
    {
        return is_string($source) && trim($source) !== '';
    }

    /**
     * @return list<ExtractedTable>
     */
    public function extract(mixed $source): array
    {
        if (! is_string($source)) {
            return [];
        }

        $trimmed = trim($source);

        if ($trimmed === '') {
            return [];
        }

        // Sampled by naive line-splitting purely to measure the delimiter —
        // a multiline quoted cell throwing off ONE sample line does not
        // change which character is the delimiter, so this approximation is
        // safe here even though it would corrupt the actual parse below.
        $sampleLines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/u', $trimmed) ?: [],
            fn (string $line) => trim($line) !== '',
        ));

        if ($sampleLines === []) {
            return [];
        }

        $delimiter = $this->sniffer->sniff($sampleLines);

        // Parsed through fgetcsv on a stream, NOT split on "\n" first: a
        // quoted field carrying an embedded newline — a multiline
        // observation pasted from a spreadsheet cell — is one field, and
        // splitting the text into lines before parsing it would tear that
        // field in two.
        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            return [];
        }

        $rows = [];

        try {
            fwrite($handle, $trimmed);
            rewind($handle);

            $rowIndex = 1;

            while (($fields = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                if ($fields === [null]) {
                    continue;
                }

                $cells = [];

                foreach ($fields as $columnIndex => $field) {
                    $cells[] = new ExtractedCell(
                        text: trim((string) $field),
                        row: $rowIndex,
                        column: $columnIndex + 1,
                    );
                }

                $rows[] = new ExtractedRow($rowIndex, $cells);
                $rowIndex++;
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            return [];
        }

        return [new ExtractedTable(
            rows: $rows,
            sourceType: ExtractedTableSource::PastedTsv,
        )];
    }
}
