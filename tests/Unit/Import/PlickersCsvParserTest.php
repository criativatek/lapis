<?php

namespace Tests\Unit\Import;

use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\CanonicalResult;
use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportIssue;
use App\Domain\Import\Correction\IssueCode;
use App\Services\Import\Correction\PlickersCsvParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Plickers reader, against fixtures whose shape was taken from a real export
 * and whose content is entirely invented.
 *
 * The fixture is not decoration. A Plickers header row IS the question text, so
 * it contains commas and colons and quotation marks; the export carries a BOM;
 * and a student who did not take part is written with «-» rather than left
 * blank. Every one of those broke something naive at least once, so each has a
 * test rather than a comment.
 */
class PlickersCsvParserTest extends TestCase
{
    protected function fixture(string $name): string
    {
        return base_path('tests/Fixtures/Import/'.$name);
    }

    protected function parse(string $name = 'plickers-basico.csv'): CanonicalCorrectionGrid
    {
        return (new PlickersCsvParser)->parse($this->fixture($name), $name);
    }

    protected function resultFor(CanonicalCorrectionGrid $grid, string $student, string $item): ?CanonicalResult
    {
        foreach ($grid->results as $result) {
            if ($result->studentSourceKey === $student && $result->itemSourceKey === $item) {
                return $result;
            }
        }

        return null;
    }

    #[Test]
    public function it_reads_a_valid_export(): void
    {
        $grid = $this->parse();

        $this->assertSame(CorrectionGridSource::Plickers, $grid->source);
        $this->assertCount(3, $grid->students);
        $this->assertCount(3, $grid->items);
        $this->assertCount(9, $grid->results, '3 alunos x 3 perguntas.');
    }

    #[Test]
    public function it_reads_the_same_file_with_a_bom_and_crlf_line_endings(): void
    {
        // Excel writes both. Left unhandled, the BOM becomes part of the first
        // header cell and «Card Number» stops matching itself.
        $plain = $this->parse();
        $awkward = $this->parse('plickers-bom-crlf.csv');

        $this->assertCount(count($plain->students), $awkward->students);
        $this->assertCount(count($plain->items), $awkward->items);
        $this->assertSame($plain->items[0]->questionText, $awkward->items[0]->questionText);
    }

    #[Test]
    public function a_question_containing_commas_stays_one_question(): void
    {
        // The third fixture question has commas inside its quoted header. A
        // naive explode(',') turns one question into three and every column
        // after it lands on the wrong question (§47).
        $grid = $this->parse();

        $this->assertCount(3, $grid->items);
        $this->assertStringContainsString('incluindo vírgulas', (string) $grid->items[2]->questionText);
    }

    #[Test]
    public function it_reads_the_students_and_their_cards(): void
    {
        $grid = $this->parse();

        $cards = array_map(fn ($student) => $student->cardNumber, $grid->students);
        $this->assertSame(['1', '2', '3'], $cards);
        $this->assertSame('Ana Exemplo', $grid->students[0]->displayName);
    }

    #[Test]
    public function it_reads_the_questions_their_text_and_their_external_ids(): void
    {
        $grid = $this->parse();

        $this->assertSame('A', $grid->items[0]->code, 'A letra que o aluno viu no papel serve de código.');
        $this->assertStringContainsString('capital de um país', (string) $grid->items[0]->questionText);
        $this->assertSame('https://exemplo.invalido/q/aaa111', $grid->items[0]->externalId);
        $this->assertTrue($grid->sourceMetadata['has_external_ids']);
    }

    #[Test]
    public function it_reads_the_answer_key(): void
    {
        $grid = $this->parse();

        $this->assertSame(['A', 'B', 'C'], array_map(fn ($item) => $item->answerKey, $grid->items));
        $this->assertTrue($grid->sourceMetadata['has_answer_key']);
    }

    #[Test]
    public function it_reads_each_response_without_turning_it_into_a_mark(): void
    {
        $grid = $this->parse();

        $result = $this->resultFor($grid, 'student:2', 'item:7');

        $this->assertSame('C', $result?->rawResponse);
        $this->assertNull($result?->pointsEarned, 'Uma resposta não é uma classificação (§17).');
    }

    #[Test]
    public function it_identifies_a_wrong_answer_without_scoring_it(): void
    {
        $grid = $this->parse();

        // Bruno answered C where the key says B.
        $wrong = $this->resultFor($grid, 'student:2', 'item:7');
        $right = $this->resultFor($grid, 'student:1', 'item:7');

        $this->assertFalse($wrong?->isCorrect);
        $this->assertTrue($right?->isCorrect);
        $this->assertNull($wrong?->pointsEarned);
    }

    #[Test]
    public function an_unanswered_question_is_not_a_wrong_answer(): void
    {
        // Carla's row is «-» across the board with Answered = 0. That is a
        // student who did not take part, which is neither zero nor absent —
        // both of those are decisions somebody makes, not readings of a dash.
        $grid = $this->parse();

        foreach (['item:6', 'item:7', 'item:8'] as $item) {
            $result = $this->resultFor($grid, 'student:3', $item);

            $this->assertNull($result?->rawResponse);
            $this->assertNull($result?->isCorrect, 'Não responder não é responder errado.');
            $this->assertNull($result?->pointsEarned, 'Não responder não é zero.');
            $this->assertNull($result?->resultState, 'Não responder não é uma falta.');
            $this->assertSame('-', $result?->sourceValue, 'O que o ficheiro dizia fica registado.');
        }
    }

    #[Test]
    public function score_correct_and_answered_are_summaries_not_results(): void
    {
        $grid = $this->parse();

        $summaries = [];

        foreach ($grid->summariesForStudent('student:1') as $summary) {
            $summaries[$summary->key] = $summary->value;
        }

        $this->assertSame('100%', $summaries['score']);
        $this->assertSame('3', $summaries['correct']);
        $this->assertSame('3', $summaries['answered']);

        // And the student object carries the same facts for the preview.
        $this->assertSame(3, $grid->students[0]->sourceCorrect);
        $this->assertSame(3, $grid->students[0]->sourceAnswered);

        // The one that matters: none of that produced a mark anywhere.
        foreach ($grid->results as $result) {
            $this->assertNull($result->pointsEarned, 'Nenhum summary se transformou em classificação (§49).');
        }
    }

    #[Test]
    public function a_student_who_took_no_part_has_a_dash_score_read_as_absent_information(): void
    {
        $grid = $this->parse();

        $carla = $grid->students[2];

        $this->assertNull($carla->sourceScore, '«-» não é uma percentagem.');
        $this->assertSame(0, $carla->sourceAnswered);
        $this->assertTrue($carla->answeredNothing());
    }

    #[Test]
    public function it_says_plainly_that_plickers_declares_no_marks(): void
    {
        $grid = $this->parse();

        $codes = array_map(fn (ImportIssue $issue) => $issue->code, $grid->issues);

        $this->assertContains(IssueCode::MissingPoints, $codes);
        $this->assertFalse($grid->canBeConfirmed(), 'Sem cotação decidida, não há nada a confirmar (§51).');
        $this->assertCount(3, $grid->itemsWithoutDeclaredWorth());
    }

    #[Test]
    public function it_reports_the_blank_answers_it_found(): void
    {
        $grid = $this->parse();

        $codes = array_map(fn (ImportIssue $issue) => $issue->code, $grid->issues);

        $this->assertContains(IssueCode::UnansweredQuestion, $codes);
    }

    #[Test]
    public function plickers_has_no_sections_so_the_single_group_stays_invisible(): void
    {
        $grid = $this->parse();

        $this->assertCount(1, $grid->groups);
        $this->assertTrue($grid->groups[0]->isImplicit(), 'Um «Grupo 1» que o professor nunca criou não deve aparecer (§54).');
        $this->assertNull($grid->groups[0]->label);
    }

    #[Test]
    public function it_offers_the_export_title_as_a_suggestion_and_invents_no_date(): void
    {
        $grid = $this->parse();

        $this->assertSame('Teste de Exemplo - 7X (25/26)', $grid->instrument->title);

        // «(25/26)» is a school year. Reading it as a date would place the
        // instrument at the wrong point in time, and the date decides which
        // students it even applies to (§11.4, §13).
        $this->assertNull($grid->instrument->appliedOn);
    }

    #[Test]
    public function a_file_it_cannot_read_fails_loudly_and_says_nothing_about_its_contents(): void
    {
        $grid = $this->parse('plickers-malformado.csv');

        $this->assertFalse($grid->canBeConfirmed());
        $this->assertSame(IssueCode::UnsupportedStructure, $grid->errors()[0]->code);
        $this->assertSame([], $grid->students);
        $this->assertSame([], $grid->results);

        // The message explains the shape, never quotes the file: these carry
        // students' names (§26).
        $this->assertStringNotContainsString('isto nao e um csv', $grid->errors()[0]->message);
    }

    #[Test]
    public function it_declines_files_it_should_not_attempt(): void
    {
        $parser = new PlickersCsvParser;

        $this->assertTrue($parser->supports($this->fixture('plickers-basico.csv'), 'plickers-basico.csv'));
        $this->assertFalse($parser->supports($this->fixture('plickers-malformado.csv'), 'plickers-malformado.csv'));
        $this->assertFalse($parser->supports($this->fixture('plickers-basico.csv'), 'grelha.pdf'), 'PDF não é suportado (§36).');
        $this->assertFalse($parser->supports($this->fixture('plickers-basico.csv'), 'grelha.xlsx'));
    }
}
