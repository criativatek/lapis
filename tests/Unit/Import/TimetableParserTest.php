<?php

namespace Tests\Unit\Import;

use App\Domain\Import\Timetable\ExtractedPdfDocument;
use App\Domain\Import\Timetable\ExtractedPdfPage;
use App\Domain\Import\Timetable\ParsedTimetable;
use App\Domain\Import\Timetable\PositionedTextFragment;
use App\Domain\Import\Timetable\TimetableCandidateRow;
use App\Services\Import\Timetable\TimetableParser;
use App\Services\Import\Timetable\TimetablePdfException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The timetable reader, against positioned text of exactly the shape a real
 * export produces — and no database anywhere near it.
 *
 * The fixture is not decoration, and none of its awkwardness is invented for the
 * sake of it. In a real export the hours, the heading row and the page footer
 * are all drawn relatively and reported at x = 0; the lesson cells are the only
 * things with a real position; an empty weekday leaves NO trace at all; a long
 * cell arrives as two separate runs; and a whole weekday can be missing from the
 * file without being missing from the week. Every one of those has produced a
 * confidently wrong answer at some point, so each has a test rather than a
 * comment.
 *
 * Every turma and every room in here is invented.
 */
class TimetableParserTest extends TestCase
{
    /** The five weekday columns, evenly spaced as a real export draws them. */
    private const MONDAY = 96.641;

    private const TUESDAY = 186.029;

    private const WEDNESDAY = 275.418;

    private const THURSDAY = 364.806;

    private const FRIDAY = 454.194;

    /** Hours, heading and footer alike: relative positioning, reported as zero. */
    private const LABEL = 0.0;

    #[Test]
    public function it_maps_portuguese_weekday_labels_to_iso_weekday_numbers(): void
    {
        // «2ª feira» is Monday: the Portuguese ordinal counts from Sunday and
        // ISO-8601 — what RecurringLessonSlot stores, and what Carbon's
        // dayOfWeekIso is compared against at materialisation — counts from
        // Monday. One full week, every column occupied, so nothing is inferred.
        $timetable = (new TimetableParser)->parse($this->document(
            "Horas\t2ª FEIRA\t3ª FEIRA\t4ª FEIRA\t5ª FEIRA\t6ª FEIRA\n08:30 - 09:20\tMAT - 7º A - S01\tMAT - 7º B - S01\tMAT - 7º C - S01\tMAT - 7º D - S01\tMAT - 7º E - S01",
            [
                [self::LABEL, 9.2, 'Horas'],
                [self::LABEL, 9.2, '2ª FEIRA'],
                [self::LABEL, 9.2, '3ª FEIRA'],
                [self::LABEL, 9.2, '4ª FEIRA'],
                [self::LABEL, 9.2, '5ª FEIRA'],
                [self::LABEL, 9.2, '6ª FEIRA'],
                [self::LABEL, 7.2, '08:30 - 09:20'],
                [self::MONDAY, 210.9, 'MAT - 7º A - S01'],
                [self::TUESDAY, 210.9, 'MAT - 7º B - S01'],
                [self::WEDNESDAY, 210.9, 'MAT - 7º C - S01'],
                [self::THURSDAY, 210.9, 'MAT - 7º D - S01'],
                [self::FRIDAY, 210.9, 'MAT - 7º E - S01'],
            ],
        ));

        $mapped = [];

        foreach ($timetable->rows as $row) {
            $mapped[$row->weekdayLabel] = $row->dayOfWeek;
        }

        $this->assertSame(
            ['2ª FEIRA' => 1, '3ª FEIRA' => 2, '4ª FEIRA' => 3, '5ª FEIRA' => 4, '6ª FEIRA' => 5],
            $mapped,
        );
    }

    #[Test]
    public function it_does_not_shift_a_column_when_an_earlier_cell_in_the_row_is_empty(): void
    {
        // 08:30 has nothing on Monday and something on Tuesday, Wednesday and
        // Friday. Counting separators would read those three as Monday, Tuesday
        // and Wednesday — the exact failure this parser exists to avoid.
        $rows = $this->rowsAt($this->parse()->rows, '08:30');

        $this->assertSame(
            [
                [2, 'MAT - 7º C - S01'],
                [3, 'MAT - 8º A - S02'],
                [5, 'MAT - 9º B - S05'],
            ],
            array_map(fn (TimetableCandidateRow $row): array => [$row->dayOfWeek, $row->rawText], $rows),
        );
    }

    #[Test]
    public function it_places_a_column_whose_neighbour_never_appears_by_interpolating_the_spacing(): void
    {
        // Thursday is empty for the whole file, so its column is never drawn.
        // Friday still has to land on Friday, which it only can if the spacing
        // between the columns that ARE drawn is used to interpolate the one that
        // is not.
        $friday = $this->rowsAt($this->parse()->rows, '08:30')[2];

        $this->assertSame(5, $friday->dayOfWeek);
        $this->assertSame('6ª FEIRA', $friday->weekdayLabel);
        $this->assertSame([], $this->rowsFor($this->parse()->rows, 4));
    }

    #[Test]
    public function it_keeps_two_consecutive_blocks_of_the_same_turma_as_two_candidates(): void
    {
        // A CLOSED PRODUCT DECISION: 08:30–09:20 and 09:20–10:10 are two lessons
        // of the same turma, not one lesson of 08:30–10:10. Merging them would
        // silently halve a teacher's week.
        $tuesday = $this->rowsFor($this->parse()->rows, 2);

        $this->assertCount(2, $tuesday);
        $this->assertSame(['08:30', '09:20'], array_map(
            fn (TimetableCandidateRow $row): string => $row->startsAt,
            $tuesday,
        ));
        $this->assertSame(['09:20', '10:10'], array_map(
            fn (TimetableCandidateRow $row): string => $row->endsAt,
            $tuesday,
        ));
        $this->assertSame(['7º C', '7º C'], array_map(
            fn (TimetableCandidateRow $row): ?string => $row->classRaw,
            $tuesday,
        ));
    }

    #[Test]
    public function it_reads_the_hours_exactly_as_printed_and_never_infers_a_duration(): void
    {
        $rows = $this->rowsAt($this->parse()->rows, '10:30');

        $this->assertSame('10:30', $rows[0]->startsAt);
        $this->assertSame('11:20', $rows[0]->endsAt);
    }

    #[Test]
    public function it_rejoins_a_cell_whose_text_wrapped_onto_a_second_line(): void
    {
        $wrapped = $this->rowsAt($this->parse()->rows, '14:20')[1];

        $this->assertSame('AE_3C_Mat - 7º C - S02', $wrapped->rawText);
    }

    #[Test]
    public function it_produces_no_candidate_at_all_for_an_empty_hour(): void
    {
        // 12:10–13:30 is lunch: a real row of the table, with nothing in it.
        $this->assertSame([], $this->rowsAt($this->parse()->rows, '12:10'));
        // It is still counted as an hour of the timetable, which is what proves
        // the row was read rather than skipped.
        $this->assertSame(6, $this->parse()->timeRowCount);
    }

    #[Test]
    public function it_refuses_to_treat_a_two_part_entry_as_a_turma(): void
    {
        $entry = $this->rowsAt($this->parse()->rows, '16:15')[0];

        $this->assertSame('REE - Sem sala', $entry->rawText);
        $this->assertFalse($entry->isCurricularCandidate());
        $this->assertNull($entry->classRaw);
        $this->assertNull($entry->subjectRaw);
        // «Sem sala» is a literal "no room" marker, never a room code.
        $this->assertNull($entry->roomRaw);
    }

    #[Test]
    public function it_refuses_to_treat_a_qualified_first_part_as_a_subject_code(): void
    {
        // Three parts, but «AE_3C_Mat» is a co-teaching code and not a subject.
        // It must never be imported as an ordinary lesson of turma 7º C.
        $entry = $this->rowsAt($this->parse()->rows, '14:20')[1];

        $this->assertFalse($entry->isCurricularCandidate());
        $this->assertNull($entry->classRaw);
        $this->assertSame('AE_3C_Mat - 7º C - S02', $entry->rawText);
    }

    #[Test]
    public function it_reads_more_than_one_turma_from_one_file(): void
    {
        $turmas = array_values(array_unique(array_filter(array_map(
            fn (TimetableCandidateRow $row): ?string => $row->classRaw,
            $this->parse()->rows,
        ))));

        sort($turmas);

        $this->assertSame(['7º C', '7º E', '8º A', '9º B'], $turmas);
    }

    #[Test]
    public function it_splits_a_curricular_cell_into_subject_turma_and_room(): void
    {
        $row = $this->rowsAt($this->parse()->rows, '08:30')[0];

        $this->assertTrue($row->isCurricularCandidate());
        $this->assertSame('MAT', $row->subjectRaw);
        $this->assertSame('7º C', $row->classRaw);
        $this->assertSame('S01', $row->roomRaw);
    }

    #[Test]
    public function it_reads_the_academic_year_and_the_teacher_from_the_title_block(): void
    {
        $timetable = $this->parse();

        $this->assertSame('2025/26', $timetable->academicYearLabel);
        // Expanded to the format this application stores years in, so the two
        // can actually be compared.
        $this->assertSame('2025/2026', $timetable->academicYearNormalised);
        $this->assertSame('Ana Exemplo', $timetable->teacherName);
    }

    #[Test]
    public function a_missing_title_block_is_not_a_reason_to_refuse_the_file(): void
    {
        $timetable = (new TimetableParser)->parse($this->document(
            "Horas\t2ª FEIRA\t3ª FEIRA\t4ª FEIRA\t5ª FEIRA\t6ª FEIRA\n08:30 - 09:20\tMAT - 7º C - S01",
            [
                [self::LABEL, 9.2, 'Horas'],
                [self::LABEL, 9.2, '2ª FEIRA'],
                [self::LABEL, 9.2, '3ª FEIRA'],
                [self::LABEL, 9.2, '4ª FEIRA'],
                [self::LABEL, 9.2, '5ª FEIRA'],
                [self::LABEL, 9.2, '6ª FEIRA'],
                [self::LABEL, 7.2, '08:30 - 09:20'],
                [self::MONDAY, 210.9, 'MAT - 7º C - S01'],
            ],
        ));

        $this->assertNull($timetable->academicYearLabel);
        $this->assertNull($timetable->teacherName);
        $this->assertCount(1, $timetable->rows);
    }

    #[Test]
    public function it_ignores_the_page_footer_drawn_in_the_row_label_column(): void
    {
        // «Rua de Exemplo - 0000-000 EXEMPLO» has three parts split by " - " and
        // sits below the last hour of the day. If it were read as a cell it
        // would both invent a lesson and drag the whole column grid leftwards,
        // moving every real lesson one weekday earlier.
        $raw = array_map(fn (TimetableCandidateRow $row): string => $row->rawText, $this->parse()->rows);

        $this->assertNotContains('Rua de Exemplo - 0000-000 EXEMPLO', $raw);
        // And the grid still starts where Monday is: 10:30 is the only hour
        // with a Monday lesson, and it is still read as Monday.
        $this->assertSame(1, $this->rowsAt($this->parse()->rows, '10:30')[0]->dayOfWeek);
    }

    #[Test]
    public function it_uses_the_heading_rows_own_positions_when_it_has_them(): void
    {
        // Not every system draws the heading relatively. When each label has a
        // place of its own, that IS the answer, and it needs no interpolation:
        // here only Monday, Wednesday and Friday are ever occupied, which the
        // data alone could not tell apart from Monday, Tuesday and Wednesday.
        $timetable = (new TimetableParser)->parse($this->document(
            "Horas\t2ª FEIRA\t3ª FEIRA\t4ª FEIRA\t5ª FEIRA\t6ª FEIRA\n08:30 - 09:20\tMAT - 7º C - S01\tMAT - 8º A - S02",
            [
                [68.4, 731.3, 'Horas'],
                [151.3, 731.3, '2ª FEIRA'],
                [239.0, 731.3, '3ª FEIRA'],
                [326.8, 731.3, '4ª FEIRA'],
                [414.5, 731.3, '5ª FEIRA'],
                [502.3, 731.3, '6ª FEIRA'],
                [36.2, 718.4, '08:30 - 09:20'],
                [124.0, 718.4, 'MAT - 7º C - S01'],
                [299.5, 718.4, 'MAT - 8º A - S02'],
                [475.0, 718.4, 'MAT - 9º B - S05'],
            ],
        ));

        $this->assertSame([1, 3, 5], array_map(
            fn (TimetableCandidateRow $row): int => $row->dayOfWeek,
            $timetable->rows,
        ));
    }

    #[Test]
    public function it_refuses_a_document_with_no_hours_at_all(): void
    {
        $this->expectException(TimetablePdfException::class);
        $this->expectExceptionMessageMatches('/bloco horário/');

        (new TimetableParser)->parse($this->document(
            "Uma circular qualquer\nsem qualquer horário",
            [[self::LABEL, 9.0, 'Uma circular qualquer'], [self::LABEL, 9.0, 'sem qualquer horário']],
        ));
    }

    #[Test]
    public function it_refuses_a_document_with_no_text_at_all(): void
    {
        $this->expectException(TimetablePdfException::class);
        // A scan. Out of scope for this import, and said so plainly rather than
        // failing as though something had gone wrong.
        $this->expectExceptionMessageMatches('/digitaliza/');

        (new TimetableParser)->parse(new ExtractedPdfDocument([new ExtractedPdfPage('', [])]));
    }

    /**
     * The reference document: the shape of a real export, with invented content.
     */
    private function parse(): ParsedTimetable
    {
        return (new TimetableParser)->parse($this->document($this->referenceText(), $this->referenceFragments()));
    }

    private function referenceText(): string
    {
        return implode("\n", [
            'Agrupamento de Escolas de Exemplo',
            'HORÁRIO PROFESSOR',
            '2025/26',
            'Ana Exemplo',
            "Horas\t2ª FEIRA\t3ª FEIRA\t4ª FEIRA\t5ª FEIRA\t6ª FEIRA",
            "08:30 - 09:20\tMAT - 7º C - S01\tMAT - 8º A - S02\tMAT - 9º B - S05",
            "09:20 - 10:10\tMAT - 7º C - S01",
            "10:30 - 11:20\tMAT - 9º B - S08",
            '12:10 - 13:30',
            "14:20 - 15:10\tMAT - 7º E - S27",
            'AE_3C_Mat - 7º C -',
            'S02',
            "16:15 - 17:05\tREE - Sem sala",
            'Rua de Exemplo - 0000-000 EXEMPLO',
        ]);
    }

    /**
     * @return list<array{float, float, string}>
     */
    private function referenceFragments(): array
    {
        return [
            [self::LABEL, 9.0, 'Agrupamento de Escolas de Exemplo'],
            [self::LABEL, 9.0, 'HORÁRIO PROFESSOR'],
            [self::LABEL, 9.0, '2025/26'],
            [self::LABEL, 9.0, 'Ana Exemplo'],
            [self::LABEL, 9.2, 'Horas'],
            [self::LABEL, 9.2, '2ª FEIRA'],
            [self::LABEL, 9.2, '3ª FEIRA'],
            [self::LABEL, 9.2, '4ª FEIRA'],
            [self::LABEL, 9.2, '5ª FEIRA'],
            [self::LABEL, 9.2, '6ª FEIRA'],

            // Monday empty, then three filled columns — one of them beyond a
            // weekday that never appears anywhere in this file.
            [self::LABEL, 7.2, '08:30 - 09:20'],
            [self::TUESDAY, 210.9, 'MAT - 7º C - S01'],
            [self::WEDNESDAY, 210.9, 'MAT - 8º A - S02'],
            [self::FRIDAY, 210.9, 'MAT - 9º B - S05'],

            // The same turma, the very next hour: two blocks, never one.
            [self::LABEL, 7.2, '09:20 - 10:10'],
            [self::TUESDAY, 230.8, 'MAT - 7º C - S01'],

            [self::LABEL, 7.2, '10:30 - 11:20'],
            [self::MONDAY, 250.6, 'MAT - 9º B - S08'],

            // Lunch: a row of the table with nothing in it.
            [self::LABEL, 7.2, '12:10 - 13:30'],

            // A cell whose text wrapped: two runs, same column, adjacent lines.
            [self::LABEL, 7.2, '14:20 - 15:10'],
            [self::MONDAY, 330.0, 'MAT - 7º E - S27'],
            [self::FRIDAY, 325.4, 'AE_3C_Mat - 7º C -'],
            [self::FRIDAY, 334.6, 'S02'],

            [self::LABEL, 7.2, '16:15 - 17:05'],
            [self::WEDNESDAY, 369.7, 'REE - Sem sala'],

            // Printed in the same left margin as the hours, and split by " - "
            // exactly like a lesson.
            [self::LABEL, 9.0, 'Rua de Exemplo - 0000-000 EXEMPLO'],
        ];
    }

    /**
     * @param  list<array{float, float, string}>  $fragments
     */
    private function document(string $text, array $fragments): ExtractedPdfDocument
    {
        return new ExtractedPdfDocument([new ExtractedPdfPage($text, array_map(
            fn (array $fragment): PositionedTextFragment => new PositionedTextFragment(
                $fragment[0],
                $fragment[1],
                $fragment[2],
            ),
            $fragments,
        ))]);
    }

    /**
     * @param  list<TimetableCandidateRow>  $rows
     * @return list<TimetableCandidateRow>
     */
    private function rowsAt(array $rows, string $startsAt): array
    {
        return array_values(array_filter(
            $rows,
            fn (TimetableCandidateRow $row): bool => $row->startsAt === $startsAt,
        ));
    }

    /**
     * @param  list<TimetableCandidateRow>  $rows
     * @return list<TimetableCandidateRow>
     */
    private function rowsFor(array $rows, int $dayOfWeek): array
    {
        return array_values(array_filter(
            $rows,
            fn (TimetableCandidateRow $row): bool => $row->dayOfWeek === $dayOfWeek,
        ));
    }
}
