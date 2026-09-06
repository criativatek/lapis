<?php

namespace Tests\Unit\Ai;

use App\Support\Privacy\Pseudonyms;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * O QUE VOLTA PARA O ECRÃ DEPOIS DE UMA RESPOSTA DA IA.
 *
 * O BUG QUE ESTE FICHEIRO EXISTE PARA FIXAR: um modelo não escreve «Aluno E e
 * Aluno F». Escreve «com exceção dos alunos E e F» — o prefixo uma vez, no
 * plural, e as letras soltas. Uma substituição literal de «Aluno E» não apanha
 * nada disso, e o professor via na análise da sua própria turma uma frase sobre
 * «alunos E e F» que não identifica ninguém (§39).
 *
 * E A SEGUNDA METADE DO MESMO PROBLEMA: «o aluno E» é masculino porque «aluno»
 * é masculino. Trocar só o pseudónimo produz «o Marta Tomás». O artigo tem de
 * sair com ele, sem que nada infira género nenhum a partir de um nome (§42).
 */
class PseudonymRehydrationTest extends TestCase
{
    /**
     * Seis nomes, na ordem em que se tornam A, B, C, D, E, F.
     */
    private function map(): Pseudonyms
    {
        return Pseudonyms::of([
            'Álvaro Simões Ribeiro Costa',
            'Marta Tomás Nunes',
            'João Dias',
            'Rita Bastos',
            'Eva Salgado',
            'Filipe Andrade',
        ])->restoringShortNames();
    }

    // -------------------------------------------------- a frase que falhava

    #[Test]
    public function the_plural_contracted_form_a_model_actually_writes_is_restored(): void
    {
        $this->assertSame(
            'A turma melhorou, com exceção de Eva Salgado e Filipe Andrade.',
            $this->map()->rehydrate('A turma melhorou, com exceção dos alunos E e F.'),
        );
    }

    #[Test]
    public function no_pseudonym_survives_in_any_of_the_shapes_a_model_uses(): void
    {
        $map = $this->map();

        $shapes = [
            'Aluno A destacou-se.',
            'O Aluno A destacou-se.',
            'o aluno A destacou-se.',
            'Alunos A e B destacaram-se.',
            'Os alunos A, B e C destacaram-se.',
            'Recomenda-se acompanhamento ao aluno B.',
            'Nos alunos A e B nota-se progresso.',
            'Foi pelo aluno C que a turma subiu.',
        ];

        foreach ($shapes as $shape) {
            $restored = $map->rehydrate($shape);

            // A AFIRMAÇÃO CENTRAL: nenhuma destas frases pode chegar ao
            // professor a falar de «alunos» com uma letra ao lado.
            $this->assertDoesNotMatchRegularExpression(
                '/\balunos?\s+[A-Z]\b/iu',
                $restored,
                "Sobrou um pseudónimo em: {$restored}",
            );
        }
    }

    // ------------------------------------------------------- as enumerações

    #[Test]
    public function a_list_is_written_the_way_portuguese_writes_a_list(): void
    {
        $this->assertSame(
            'Álvaro Costa, Marta Nunes e João Dias mantiveram o nível.',
            $this->map()->rehydrate('Alunos A, B e C mantiveram o nível.'),
        );

        $this->assertSame(
            'Álvaro Costa e Marta Nunes mantiveram o nível.',
            $this->map()->rehydrate('Alunos A e B mantiveram o nível.'),
        );
    }

    // ------------------------------------------------------------- o género

    #[Test]
    public function the_article_leaves_with_the_pseudonym_so_no_gender_is_ever_asserted(): void
    {
        $map = $this->map();

        // «O Aluno B» tornar-se-ia «O Marta Nunes» se só o pseudónimo fosse
        // trocado. O artigo sai, e ninguém teve de adivinhar o género de
        // ninguém (§42).
        $this->assertSame('Marta Nunes melhorou em Leitura.', $map->rehydrate('O Aluno B melhorou em Leitura.'));
        $this->assertStringNotContainsString('O Marta', $map->rehydrate('O Aluno B melhorou em Leitura.'));
    }

    #[Test]
    public function a_contraction_goes_back_to_the_preposition_it_was_hiding(): void
    {
        $map = $this->map();

        $this->assertSame('O trabalho de Álvaro Costa melhorou.', $map->rehydrate('O trabalho do Aluno A melhorou.'));
        $this->assertSame('Recomenda-se apoio a Marta Nunes.', $map->rehydrate('Recomenda-se apoio ao Aluno B.'));
        $this->assertSame('Em João Dias nota-se progresso.', $map->rehydrate('No Aluno C nota-se progresso.'));
        $this->assertSame('Foi por Rita Bastos que subiu.', $map->rehydrate('Foi pelo Aluno D que subiu.'));

        // E a maiúscula do início de uma frase não se perde pelo caminho.
        $this->assertSame('De Álvaro Costa e Marta Nunes, só um progrediu.', $map->rehydrate('Dos alunos A e B, só um progrediu.'));
    }

    // ------------------------------------------------ o que NÃO se pode tocar

    #[Test]
    public function a_loose_letter_is_never_mistaken_for_a_pseudonym(): void
    {
        $map = $this->map();

        // A conjunção «e» é uma conjunção. Sem o prefixo à frente, nada é
        // substituído — que é a diferença entre corrigir uma frase e destruí-la
        // (§41).
        $this->assertSame('O aluno e o professor conversaram.', $map->rehydrate('O aluno e o professor conversaram.'));
        $this->assertSame('A resposta B estava certa.', $map->rehydrate('A resposta B estava certa.'));
        $this->assertSame('O domínio E não existe.', $map->rehydrate('O domínio E não existe.'));
    }

    #[Test]
    public function an_unknown_letter_leaves_the_whole_sentence_untouched(): void
    {
        // Metade de uma enumeração substituída seria uma frase que mistura
        // nomes e pseudónimos — pior do que a frase original.
        $this->assertSame(
            'Os alunos A e Z não são comparáveis.',
            $this->map()->rehydrate('Os alunos A e Z não são comparáveis.'),
        );
    }

    #[Test]
    public function an_empty_map_changes_nothing(): void
    {
        $this->assertSame(
            'Os alunos A e B melhoraram.',
            Pseudonyms::none()->rehydrate('Os alunos A e B melhoraram.'),
        );
    }

    // -------------------------------------------------- o nome que se mostra

    #[Test]
    public function names_come_back_short_where_the_caller_asked_for_short_names(): void
    {
        // Primeiro e último — como uma pessoa chama outra numa reunião (§40).
        $this->assertSame('Álvaro Costa destacou-se.', $this->map()->rehydrate('Aluno A destacou-se.'));
    }

    #[Test]
    public function names_come_back_whole_where_nobody_asked_for_anything(): void
    {
        // O REESCRITOR DE UM TEXTO DO PROFESSOR NÃO ENCURTA NADA: o texto de
        // partida é dele, e mexer-lhe nos nomes seria editar-lhe a prosa a
        // pretexto de a devolver.
        $map = Pseudonyms::of(['Álvaro Simões Ribeiro Costa']);

        $this->assertSame('Álvaro Simões Ribeiro Costa destacou-se.', $map->rehydrate('Aluno A destacou-se.'));
    }

    // ------------------------------------------------------- ida e volta

    #[Test]
    public function what_goes_out_is_still_a_pseudonym_and_only_what_comes_back_is_a_name(): void
    {
        $map = $this->map();

        $sent = $map->apply('A Marta Tomás Nunes e o Álvaro Simões Ribeiro Costa melhoraram.');

        // O QUE SAI DAQUI NÃO LEVA NOME NENHUM. É a afirmação que a
        // pseudonimização inteira existe para poder ser feita (§39, §73).
        $this->assertStringNotContainsString('Marta', $sent);
        $this->assertStringNotContainsString('Álvaro', $sent);
        $this->assertTrue($map->coversEverythingIn($sent));
    }
}
