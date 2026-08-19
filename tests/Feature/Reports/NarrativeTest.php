<?php

namespace Tests\Feature\Reports;

use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The sentence layer (§42).
 *
 * These look small and are not. «1 alunos», «66.4%» and «Leitura, Escrita e,
 * Oralidade» are the tells that a document was assembled by a machine, and the
 * moment a teacher spots one they stop trusting every number beside it.
 *
 * The Absence tests are the more important half: they pin down that the module
 * cannot accidentally turn silence into good news (§41, §67).
 */
class NarrativeTest extends TestCase
{
    #[Test]
    public function it_agrees_in_number(): void
    {
        $this->assertSame('nenhum aluno', Phrase::students(0));
        $this->assertSame('um aluno', Phrase::students(1));
        $this->assertSame('seis alunos', Phrase::students(6));
        // Beyond ten the numeral is easier to read than the words.
        $this->assertSame('26 alunos', Phrase::students(26));

        $this->assertSame('um registo', Phrase::records(1));
        $this->assertSame('18 registos', Phrase::records(18));
    }

    #[Test]
    public function a_group_of_zero_takes_a_singular_verb(): void
    {
        // «nenhum aluno mantiveram» is the single most visible grammatical
        // failure this module can produce (§18).
        $this->assertSame('nenhum aluno manteve', Phrase::studentsDid(0, 'manteve', 'mantiveram'));
        $this->assertSame('um aluno manteve', Phrase::studentsDid(1, 'manteve', 'mantiveram'));
        $this->assertSame('quatro alunos mantiveram', Phrase::studentsDid(4, 'manteve', 'mantiveram'));
    }

    #[Test]
    public function small_numbers_are_written_out_and_agree_in_gender(): void
    {
        $this->assertSame('um', Phrase::spelled(1));
        $this->assertSame('uma', Phrase::spelled(1, feminine: true));
        $this->assertSame('dois', Phrase::spelled(2));
        $this->assertSame('duas', Phrase::spelled(2, feminine: true));
        $this->assertSame('dez', Phrase::spelled(10));
        $this->assertSame('11', Phrase::spelled(11));
    }

    #[Test]
    public function a_date_inside_a_sentence_is_written_out_in_lower_case(): void
    {
        $this->assertSame(
            '19 de agosto de 2026',
            Phrase::date(new \DateTimeImmutable('2026-08-19')),
        );

        $this->assertSame(
            '1 de março de 2027',
            Phrase::date(new \DateTimeImmutable('2027-03-01')),
        );
    }

    #[Test]
    public function it_writes_decimals_the_portuguese_way(): void
    {
        $this->assertSame('66,4', Phrase::number('66.4'));
        $this->assertSame('66,4', Phrase::number('66.40'));
        $this->assertSame('66', Phrase::number('66.00'));
        $this->assertSame('66,4%', Phrase::percentage('66.4'));
        $this->assertSame('100%', Phrase::percentage('100.0'));
    }

    #[Test]
    public function absence_of_a_figure_is_null_and_never_zero(): void
    {
        $this->assertNull(Phrase::number(null));
        $this->assertNull(Phrase::percentage(null));
        $this->assertNull(Phrase::number(''));
        // A genuine zero is still a zero — the distinction is null vs 0 (§41).
        $this->assertSame('0%', Phrase::percentage('0'));
    }

    #[Test]
    public function it_joins_lists_without_a_comma_before_the_conjunction(): void
    {
        $this->assertSame('', Phrase::items([]));
        $this->assertSame('a Leitura', Phrase::items(['a Leitura']));
        $this->assertSame('a Leitura e a Escrita', Phrase::items(['a Leitura', 'a Escrita']));
        $this->assertSame(
            'a Leitura, a Escrita e a Oralidade',
            Phrase::items(['a Leitura', 'a Escrita', 'a Oralidade']),
        );
    }

    #[Test]
    public function a_sentence_drops_empty_clauses_instead_of_leaving_double_spaces(): void
    {
        $this->assertSame(
            'A turma obteve resultados positivos.',
            Phrase::sentence('a turma', null, 'obteve', '', 'resultados positivos'),
        );

        $this->assertSame('', Phrase::sentence(null, '', null));
    }

    #[Test]
    public function a_clause_that_opens_with_a_comma_does_not_leave_a_space_before_it(): void
    {
        // Composers legitimately hand over «, e o mais baixo» as a continuing
        // clause. The seam must not show.
        $this->assertSame(
            'Leitura (72,1%), e o mais baixo Escrita.',
            Phrase::sentence('Leitura (72,1%)', ', e o mais baixo', 'Escrita'),
        );

        $this->assertSame('Uma citação «assim».', Phrase::sentence('uma citação «', 'assim', '»'));
    }

    #[Test]
    public function a_sentence_is_capitalised_and_terminated_once(): void
    {
        $this->assertSame('Uma frase.', Phrase::sentence('uma frase'));
        $this->assertSame('Uma frase.', Phrase::sentence('uma frase.'));
        $this->assertSame('Uma pergunta?', Phrase::sentence('uma pergunta?'));
        // mb-safe: a sentence may legitimately open on an accented letter.
        $this->assertSame('Ótimo resultado.', Phrase::sentence('ótimo resultado'));
    }

    #[Test]
    public function it_says_all_of_them_rather_than_twenty_six_of_twenty_six(): void
    {
        $this->assertSame('os 26 alunos', Phrase::outOfTotal(26, 26));
        $this->assertSame('24 de 26 alunos', Phrase::outOfTotal(24, 26));
        $this->assertSame('o único aluno', Phrase::outOfTotal(1, 1));
    }

    #[Test]
    public function paragraphs_and_bodies_drop_what_is_empty(): void
    {
        $this->assertSame('Uma. Duas.', Phrase::paragraph(['Uma.', null, '', 'Duas.']));
        $this->assertSame("Uma.\n\nDuas.", Phrase::body(['Uma.', '', null, 'Duas.']));
        $this->assertSame('', Phrase::body([null, '', '   ']));
    }

    // ------------------------------------------------------------- absence

    #[Test]
    public function no_records_never_becomes_no_problems(): void
    {
        $sentence = Absence::noRecords('ocorrências disciplinares');

        $this->assertStringContainsString('Não foram encontrados registos', $sentence);
        $this->assertStringNotContainsString('não houve', mb_strtolower($sentence));
        $this->assertStringNotContainsString('sem problemas', mb_strtolower($sentence));
    }

    #[Test]
    public function no_registered_intervention_never_becomes_none_was_needed(): void
    {
        $sentence = Absence::noInterventions();

        $this->assertStringContainsString('Não foram registadas intervenções', $sentence);
        $this->assertStringNotContainsString('necessári', mb_strtolower($sentence));
    }

    #[Test]
    public function no_classification_never_becomes_a_failure(): void
    {
        $sentence = Absence::noClassifications();

        $this->assertStringContainsString('Ainda não foram atribuídas', $sentence);
        $this->assertStringNotContainsString('negativ', mb_strtolower($sentence));
        $this->assertStringNotContainsString('insucesso', mb_strtolower($sentence));
    }

    #[Test]
    public function students_without_a_result_are_named_as_such_and_kept_out_of_the_means(): void
    {
        $this->assertNull(Absence::studentsWithoutResult(0));

        $sentence = Absence::studentsWithoutResult(3);

        $this->assertNotNull($sentence);
        $this->assertStringContainsString('3 alunos não têm ainda resultado', $sentence);
        $this->assertStringContainsString('não entram nas médias', $sentence);
        // Never phrased as a zero or a failure.
        $this->assertStringNotContainsString('zero', mb_strtolower($sentence));
    }

    #[Test]
    public function an_unanswered_characterisation_says_who_has_not_spoken(): void
    {
        $sentence = Absence::notCharacterised('comportamento');

        $this->assertSame('Não foi registada caracterização de comportamento.', $sentence);
    }
}
