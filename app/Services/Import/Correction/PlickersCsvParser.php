<?php

namespace App\Services\Import\Correction;

use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\CanonicalGroup;
use App\Domain\Import\Correction\CanonicalInstrument;
use App\Domain\Import\Correction\CanonicalItem;
use App\Domain\Import\Correction\CanonicalResult;
use App\Domain\Import\Correction\CanonicalStudent;
use App\Domain\Import\Correction\CanonicalSummary;
use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportIssue;
use App\Domain\Import\Correction\IssueCode;
use App\Domain\Import\Correction\IssueSeverity;

/**
 * Reads a Plickers CSV export.
 *
 * The shape, established against a real export rather than assumed:
 *
 *   L1   a title line the export prints for itself
 *   L2   blank
 *   L3   the header: Card Number, First name, Last Name, Score, Correct,
 *        Answered, and then one column per question WHOSE HEADER IS THE
 *        QUESTION TEXT
 *   L4   blank
 *   L5   the questions' URLs — their external identity
 *   L6   the answer key, one option letter per question
 *   L7+  one row per student
 *
 * The header is located by its own content, never by row number: an export with
 * one more or one fewer title line is the same export, and a parser that counts
 * rows breaks on a file a teacher would call identical. Same lesson the roster
 * import already learned.
 *
 * Two things this parser deliberately does NOT do:
 *
 *  - It never turns «-» into a zero. In the real export «-» appears both in the
 *    Score column and in every question cell of a student who did not take part,
 *    with Answered = 0. Not answering is not answering wrongly, and it is not an
 *    absence either — an absence is something a teacher records (§68, §69).
 *  - It never invents what a question is worth. Plickers has no notion of marks
 *    per question, so `pointsPossible` stays null and the teacher decides before
 *    anything can be confirmed (§51).
 */
class PlickersCsvParser implements CorrectionGridParser
{
    /** The header cell that identifies the real header row. */
    protected const HEADER_CARD = 'card number';

    /** Columns that come before the questions, in the order the export writes them. */
    protected const FIXED_HEADERS = ['card number', 'first name', 'last name', 'score', 'correct', 'answered'];

    /** What Plickers writes where a student gave no answer at all. */
    protected const NO_ANSWER = '-';

    public function source(): CorrectionGridSource
    {
        return CorrectionGridSource::Plickers;
    }

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['csv'];
    }

    /**
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        // Windows and Excel disagree about what a CSV is; all four turn up in
        // practice for the same file.
        return ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
    }

    public function supports(string $absolutePath, string $originalFilename): bool
    {
        if (strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION)) !== 'csv') {
            return false;
        }

        $rows = $this->readRows($absolutePath, limit: 20);

        return $this->locateHeaderRow($rows) !== null;
    }

    public function parse(string $absolutePath, string $originalFilename): CanonicalCorrectionGrid
    {
        $rows = $this->readRows($absolutePath);
        $headerIndex = $this->locateHeaderRow($rows);

        if ($headerIndex === null) {
            return $this->unreadable('Não foi encontrada a linha de cabeçalho do Plickers («Card Number»).');
        }

        $header = $rows[$headerIndex];
        $questionColumns = $this->questionColumns($header);

        if ($questionColumns === []) {
            return $this->unreadable('O ficheiro não tem nenhuma coluna de pergunta a seguir às colunas fixas do Plickers.');
        }

        $body = array_slice($rows, $headerIndex + 1);

        $externalIds = $this->findAnnotationRow($body, $questionColumns, fn (string $value): bool => str_starts_with($value, 'http'));
        $answerKeys = $this->findAnnotationRow($body, $questionColumns, fn (string $value): bool => (bool) preg_match('/^[A-Za-z]$/', $value));

        // One implicit section: Plickers has no structure, and showing the
        // teacher a «Grupo 1» that only exists because the model needs one would
        // be inventing a section they never made (§54).
        $group = CanonicalGroup::implicit();

        $items = $this->items($header, $questionColumns, $externalIds, $answerKeys, $group->sourceKey);

        [$students, $results, $summaries, $issues] = $this->students($body, $questionColumns, $items);

        if ($students === []) {
            return $this->unreadable('O ficheiro não tem nenhuma linha de aluno abaixo do cabeçalho.');
        }

        if ($answerKeys === null) {
            $issues[] = ImportIssue::make(
                IssueCode::AmbiguousValue,
                'O ficheiro não traz a linha de respostas corretas, por isso não é possível saber que respostas estavam certas. Terá de registar as classificações manualmente.',
                ['questions' => count($items)],
                IssueSeverity::Warning,
            );
        }

        // Plickers states no marks per question. Not a defect in the file — it
        // is not that kind of tool — but the import cannot produce a single mark
        // until the teacher says what a question is worth.
        $issues[] = ImportIssue::make(
            IssueCode::MissingPoints,
            'O ficheiro Plickers não fornece uma cotação individual para estas perguntas. Defina a cotação antes de importar.',
            ['questions' => count($items)],
        );

        return new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Plickers,
            instrument: new CanonicalInstrument(
                // Whatever the export printed at the top, offered as a
                // suggestion. Never a date: the parenthesised «(25/26)» in a real
                // export is a school year, and reading it as a date would put the
                // instrument in the wrong place in time (§13, §53).
                title: $this->titleLine($rows, $headerIndex),
                metadata: ['question_count' => count($items)],
            ),
            groups: [$group],
            items: $items,
            students: $students,
            results: $results,
            summaries: $summaries,
            issues: $issues,
            sourceMetadata: [
                'header_row' => $headerIndex + 1,
                'question_columns' => count($questionColumns),
                'has_answer_key' => $answerKeys !== null,
                'has_external_ids' => $externalIds !== null,
                'student_rows' => count($students),
            ],
        );
    }

    /**
     * Reads the file as CSV — with a real CSV reader, because a question's text
     * routinely contains commas and quotes and `explode(',')` would silently
     * shred the header into the wrong number of columns (§47).
     *
     * @return list<list<string>>
     */
    protected function readRows(string $absolutePath, ?int $limit = null): array
    {
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            return [];
        }

        // Excel writes a UTF-8 BOM and Plickers exports carry one. Left in
        // place it becomes part of the first header cell, and the header stops
        // being found by a comparison that is otherwise exactly right.
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            // A CSV reader hands back [null] for a blank line; normalise before
            // anything downstream has to care.
            $rows[] = array_map(fn ($cell): string => trim((string) $cell), $row);

            if ($limit !== null && count($rows) >= $limit) {
                break;
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<list<string>>  $rows
     */
    protected function locateHeaderRow(array $rows): ?int
    {
        foreach ($rows as $index => $row) {
            foreach ($row as $cell) {
                if (strtolower($cell) === self::HEADER_CARD) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * The columns after the fixed ones that actually carry a question header.
     *
     * @param  list<string>  $header
     * @return list<int>
     */
    protected function questionColumns(array $header): array
    {
        $columns = [];

        foreach ($header as $column => $label) {
            if ($label === '' || in_array(strtolower($label), self::FIXED_HEADERS, true)) {
                continue;
            }

            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * The export annotates its questions on rows of their own — URLs on one,
     * the answer key on another — with the student columns left empty. Found by
     * what they contain rather than by where they sit, so an export with one
     * more blank line between them still reads.
     *
     * @param  list<list<string>>  $body
     * @param  list<int>  $questionColumns
     * @param  callable(string): bool  $looksRight
     * @return array<int, string>|null
     */
    protected function findAnnotationRow(array $body, array $questionColumns, callable $looksRight): ?array
    {
        foreach ($body as $row) {
            // A student row always names a card; an annotation row never does.
            if (($row[0] ?? '') !== '') {
                continue;
            }

            $values = [];

            foreach ($questionColumns as $column) {
                $value = $row[$column] ?? '';

                if ($value !== '' && $looksRight($value)) {
                    $values[$column] = $value;
                }
            }

            if ($values !== []) {
                return $values;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $header
     * @param  list<int>  $questionColumns
     * @param  array<int, string>|null  $externalIds
     * @param  array<int, string>|null  $answerKeys
     * @return list<CanonicalItem>
     */
    protected function items(array $header, array $questionColumns, ?array $externalIds, ?array $answerKeys, string $groupSourceKey): array
    {
        $items = [];

        foreach ($questionColumns as $sequence => $column) {
            $text = $header[$column] ?? '';

            $items[] = new CanonicalItem(
                sourceKey: 'item:'.$column,
                sequence: $sequence + 1,
                groupSourceKey: $groupSourceKey,
                externalId: $externalIds[$column] ?? null,
                // The letter the export prints in front of each question is what
                // the students saw on the paper, so it makes a better code than
                // an invented Q1. Still only a suggestion — code is a label, not
                // identity (§15).
                code: $this->codeFrom($text) ?? 'Q'.($sequence + 1),
                label: $this->shorten($text),
                questionText: $text === '' ? null : $text,
                // Plickers does not say. Nobody here gets to decide (§51).
                pointsPossible: null,
                answerKey: $answerKeys[$column] ?? null,
                metadata: ['column' => $column],
            );
        }

        return $items;
    }

    /**
     * «A. Logo no início…» → «A». Anything that is not a short prefix followed
     * by a separator produces null, and the caller falls back to a sequence.
     */
    protected function codeFrom(string $questionText): ?string
    {
        if (preg_match('/^([A-Za-z0-9]{1,3})[.)]\s/u', $questionText, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * A readable label for a question whose "header" is the entire question.
     */
    protected function shorten(string $text, int $length = 80): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) <= $length ? $text : mb_substr($text, 0, $length - 1).'…';
    }

    /**
     * @param  list<list<string>>  $body
     * @param  list<int>  $questionColumns
     * @param  list<CanonicalItem>  $items
     * @return array{0: list<CanonicalStudent>, 1: list<CanonicalResult>, 2: list<CanonicalSummary>, 3: list<ImportIssue>}
     */
    protected function students(array $body, array $questionColumns, array $items): array
    {
        $students = [];
        $results = [];
        $summaries = [];
        $issues = [];
        $unanswered = 0;

        foreach ($body as $row) {
            $card = $row[0] ?? '';

            // Annotation rows and trailing blanks: no card, no student.
            if ($card === '') {
                continue;
            }

            $sourceKey = 'student:'.$card;
            $score = $this->nullIfDash($row[3] ?? '');

            $students[] = new CanonicalStudent(
                sourceKey: $sourceKey,
                externalId: $card,
                cardNumber: $card,
                displayName: $this->name($row, $card),
                // NEVER set from the card. A Plickers card is a piece of
                // cardboard; the number on it is whatever the teacher happened
                // to hand out, and reading it as a roll number would put one
                // student's marks on another (§3).
                classNumber: null,
                sourceScore: $score,
                sourceCorrect: $this->integerOrNull($row[4] ?? ''),
                sourceAnswered: $this->integerOrNull($row[5] ?? ''),
            );

            // The source's own arithmetic, kept apart from the marks. Shown for
            // reference and comparison; never a classification (§19, §49).
            foreach (['score' => $score, 'correct' => $row[4] ?? null, 'answered' => $row[5] ?? null] as $key => $value) {
                $summaries[] = new CanonicalSummary(
                    scope: CanonicalSummary::SCOPE_STUDENT,
                    key: $key,
                    subjectSourceKey: $sourceKey,
                    value: $this->nullIfDash((string) $value),
                    unit: $key === 'score' ? 'percent' : 'count',
                );
            }

            foreach ($items as $index => $item) {
                $column = $questionColumns[$index];
                $raw = $row[$column] ?? '';
                $answerKey = $item->answerKey;

                if ($raw === '' || $raw === self::NO_ANSWER) {
                    // No answer. Not a wrong answer, not a zero, not an absence.
                    $results[] = new CanonicalResult(
                        studentSourceKey: $sourceKey,
                        itemSourceKey: $item->sourceKey,
                        rawResponse: null,
                        pointsEarned: null,
                        resultState: null,
                        isCorrect: null,
                        sourceValue: $raw === '' ? null : $raw,
                    );
                    $unanswered++;

                    continue;
                }

                $results[] = new CanonicalResult(
                    studentSourceKey: $sourceKey,
                    itemSourceKey: $item->sourceKey,
                    rawResponse: $raw,
                    // Still null: knowing the answer was wrong is not knowing
                    // what it was worth. The mark is resolved once the teacher
                    // has set a cotação (CanonicalResult::resolvedAgainst).
                    pointsEarned: null,
                    resultState: null,
                    isCorrect: $answerKey === null ? null : strcasecmp($raw, $answerKey) === 0,
                    sourceValue: $raw,
                );
            }
        }

        if ($unanswered > 0) {
            $issues[] = ImportIssue::make(
                IssueCode::UnansweredQuestion,
                'Há respostas em branco no ficheiro. Ficam por avaliar — uma resposta em branco não é uma resposta errada nem uma falta.',
                ['cells' => $unanswered],
            );
        }

        return [$students, $results, $summaries, $issues];
    }

    /**
     * The student's name, with the card number taken back off it.
     *
     * Teachers routinely type the card number into the Plickers name field so
     * they can hand the right card to the right student — a real export reads
     * «1 Afonso Pito Mordomo», not «Afonso Pito Mordomo». Left in, that prefix
     * makes the name match nothing at all, and the teacher is sent to map thirty
     * students by hand for want of a leading digit.
     *
     * Only stripped when it is demonstrably THIS row's card number followed by a
     * separator. A student genuinely called «2Pac» keeps their name, and a card
     * number that is not at the front is left exactly where it is.
     *
     * @param  list<string>  $row
     */
    protected function name(array $row, ?string $card = null): ?string
    {
        $name = trim(($row[1] ?? '').' '.($row[2] ?? ''));

        if ($name === '') {
            return null;
        }

        if ($card !== null && $card !== '') {
            $stripped = preg_replace('/^'.preg_quote($card, '/').'\s*[.\-–—)]?\s+/u', '', $name, 1);

            // Never strip it away entirely: a name that IS the number is a name
            // we do not understand, and leaving it whole keeps that visible.
            if (is_string($stripped) && trim($stripped) !== '') {
                $name = trim($stripped);
            }
        }

        return $name;
    }

    protected function nullIfDash(string $value): ?string
    {
        $value = trim($value);

        return ($value === '' || $value === self::NO_ANSWER) ? null : $value;
    }

    protected function integerOrNull(string $value): ?int
    {
        $value = trim($value);

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }

    /**
     * @param  list<list<string>>  $rows
     */
    protected function titleLine(array $rows, int $headerIndex): ?string
    {
        for ($index = 0; $index < $headerIndex; $index++) {
            $candidate = trim($rows[$index][0] ?? '');

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A file this parser cannot read honestly. An Error, an empty grid, and no
     * guesses — and deliberately no fragment of the file in the message, since
     * these carry students' names (§26).
     */
    protected function unreadable(string $message): CanonicalCorrectionGrid
    {
        return new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Plickers,
            instrument: new CanonicalInstrument,
            issues: [ImportIssue::make(IssueCode::UnsupportedStructure, $message)],
        );
    }
}
