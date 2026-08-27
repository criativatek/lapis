<?php

namespace Tests\Unit\Import;

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
use App\Models\ResultState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The canonical grid is the seam every future format has to fit through, so
 * these tests are less about behaviour than about shape: they pin the
 * distinctions that would be cheap to collapse now and expensive to recover
 * later.
 *
 * Three of them matter more than the rest. Group is not Domain. A summary is not
 * a mark. And an unresolved cell is not a zero — which is the same rule the
 * assessment engine has kept since the beginning, arriving here from a new
 * direction.
 *
 * No parser is exercised. These are plain objects and the point is that they
 * stay plain: nothing here knows what a row, a column or a worksheet is.
 */
class CanonicalCorrectionGridTest extends TestCase
{
    #[Test]
    public function a_grid_survives_a_source_that_states_almost_nothing(): void
    {
        // Plickers states no date, no total, no external id — and that has to be
        // representable without a single invented value (§13).
        $grid = new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Plickers,
            instrument: new CanonicalInstrument,
        );

        $this->assertNull($grid->instrument->title);
        $this->assertNull($grid->instrument->appliedOn);
        $this->assertNull($grid->instrument->sourceTotal);
        $this->assertTrue($grid->canBeConfirmed());
    }

    #[Test]
    public function a_group_is_structure_and_says_nothing_about_curriculum(): void
    {
        // A source that names its section «Leitura» has named a section. What
        // the questions inside it assess is a separate decision the teacher
        // makes, and nothing may derive one from the other (§9).
        $group = new CanonicalGroup(sourceKey: 'g:1', sequence: 1, label: 'Leitura');
        $item = new CanonicalItem(sourceKey: 'i:1', sequence: 1, groupSourceKey: 'g:1');

        $this->assertSame('Leitura', $group->label);
        $this->assertNull($item->domainHint, 'Pertencer a um grupo chamado «Leitura» não atribui domínio nenhum.');
    }

    #[Test]
    public function the_same_code_may_exist_in_two_different_groups(): void
    {
        // A real test paper numbers its questions from 1 inside each group. Any
        // model that treats a code as identity merges these two into one.
        $grid = new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Intuitivo,
            instrument: new CanonicalInstrument,
            groups: [
                new CanonicalGroup(sourceKey: 'g:1', sequence: 1, label: 'Grupo I'),
                new CanonicalGroup(sourceKey: 'g:2', sequence: 2, label: 'Grupo II'),
            ],
            items: [
                new CanonicalItem(sourceKey: 'i:1', sequence: 1, groupSourceKey: 'g:1', code: 'Q1'),
                new CanonicalItem(sourceKey: 'i:2', sequence: 2, groupSourceKey: 'g:2', code: 'Q1'),
            ],
        );

        $this->assertCount(2, $grid->items);
        $this->assertSame('Q1', $grid->item('i:1')?->code);
        $this->assertSame('Q1', $grid->item('i:2')?->code);
        $this->assertNotSame($grid->item('i:1')?->groupSourceKey, $grid->item('i:2')?->groupSourceKey);

        $this->assertCount(1, $grid->itemsInGroup('g:1'));
        $this->assertCount(1, $grid->itemsInGroup('g:2'));
    }

    #[Test]
    public function an_unresolved_cell_is_not_a_zero(): void
    {
        $blank = new CanonicalResult(studentSourceKey: 's:1', itemSourceKey: 'i:1');
        $zero = new CanonicalResult(studentSourceKey: 's:2', itemSourceKey: 'i:1', pointsEarned: '0', resultState: ResultState::Assessed);

        $this->assertFalse($blank->hasDeterminedMark());
        $this->assertNull($blank->pointsEarned);

        // The other half of the same rule: a determined zero IS a mark, and
        // anything treating it as emptiness is the same bug wearing a hat.
        $this->assertTrue($zero->hasDeterminedMark());
        $this->assertSame('0', $zero->pointsEarned);
    }

    #[Test]
    public function a_response_is_not_a_mark(): void
    {
        // «C» is what the student wrote. It becomes a mark only once both the
        // answer key and the question's worth are known (§17).
        $result = new CanonicalResult(
            studentSourceKey: 's:1',
            itemSourceKey: 'i:1',
            rawResponse: 'C',
            isCorrect: false,
        );

        $this->assertSame('C', $result->rawResponse);
        $this->assertNull($result->pointsEarned);
        $this->assertFalse($result->hasDeterminedMark());
    }

    #[Test]
    public function an_answer_key_alone_produces_no_mark(): void
    {
        $item = new CanonicalItem(sourceKey: 'i:1', sequence: 1, answerKey: 'B');

        $this->assertSame('B', $item->answerKey);
        $this->assertNull($item->pointsPossible);
        $this->assertFalse($item->hasDeclaredWorth());
    }

    #[Test]
    public function a_mark_appears_only_once_the_question_has_a_declared_worth(): void
    {
        $correct = new CanonicalResult(studentSourceKey: 's:1', itemSourceKey: 'i:1', rawResponse: 'B', isCorrect: true);
        $wrong = new CanonicalResult(studentSourceKey: 's:2', itemSourceKey: 'i:1', rawResponse: 'C', isCorrect: false);
        $unanswered = new CanonicalResult(studentSourceKey: 's:3', itemSourceKey: 'i:1');

        $this->assertSame('2.5', $correct->resolvedAgainst('2.5')->pointsEarned);
        $this->assertSame('0', $wrong->resolvedAgainst('2.5')->pointsEarned);

        // And the one that must not move: nobody judged this cell, so declaring
        // a cotação does not turn it into a zero (§68).
        $this->assertNull($unanswered->resolvedAgainst('2.5')->pointsEarned);
        $this->assertNull($unanswered->resolvedAgainst('2.5')->resultState);
    }

    #[Test]
    public function summaries_are_kept_apart_from_results(): void
    {
        // «75%» is a conclusion the source drew. Lapispro draws its own, and the
        // day the two disagree the reason must be visible rather than merged
        // away (§19, §75).
        $grid = new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Plickers,
            instrument: new CanonicalInstrument,
            students: [new CanonicalStudent(sourceKey: 's:1', sourceScore: '75%')],
            results: [new CanonicalResult(studentSourceKey: 's:1', itemSourceKey: 'i:1', rawResponse: 'B')],
            summaries: [new CanonicalSummary(
                scope: CanonicalSummary::SCOPE_STUDENT,
                key: 'score',
                subjectSourceKey: 's:1',
                value: '75%',
                unit: 'percent',
            )],
        );

        $this->assertCount(1, $grid->results);
        $this->assertCount(1, $grid->summaries);
        $this->assertCount(1, $grid->summariesForStudent('s:1'));
        $this->assertNull($grid->results[0]->pointsEarned, 'Um summary não pode preencher uma classificação.');
    }

    #[Test]
    public function an_issue_is_identified_by_a_code_not_by_its_wording(): void
    {
        $issue = ImportIssue::make(IssueCode::MissingPoints, 'Qualquer texto, que há-de mudar.');

        $this->assertSame(IssueCode::MissingPoints, $issue->code);
        $this->assertSame(IssueSeverity::Error, $issue->severity);
        $this->assertTrue($issue->blocksConfirmation());
    }

    #[Test]
    public function an_error_blocks_confirmation_and_a_warning_does_not(): void
    {
        $withWarning = new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Plickers,
            instrument: new CanonicalInstrument,
            issues: [ImportIssue::make(IssueCode::UnknownStudent, 'Aluno por identificar.')],
        );

        $withError = $withWarning->withIssues([ImportIssue::make(IssueCode::UnsupportedStructure, 'Não sei ler isto.')]);

        $this->assertTrue($withWarning->canBeConfirmed());
        $this->assertCount(1, $withWarning->warnings());

        $this->assertFalse($withError->canBeConfirmed());
        $this->assertCount(1, $withError->errors());
    }

    #[Test]
    public function source_specific_detail_travels_in_metadata_and_not_in_the_core(): void
    {
        // The point of the seam: a spreadsheet's worksheet and a Plickers URL
        // both fit, and neither becomes a column every other format has to have.
        $grid = new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Generic,
            instrument: new CanonicalInstrument,
            items: [new CanonicalItem(sourceKey: 'i:1', sequence: 1, metadata: ['worksheet' => 'Notas', 'cell' => 'D7'])],
            sourceMetadata: ['sheets' => ['Notas', 'Domínios'], 'header_rows' => 3],
        );

        $this->assertSame('Notas', $grid->item('i:1')?->metadata['worksheet']);
        $this->assertSame(3, $grid->sourceMetadata['header_rows']);
    }

    #[Test]
    public function the_three_known_families_all_fit_the_same_model(): void
    {
        // Compatibility rehearsal, without a parser in sight: if any of these
        // three cannot be said in this vocabulary, the vocabulary is wrong and
        // now is the cheap moment to find out (§41).

        // PLICKERS: card, response, answer key, and the source's own summary.
        $plickers = new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Plickers,
            instrument: new CanonicalInstrument,
            groups: [CanonicalGroup::implicit()],
            items: [new CanonicalItem(sourceKey: 'i:1', sequence: 1, groupSourceKey: 'group:0', answerKey: 'B')],
            students: [new CanonicalStudent(sourceKey: 's:1', cardNumber: '17', sourceScore: '75%', sourceCorrect: 15, sourceAnswered: 20)],
            results: [new CanonicalResult(studentSourceKey: 's:1', itemSourceKey: 'i:1', rawResponse: 'C', isCorrect: false)],
        );

        $this->assertTrue($plickers->groups[0]->isImplicit());
        $this->assertSame('17', $plickers->students[0]->cardNumber);
        $this->assertFalse($plickers->results[0]->isCorrect);

        // INTUITIVO: real sections, and Q1 inside each of them.
        $intuitivo = new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Intuitivo,
            instrument: new CanonicalInstrument(sourceTotal: '100'),
            groups: [
                new CanonicalGroup(sourceKey: 'g:1', sequence: 1, label: 'Grupo I'),
                new CanonicalGroup(sourceKey: 'g:2', sequence: 2, label: 'Grupo II'),
            ],
            items: [
                new CanonicalItem(sourceKey: 'i:1', sequence: 1, groupSourceKey: 'g:1', code: 'Q1', pointsPossible: '10'),
                new CanonicalItem(sourceKey: 'i:2', sequence: 2, groupSourceKey: 'g:2', code: 'Q1', pointsPossible: '15'),
            ],
        );

        $this->assertSame('Q1', $intuitivo->itemsInGroup('g:1')[0]->code);
        $this->assertSame('Q1', $intuitivo->itemsInGroup('g:2')[0]->code);
        $this->assertTrue($intuitivo->itemsWithoutDeclaredWorth() === []);

        // GENÉRICO: an item, a total, a percentage and a qualitative remark —
        // the last three as summaries, because none of them is a question.
        $generic = new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Generic,
            instrument: new CanonicalInstrument,
            items: [new CanonicalItem(sourceKey: 'i:1', sequence: 1, pointsPossible: '20')],
            students: [new CanonicalStudent(sourceKey: 's:1')],
            summaries: [
                new CanonicalSummary(scope: CanonicalSummary::SCOPE_STUDENT, key: 'total', subjectSourceKey: 's:1', value: '84'),
                new CanonicalSummary(scope: CanonicalSummary::SCOPE_STUDENT, key: 'percentage', subjectSourceKey: 's:1', value: '84', unit: 'percent'),
                new CanonicalSummary(scope: CanonicalSummary::SCOPE_STUDENT, key: 'appraisal', subjectSourceKey: 's:1', value: 'Bom'),
            ],
        );

        $this->assertCount(1, $generic->items, 'Total, percentagem e apreciação não são questões.');
        $this->assertCount(3, $generic->summariesForStudent('s:1'));
    }

    #[Test]
    public function the_grid_survives_a_json_round_trip(): void
    {
        // It is stored as JSON and handed to the interface as JSON, so anything
        // that only exists as a PHP object is lost between the two.
        $grid = new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Plickers,
            instrument: new CanonicalInstrument(title: 'Exemplo'),
            groups: [CanonicalGroup::implicit()],
            items: [new CanonicalItem(sourceKey: 'i:1', sequence: 1, answerKey: 'B')],
            students: [new CanonicalStudent(sourceKey: 's:1', cardNumber: '1')],
            results: [new CanonicalResult(studentSourceKey: 's:1', itemSourceKey: 'i:1', rawResponse: 'B', isCorrect: true)],
            issues: [ImportIssue::make(IssueCode::MissingPoints, 'Sem cotação.')],
        );

        $decoded = json_decode((string) json_encode($grid->toArray()), true);

        $this->assertSame('plickers', $decoded['source']);
        $this->assertSame('B', $decoded['items'][0]['answer_key']);
        $this->assertNull($decoded['items'][0]['points_possible']);
        $this->assertTrue($decoded['results'][0]['is_correct']);
        $this->assertNull($decoded['results'][0]['points_earned']);
        $this->assertSame('missing_points', $decoded['issues'][0]['code']);
        $this->assertSame('error', $decoded['issues'][0]['severity']);
    }
}
