<?php

namespace Tests\Unit\Reporting;

use App\Services\Reporting\Writing\ProtectedFacts;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The mechanism the whole feature rests on (§11, §13, §14).
 *
 * These are unit tests on purpose. Everything here is a pure string
 * transformation, and a bug in it is the bug that puts a wrong percentage in a
 * report about a real class — it deserves to be pinned down without a database,
 * a tenant or an HTTP request in the way.
 */
class ProtectedFactsTest extends TestCase
{
    #[Test]
    public function percentages_never_reach_the_model(): void
    {
        $facts = ProtectedFacts::extract(
            'A Média Ponderada Acumulada da turma foi de 60,3% e a taxa de sucesso foi de 83,3%.',
        );

        $this->assertStringNotContainsString('60,3', $facts->redacted);
        $this->assertStringNotContainsString('83,3', $facts->redacted);
        $this->assertStringNotContainsString('%', $facts->redacted);

        // The invariant the guard rests on: what goes out has no digit in it at
        // all, so any digit that comes back was written by the model.
        $this->assertDoesNotMatchRegularExpression('/\d/u', $facts->redacted);
    }

    #[Test]
    public function the_original_figures_come_back_untouched(): void
    {
        $original = 'A Média Ponderada Acumulada da turma foi de 60,3% e a taxa de sucesso foi de 83,3%.';

        $facts = ProtectedFacts::extract($original);

        $this->assertSame($original, $facts->restore($facts->redacted));
    }

    #[Test]
    public function a_rewrite_that_moves_the_markers_still_restores_the_same_numbers(): void
    {
        $facts = ProtectedFacts::extract(
            'A Média Ponderada Acumulada da turma foi de 60,3% e a taxa de sucesso foi de 83,3%.',
        );

        [$first, $second] = array_keys($facts->values);

        $rewritten = "A turma registou uma taxa de sucesso de {$second}, com Média Ponderada Acumulada de {$first}.";

        $restored = $facts->restore($rewritten);

        $this->assertStringContainsString('83,3%', $restored);
        $this->assertStringContainsString('60,3%', $restored);
        $this->assertStringNotContainsString('61,3', $restored);
    }

    #[Test]
    public function the_same_value_twice_is_the_same_marker(): void
    {
        $facts = ProtectedFacts::extract('Subiu de 60,3% para 72,0%, tendo partido de 60,3%.');

        $this->assertCount(2, $facts->values);
        $this->assertSame(2, $facts->expectedCounts()[array_key_first($facts->values)]);
    }

    #[Test]
    public function levels_travel_whole_so_the_word_cannot_be_swapped_for_a_mention(): void
    {
        $facts = ProtectedFacts::extract('Um aluno obteve nível 2, um nível 3 e quatro nível 4.');

        // The three level references and the spelled «quatro». «Um» is
        // deliberately left alone — see the class docblock.
        $this->assertContains('nível 2', $facts->values);
        $this->assertContains('nível 3', $facts->values);
        $this->assertContains('nível 4', $facts->values);
        $this->assertContains('quatro', $facts->values);

        $this->assertStringNotContainsString('2', $facts->redacted);
        $this->assertStringNotContainsString('quatro', $facts->redacted);
    }

    #[Test]
    public function spelled_out_quantities_are_protected_like_digits(): void
    {
        $facts = ProtectedFacts::extract('Três alunos integraram a turma e dois deixaram de a integrar.');

        $this->assertContains('três', $facts->values);
        $this->assertContains('dois', $facts->values);
        $this->assertStringNotContainsString('rês', $facts->redacted);
    }

    #[Test]
    public function a_quantity_that_moves_out_of_the_first_position_stops_being_capitalised(): void
    {
        $facts = ProtectedFacts::extract('Três alunos integraram a turma após o início do ano letivo.');

        $marker = array_key_first($facts->values);

        $this->assertSame(
            'Integraram a turma, após o início do ano letivo, três alunos.',
            $facts->restore("Integraram a turma, após o início do ano letivo, {$marker} alunos."),
        );
    }

    #[Test]
    public function a_quantity_that_moves_into_the_first_position_is_capitalised(): void
    {
        $facts = ProtectedFacts::extract('A turma perdeu três alunos.');

        $marker = array_key_first($facts->values);

        $this->assertSame('Três alunos deixaram a turma.', $facts->restore("{$marker} alunos deixaram a turma."));
    }

    #[Test]
    public function dates_are_taken_whole_rather_than_digit_by_digit(): void
    {
        $facts = ProtectedFacts::extract('O relatório reporta-se ao intervalo entre 12 de março de 2026 e 30/06/2026.');

        $this->assertContains('12 de março de 2026', $facts->values);
        $this->assertContains('30/06/2026', $facts->values);
        $this->assertCount(2, $facts->values);
    }

    #[Test]
    public function the_temporal_scope_is_a_protected_fact(): void
    {
        $facts = ProtectedFacts::extract('No segundo período, a turma manteve o desempenho.');

        $this->assertContains('segundo período', $facts->values);
        $this->assertStringNotContainsString('segundo', $facts->redacted);
    }

    #[Test]
    public function an_invented_marker_is_detected(): void
    {
        $facts = ProtectedFacts::extract('A taxa de sucesso foi de 83,3%.');

        $this->assertSame(['[[FZ]]'], $facts->unknownMarkersIn('A taxa de sucesso foi de [[FZ]].'));
        $this->assertSame([], $facts->unknownMarkersIn($facts->redacted));
    }

    #[Test]
    public function text_with_nothing_to_protect_survives_untouched(): void
    {
        $facts = ProtectedFacts::extract('A turma manteve o desempenho.');

        $this->assertTrue($facts->isEmpty());
        $this->assertSame('A turma manteve o desempenho.', $facts->redacted);
    }
}
