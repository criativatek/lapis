<?php

namespace App\Services\Import\Timetable;

use App\Domain\Import\Timetable\ExtractedPdfDocument;
use App\Domain\Import\Timetable\ParsedTimetable;
use App\Domain\Import\Timetable\PositionedTextFragment;
use App\Domain\Import\Timetable\TimetableCandidateRow;

/**
 * Turns an extracted PDF into candidate lesson blocks — and nothing else.
 *
 * NO DATABASE, NO MODELS, NO WRITES. This class does not know that SchoolClass
 * exists. It reads a file and says what the file says; deciding which turma
 * «7º C» means is MatchTimetableClasses' job, and creating anything at all is
 * the controller's. That separation is the point: this is the part that can be
 * wrong in subtle, structural ways, so it is the part that must be testable
 * without a database in sight.
 *
 * The table is reconstructed from POSITIONS, not from separators — see
 * WeekdayColumnGrid for why the linear reading cannot be trusted for a grid with
 * empty cells. Titles, the academic year, the teacher's name and the footer are
 * read from the linear text, which is perfectly reliable for them.
 */
class TimetableParser
{
    /**
     * How close to the «Horas» column a fragment must be to be counted as part
     * of it rather than as a lesson.
     *
     * A row label is recognised by its TEXT, never by its position: one export
     * draws the whole label column relatively and reports it at x = 0, another
     * draws it at the table's real left edge. But knowing where that column is
     * still matters, because the page footer is usually printed in the same
     * left margin, and a footer line mistaken for a lesson would invent a
     * weekday column that no lesson ever sits in.
     */
    protected const LABEL_COLUMN_TOLERANCE = 4.0;

    protected const TIME_RANGE = '/^(\d{1,2}):(\d{2})\s*[-–—]\s*(\d{1,2}):(\d{2})$/u';

    /**
     * A clean, unqualified subject code: letters only, all uppercase.
     *
     * This is the gate between "an ordinary lesson with a turma" and everything
     * else the same grid carries. «PORT» passes. «AE_3C_Port» does not — the
     * underscores and the lowercase tail are exactly what distinguish a
     * co-teaching/support code from a subject, and it must never be imported as
     * an ordinary class. Deliberately a SHAPE and not a list: a whitelist of
     * known subject codes would need every school's abbreviations in advance and
     * would silently drop the first one it had never seen.
     */
    protected const SUBJECT_CODE = '/^[A-Z]{2,8}$/';

    /**
     * Portuguese school timetables number the weekdays from Sunday, so «2ª
     * feira» is Monday. RecurringLessonSlot stores ISO-8601 (Monday = 1),
     * matched against Carbon's dayOfWeekIso at materialisation.
     */
    protected const NAMED_WEEKDAYS = [
        'segundafeira' => 1,
        'segunda' => 1,
        'tercafeira' => 2,
        'terca' => 2,
        'quartafeira' => 3,
        'quarta' => 3,
        'quintafeira' => 4,
        'quinta' => 4,
        'sextafeira' => 5,
        'sexta' => 5,
        'sabado' => 6,
        'domingo' => 7,
    ];

    /**
     * What these exports print when the school week is the ordinary one. Used
     * only when the header row itself could not be found — the columns are still
     * derived from the data's own positions either way.
     *
     * @var list<string>
     */
    protected const DEFAULT_WEEKDAY_LABELS = ['2ª FEIRA', '3ª FEIRA', '4ª FEIRA', '5ª FEIRA', '6ª FEIRA'];

    public function parse(ExtractedPdfDocument $document): ParsedTimetable
    {
        if (! $document->hasText()) {
            throw new TimetablePdfException(
                __('Este PDF não contém texto — parece ser uma digitalização ou uma imagem. Exporte o horário novamente a partir do sistema da escola, em vez de o digitalizar.'),
            );
        }

        $lines = $this->lines($document->text());
        $header = $this->headerRow($lines);
        $weekdayLabels = $header['labels'];
        $fragments = $document->fragments();

        $blocks = $this->readRows($fragments, $this->grid($fragments, $weekdayLabels), count($weekdayLabels));

        if ($blocks === []) {
            throw new TimetablePdfException(
                __('Não foi possível reconhecer um horário neste PDF: não encontrámos nenhum bloco horário (por exemplo «08:30 - 09:20»). Confirme que carregou o horário do professor exportado pela escola.'),
            );
        }

        $rows = [];

        foreach ($blocks as $block) {
            ksort($block['cells']);

            foreach ($block['cells'] as $column => $text) {
                $candidate = $this->candidateFor($column, $block, $text, $weekdayLabels);

                if ($candidate !== null) {
                    $rows[] = $candidate;
                }
            }
        }

        $title = $this->title($lines, $header['index']);

        return new ParsedTimetable(
            $rows,
            count($blocks),
            $weekdayLabels,
            $title['academic_year'],
            $title['academic_year'] === null ? null : $this->normaliseAcademicYear($title['academic_year']),
            $title['teacher'],
        );
    }

    /**
     * Walks the fragments in reading order and rebuilds the table.
     *
     * The algorithm is deliberately simple, and each step of it is a fact
     * established by reading a real export:
     *
     * - a fragment at x = 0 whose text is «HH:MM - HH:MM» opens a new row;
     * - every positioned fragment after it belongs to that row until the next
     *   such label, and lands in whichever weekday band its own x falls in;
     * - two fragments in the same band of the same row are one cell whose text
     *   wrapped onto a second line, and are joined back together — which is why
     *   they are appended rather than overwritten.
     *
     * A cell is never assigned by counting: an empty Tuesday leaves no trace at
     * all in the file, so counting is precisely the thing that cannot work.
     *
     * @param  list<PositionedTextFragment>  $fragments
     * @return list<array{starts_at: string, ends_at: string, cells: array<int, string>}>
     */
    protected function readRows(array $fragments, ?WeekdayColumnGrid $grid, int $columnCount): array
    {
        /** @var list<array{starts_at: string, ends_at: string}> $hours */
        $hours = [];
        /** @var list<array<int, string>> $cells */
        $cells = [];
        $current = null;

        foreach ($fragments as $fragment) {
            $text = trim($fragment->text);

            if ($text === '') {
                continue;
            }

            $range = $this->timeRange($text);

            if ($range !== null) {
                $hours[] = $range;
                $cells[] = [];
                $current = count($hours) - 1;

                continue;
            }

            if ($current === null || $grid === null) {
                continue;
            }

            $column = $grid->columnFor($fragment->x);

            if ($column === null || $column >= $columnCount) {
                continue;
            }

            // Appended, never overwritten: a second fragment in the same band of
            // the same row is the rest of a cell whose text wrapped, and losing
            // it would silently truncate the room or the turma.
            $cells[$current][$column] = isset($cells[$current][$column])
                ? trim($cells[$current][$column].' '.$text)
                : $text;
        }

        $rows = [];

        foreach ($hours as $index => $hour) {
            $rows[] = [
                'starts_at' => $hour['starts_at'],
                'ends_at' => $hour['ends_at'],
                'cells' => $cells[$index],
            ];
        }

        return $rows;
    }

    /**
     * The weekday columns, by the heading row if it can answer and by the
     * lessons' own positions if it cannot. See WeekdayColumnGrid for why that
     * order, and for what the second route can and cannot recover.
     *
     * @param  list<PositionedTextFragment>  $fragments
     * @param  list<string>  $weekdayLabels
     */
    protected function grid(array $fragments, array $weekdayLabels): ?WeekdayColumnGrid
    {
        $fromLabels = $this->labelPositions($fragments, $weekdayLabels);

        if ($fromLabels !== null) {
            $grid = WeekdayColumnGrid::fromLabelPositions($fromLabels);

            if ($grid !== null) {
                return $grid;
            }
        }

        return WeekdayColumnGrid::derive($this->cellPositions($fragments), count($weekdayLabels));
    }

    /**
     * Where the heading row drew each weekday label, when every one of them has
     * its own place on the page.
     *
     * All-or-nothing on purpose: a partial reading — three labels positioned and
     * two collapsed — would put lessons in bands that were never measured.
     *
     * @param  list<PositionedTextFragment>  $fragments
     * @param  list<string>  $weekdayLabels
     * @return list<float>|null
     */
    protected function labelPositions(array $fragments, array $weekdayLabels): ?array
    {
        $wanted = [];

        foreach ($weekdayLabels as $index => $label) {
            $wanted[$this->normaliseLabel($label)] = $index;
        }

        $positions = [];

        foreach ($fragments as $fragment) {
            $text = trim($fragment->text);

            // The heading is above the table; anything from the first hour
            // onwards is a lesson, not a label.
            if ($this->timeRange($text) !== null) {
                break;
            }

            $index = $wanted[$this->normaliseLabel($text)] ?? null;

            if ($index !== null && ! array_key_exists($index, $positions)) {
                $positions[$index] = $fragment->x;
            }
        }

        if (count($positions) !== count($weekdayLabels)) {
            return null;
        }

        ksort($positions);

        return array_values($positions);
    }

    /**
     * The x of every fragment that could be a lesson.
     *
     * Two exclusions, both of them earned. Fragments before the first hour are
     * the title block and the heading row, whose positions are not where the
     * lessons are drawn. Fragments sharing the row-label column are the hours
     * themselves and — the reason this matters — the page footer, which these
     * exports print in the same left margin and which would otherwise be read as
     * a sixth weekday.
     *
     * @param  list<PositionedTextFragment>  $fragments
     * @return list<float>
     */
    protected function cellPositions(array $fragments): array
    {
        $labelColumns = [];
        $positions = [];
        $started = false;

        foreach ($fragments as $fragment) {
            $text = trim($fragment->text);

            if ($text === '') {
                continue;
            }

            if ($this->timeRange($text) !== null) {
                $started = true;
                $labelColumns[] = $fragment->x;

                continue;
            }

            if ($started) {
                $positions[] = $fragment->x;
            }
        }

        return array_values(array_filter(
            $positions,
            function (float $x) use ($labelColumns): bool {
                foreach ($labelColumns as $labelColumn) {
                    if (abs($x - $labelColumn) <= self::LABEL_COLUMN_TOLERANCE) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * @param  array{starts_at: string, ends_at: string, cells: array<int, string>}  $block
     * @param  list<string>  $weekdayLabels
     */
    protected function candidateFor(int $column, array $block, string $text, array $weekdayLabels): ?TimetableCandidateRow
    {
        $label = $weekdayLabels[$column] ?? null;

        if ($label === null || $block['starts_at'] >= $block['ends_at']) {
            return null;
        }

        $parts = $this->split($text);

        return new TimetableCandidateRow(
            $label,
            $this->weekdayNumber($label) ?? $column + 1,
            $block['starts_at'],
            $block['ends_at'],
            $text,
            $parts['subject'],
            $parts['class'],
            $parts['room'],
        );
    }

    /**
     * Splits «PORT - 7º C - S09» into its three parts, and refuses to split
     * anything that does not have exactly that shape.
     *
     * Verified against the three patterns a real export actually contains:
     * «PORT - 7º C - S09» is an ordinary lesson; «REE - Sem sala» has two parts
     * and «Sem sala» is a literal "no room" marker rather than a room; and
     * «AE_3C_Port - 7º C - S02» has three parts but a first part that is not a
     * subject code. Only the first is a turma. The other two keep their text and
     * go to the teacher untouched.
     *
     * @return array{subject: ?string, class: ?string, room: ?string}
     */
    protected function split(string $text): array
    {
        $none = ['subject' => null, 'class' => null, 'room' => null];
        $parts = array_map(trim(...), explode(' - ', $text));

        if (count($parts) !== 3 || preg_match(self::SUBJECT_CODE, $parts[0]) !== 1 || $parts[1] === '') {
            return $none;
        }

        return [
            'subject' => $parts[0],
            'class' => $parts[1],
            'room' => $parts[2] === '' ? null : $parts[2],
        ];
    }

    /**
     * @return array{starts_at: string, ends_at: string}|null
     */
    protected function timeRange(string $text): ?array
    {
        if (preg_match(self::TIME_RANGE, $text, $matches) !== 1) {
            return null;
        }

        [, $startHour, $startMinute, $endHour, $endMinute] = $matches;

        if ((int) $startHour > 23 || (int) $endHour > 23 || (int) $startMinute > 59 || (int) $endMinute > 59) {
            return null;
        }

        return [
            'starts_at' => sprintf('%02d:%02d', (int) $startHour, (int) $startMinute),
            'ends_at' => sprintf('%02d:%02d', (int) $endHour, (int) $endMinute),
        ];
    }

    /**
     * The heading row's weekday labels, left to right, and which line it was on.
     *
     * @param  list<string>  $lines
     * @return array{index: ?int, labels: list<string>}
     */
    protected function headerRow(array $lines): array
    {
        foreach ($lines as $index => $line) {
            $labels = [];

            foreach (explode("\t", $line) as $cell) {
                $cell = trim($cell);

                if ($cell !== '' && $this->weekdayNumber($cell) !== null) {
                    $labels[] = $cell;
                }
            }

            // Two is enough to be a heading row and not a coincidence; a real
            // one has five.
            if (count($labels) >= 2) {
                return ['index' => $index, 'labels' => $labels];
            }
        }

        return ['index' => null, 'labels' => self::DEFAULT_WEEKDAY_LABELS];
    }

    protected function weekdayNumber(string $label): ?int
    {
        $key = $this->normaliseLabel($label);

        if (preg_match('/^([2-7])feira$/', $key, $matches) === 1) {
            // «2ª feira» is Monday: the ordinal counts from Sunday, ISO counts
            // from Monday, so the two differ by exactly one.
            return (int) $matches[1] - 1;
        }

        return self::NAMED_WEEKDAYS[$key] ?? null;
    }

    /**
     * Reads the academic year and the teacher's name out of the title block.
     *
     * Both are best-effort. The year drives one warning and the name drives
     * nothing at all; neither may ever block an import, so both stay nullable
     * all the way to the screen.
     *
     * @param  list<string>  $lines
     * @return array{academic_year: ?string, teacher: ?string}
     */
    protected function title(array $lines, ?int $headerIndex): array
    {
        $limit = $headerIndex ?? count($lines);

        for ($index = 0; $index < $limit; $index++) {
            $line = trim($lines[$index]);

            if (preg_match('/^\d{4}\s*\/\s*\d{2,4}$/', $line) !== 1) {
                continue;
            }

            // The export prints the teacher immediately below the year. A line
            // carrying a digit is a phone number, an address or the table
            // itself — never a name.
            $next = trim($lines[$index + 1] ?? '');
            $teacher = $next !== '' && ! str_contains($next, "\t") && preg_match('/\d/', $next) !== 1
                ? $next
                : null;

            return ['academic_year' => $line, 'teacher' => $teacher];
        }

        return ['academic_year' => null, 'teacher' => null];
    }

    /**
     * «2025/26» as this app writes it: «2025/2026».
     *
     * The century is taken from the start year rather than assumed, so a label
     * straddling one (a hypothetical «1999/00») still expands forwards.
     */
    protected function normaliseAcademicYear(string $label): ?string
    {
        if (preg_match('/^(\d{4})\s*\/\s*(\d{2,4})$/', trim($label), $matches) !== 1) {
            return null;
        }

        $start = (int) $matches[1];
        $end = (int) $matches[2];

        if (strlen($matches[2]) <= 2) {
            $end = $start - ($start % 100) + $end;

            if ($end < $start) {
                $end += 100;
            }
        }

        return sprintf('%d/%d', $start, $end);
    }

    /**
     * @return list<string>
     */
    protected function lines(string $text): array
    {
        $lines = preg_split('/\R/u', $text);

        return $lines === false ? [] : $lines;
    }

    /**
     * Lowercased, unaccented, and stripped of everything that is decoration —
     * so «2ª FEIRA», «2ª  Feira» and «2a feira» are one key.
     */
    protected function normaliseLabel(string $label): string
    {
        $label = mb_strtolower(trim($label));
        $label = str_replace(['º', 'ª', '°'], '', $label);
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);

        if ($transliterated !== false) {
            $label = $transliterated;
        }

        return (string) preg_replace('/[^a-z0-9]/', '', $label);
    }
}
