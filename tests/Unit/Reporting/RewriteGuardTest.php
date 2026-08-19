<?php

namespace Tests\Unit\Reporting;

use App\Services\Reporting\Writing\ProtectedFacts;
use App\Services\Reporting\Writing\RewriteGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What happens when the engine agrees to the rules and breaks them anyway
 * (§12, §13, §14, §44).
 *
 * Every test here hands the guard an answer a real model could plausibly
 * produce — helpful, fluent, and wrong in one specific way — and asserts it is
 * refused. The refusals are the feature.
 */
class RewriteGuardTest extends TestCase
{
    protected RewriteGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new RewriteGuard;
    }

    // ------------------------------------------------------------- os números

    #[Test]
    public function a_changed_percentage_cannot_even_be_expressed(): void
    {
        $facts = ProtectedFacts::extract(
            'A Média Ponderada Acumulada da turma foi de 60,3% e a taxa de sucesso foi de 83,3%.',
        );

        // The nearest thing to «61,3%» an engine can do is write a digit, and
        // the text it received had none.
        $verdict = $this->guard->inspect($facts, 'A Média Ponderada Acumulada da turma foi de 61,3%.');

        $this->assertFalse($verdict->acceptable);
        $this->assertSame('loose_digit', $verdict->reason);
    }

    #[Test]
    public function dropping_a_figure_is_refused(): void
    {
        $facts = ProtectedFacts::extract(
            'A Média Ponderada Acumulada da turma foi de 60,3% e a taxa de sucesso foi de 83,3%.',
        );

        [$first] = array_keys($facts->values);

        $verdict = $this->guard->inspect($facts, "A Média Ponderada Acumulada da turma foi de {$first}.");

        $this->assertFalse($verdict->acceptable);
        $this->assertSame('marker_count', $verdict->reason);
    }

    #[Test]
    public function a_marker_that_never_existed_is_refused(): void
    {
        $facts = ProtectedFacts::extract('A taxa de sucesso foi de 83,3%.');

        $verdict = $this->guard->inspect($facts, 'A taxa de sucesso foi de [[FQ]].');

        $this->assertFalse($verdict->acceptable);
        $this->assertSame('unknown_marker', $verdict->reason);
    }

    #[Test]
    public function moving_the_figures_around_is_allowed(): void
    {
        $facts = ProtectedFacts::extract(
            'A Média Ponderada Acumulada da turma foi de 60,3% e a taxa de sucesso foi de 83,3%.',
        );

        [$first, $second] = array_keys($facts->values);

        $verdict = $this->guard->inspect(
            $facts,
            "Com uma taxa de sucesso de {$second}, a turma apresentou uma Média Ponderada Acumulada de {$first}.",
        );

        $this->assertTrue($verdict->acceptable);
    }

    // --------------------------------------------------------- classificações

    #[Test]
    public function classifications_cannot_be_altered(): void
    {
        $facts = ProtectedFacts::extract('Um aluno obteve nível 2, um nível 3 e quatro nível 4.');

        // «cinco» where the text said «quatro»: a spelled quantity is a marker,
        // so the word arrives as one the answer never received.
        $verdict = $this->guard->inspect($facts, 'Um aluno obteve nível 2, um nível 3 e cinco nível 4.');

        $this->assertFalse($verdict->acceptable);
    }

    #[Test]
    public function a_level_cannot_become_a_mention(): void
    {
        $facts = ProtectedFacts::extract('Quatro alunos obtiveram nível 4.');

        $verdict = $this->guard->inspect($facts, 'Quatro alunos obtiveram menção Muito Bom.');

        $this->assertFalse($verdict->acceptable);
        $this->assertSame('marker_count', $verdict->reason);
    }

    // ------------------------------------------------------------ não inventar

    #[Test]
    public function a_difficulty_nobody_validated_is_refused(): void
    {
        $facts = ProtectedFacts::extract('Os resultados foram menos consistentes no domínio da Escrita.');

        $verdict = $this->guard->inspect(
            $facts,
            'Os resultados foram menos consistentes no domínio da Escrita, o que revela falta de hábitos de estudo.',
        );

        $this->assertFalse($verdict->acceptable);
        $this->assertSame('invented_claim', $verdict->reason);
    }

    #[Test]
    public function a_characterisation_of_behaviour_nobody_made_is_refused(): void
    {
        $facts = ProtectedFacts::extract('O professor caracterizou o comportamento da turma como satisfatório.');

        $verdict = $this->guard->inspect($facts, 'A turma é disciplinada e responsável.');

        $this->assertFalse($verdict->acceptable);
        $this->assertSame('invented_claim', $verdict->reason);
    }

    #[Test]
    public function a_strategy_nobody_chose_is_refused(): void
    {
        $facts = ProtectedFacts::extract('Foi indicada a leitura orientada como estratégia de superação.');

        $verdict = $this->guard->inspect(
            $facts,
            'Foi indicada a leitura orientada como estratégia de superação. Recomenda-se ainda apoio tutorial.',
        );

        $this->assertFalse($verdict->acceptable);
        $this->assertSame('invented_claim', $verdict->reason);
    }

    #[Test]
    public function legislation_is_refused_even_when_it_exists(): void
    {
        $facts = ProtectedFacts::extract('Foram adotadas medidas de suporte à aprendizagem.');

        $verdict = $this->guard->inspect(
            $facts,
            'Foram adotadas medidas de suporte à aprendizagem, ao abrigo do regime legal aplicável.',
        );

        $this->assertFalse($verdict->acceptable);
        $this->assertSame('invented_claim', $verdict->reason);
    }

    #[Test]
    public function a_reading_cannot_be_turned_into_something_proven(): void
    {
        $facts = ProtectedFacts::extract('Os resultados situam-se abaixo da média da turma.');

        $verdict = $this->guard->inspect($facts, 'Os resultados demonstram que o desempenho ficou abaixo da média.');

        $this->assertFalse($verdict->acceptable);
        $this->assertSame('invented_claim', $verdict->reason);
    }

    #[Test]
    public function a_word_already_in_the_text_may_be_reused(): void
    {
        // The lexicon refuses what is NEW. A teacher who wrote «tutoria» keeps
        // it, and a rewrite is free to say it better.
        $facts = ProtectedFacts::extract('Foi proposta tutoria entre pares como medida de apoio.');

        $verdict = $this->guard->inspect($facts, 'Propõe-se a tutoria entre pares como medida de apoio.');

        $this->assertTrue($verdict->acceptable);
    }

    // ------------------------------------------------------------ não perder

    #[Test]
    public function a_self_assessment_cannot_stop_being_one(): void
    {
        $facts = ProtectedFacts::extract('Os alunos autoavaliaram-se, em média, acima do resultado apurado.');

        $verdict = $this->guard->inspect($facts, 'Os alunos situam-se, em média, acima do resultado apurado.');

        $this->assertFalse($verdict->acceptable);
        $this->assertSame('dropped_hedge', $verdict->reason);
    }

    #[Test]
    public function an_absence_of_data_cannot_become_a_conclusion(): void
    {
        $facts = ProtectedFacts::extract(
            'Não existem resultados apurados para este aluno. A ausência de resultado não corresponde a um resultado negativo.',
        );

        $verdict = $this->guard->inspect($facts, 'O aluno obteve um resultado fraco no período analisado.');

        $this->assertFalse($verdict->acceptable);
    }

    #[Test]
    public function a_proposal_cannot_become_a_decision(): void
    {
        $facts = ProtectedFacts::extract('A proposta de classificação apurada pelo sistema situa-se neste intervalo.');

        $verdict = $this->guard->inspect($facts, 'A classificação atribuída situa-se neste intervalo.');

        $this->assertFalse($verdict->acceptable);
    }

    // ------------------------------------------------------------- a forma

    #[Test]
    public function markup_is_refused_rather_than_stripped(): void
    {
        $facts = ProtectedFacts::extract('A turma manteve o desempenho.');

        $verdict = $this->guard->inspect($facts, '<p>A turma manteve o desempenho.</p>');

        $this->assertFalse($verdict->acceptable);
        $this->assertSame('html', $verdict->reason);
    }

    #[Test]
    public function an_answer_half_again_as_long_has_added_something(): void
    {
        $facts = ProtectedFacts::extract(
            'A turma manteve o desempenho ao longo do intervalo analisado, sem alterações assinaláveis '
            .'na distribuição dos resultados nem no conjunto dos domínios avaliados pelo perfil em vigor.',
        );

        $verdict = $this->guard->inspect(
            $facts,
            'A turma manteve o desempenho ao longo do intervalo analisado, sem alterações assinaláveis '
            .'na distribuição dos resultados nem no conjunto dos domínios avaliados pelo perfil em vigor. '
            .'Importa sublinhar que a estabilidade observada constitui, em si mesma, um indicador relevante '
            .'do trabalho desenvolvido, merecendo por isso registo, na medida em que traduz consistência ao '
            .'longo de todo o intervalo considerado e permite uma leitura globalmente favorável do percurso.',
        );

        $this->assertFalse($verdict->acceptable);
        $this->assertSame('grew', $verdict->reason);
    }

    #[Test]
    public function an_empty_answer_is_refused(): void
    {
        $facts = ProtectedFacts::extract('A turma manteve o desempenho.');

        $this->assertFalse($this->guard->inspect($facts, '   ')->acceptable);
    }

    // ---------------------------------------------------------- normalização

    #[Test]
    public function markdown_decoration_is_normalised_away(): void
    {
        $normalised = $this->guard->normalise(
            "## Síntese\n\n- A turma manteve o **desempenho**.",
            'A turma manteve o desempenho.',
        );

        $this->assertSame("Síntese\n\nA turma manteve o desempenho.", $normalised);
    }

    #[Test]
    public function an_em_dash_the_original_did_not_have_is_normalised_away(): void
    {
        $normalised = $this->guard->normalise(
            'A turma — sobretudo no domínio da Escrita — manteve o desempenho.',
            'A turma, sobretudo no domínio da Escrita, manteve o desempenho.',
        );

        $this->assertStringNotContainsString('—', $normalised);
        $this->assertSame('A turma, sobretudo no domínio da Escrita, manteve o desempenho.', $normalised);
    }

    #[Test]
    public function an_em_dash_the_teacher_wrote_is_left_alone(): void
    {
        $normalised = $this->guard->normalise(
            'A turma — sobretudo na Escrita — manteve o desempenho.',
            'A turma — sobretudo na Escrita — manteve-se.',
        );

        $this->assertStringContainsString('—', $normalised);
    }

    // ------------------------------------------------------- o caso normal

    #[Test]
    public function a_genuine_improvement_is_accepted(): void
    {
        $facts = ProtectedFacts::extract(
            'A turma é constituída por 26 alunos. A Média Ponderada Acumulada da turma foi de 60,3%.',
        );

        [$count, $average] = array_keys($facts->values);

        $verdict = $this->guard->inspect(
            $facts,
            "Constituída por {$count} alunos, a turma apresentou uma Média Ponderada Acumulada de {$average}.",
        );

        $this->assertTrue($verdict->acceptable, $verdict->detail ?? '');
    }
}
