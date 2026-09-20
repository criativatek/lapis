<?php

namespace App\Services\Characterisation\Import;

/**
 * The delimiter of a block of pasted text, measured rather than assumed.
 *
 * Extracted out of ReadCharacterisationTable so TsvTableExtractor can share
 * exactly this logic instead of re-implementing it — a Portuguese export is
 * at least as likely to be semicolon-separated as comma-separated, and a
 * name written «Silva, Ana» makes guessing a comma actively wrong.
 */
class SniffDelimiter
{
    /**
     * Measured, not preferred: the delimiter that yields the same field count on
     * the most lines wins. A Portuguese export separated by semicolons is at
     * least as common as a comma-separated one, and a name like «Silva, Ana»
     * makes the naive choice actively wrong.
     *
     * @param  list<string>  $lines
     */
    public function sniff(array $lines): string
    {
        $sample = array_slice($lines, 0, 10);
        $best = "\t";
        $bestScore = 0;

        foreach (["\t", ';', ',', '|'] as $candidate) {
            $counts = array_map(
                fn (string $line) => count(str_getcsv($line, $candidate, '"', '\\')),
                $sample,
            );

            if ($counts === []) {
                continue;
            }

            $fields = max($counts);

            if ($fields < 2) {
                continue;
            }

            // Consistency is what identifies a delimiter; a character that
            // splits one line into nine fields and the next into two is
            // punctuation, not structure.
            $consistent = count(array_filter($counts, fn (int $count) => $count === $fields));
            $score = $consistent * 100 + $fields;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }
}
