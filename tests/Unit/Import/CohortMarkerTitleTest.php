<?php

namespace Tests\Unit\Import;

use App\Services\Import\AcademicCalendar\CohortMarkerTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A abreviatura do calendário escrita por extenso — «Fim 5/6/7/8.º» →
 * «Fim das atividades letivas — 5.º/6.º/7.º/8.º anos».
 *
 * OS TRÊS CASOS REAIS ESTÃO AQUI COM O TEXTO EXATO que o parser lê da proposta
 * de um agrupamento (o ficheiro que fica de fora deste repositório), célula a
 * célula. Não são exemplos escolhidos para o teste passar: são as três únicas
 * células de coorte que o documento tem, e as asserções são sobre a string
 * inteira e não sobre um `assertStringContainsString` que deixaria passar meia
 * frase.
 *
 * A OUTRA METADE DO CONTRATO — e a que interessa mais — é a lista do fim: um
 * rótulo que este normalizador não reconhece sai daqui EXATAMENTE como entrou.
 * Um normalizador que adivinhasse escreveria no calendário do professor uma
 * coorte que o documento nunca nomeou, e isso é pior do que uma abreviatura.
 */
class CohortMarkerTitleTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function realDocumentLabels(): array
    {
        return [
            'um ano de escolaridade só' => [
                'Fim 9.º ano',
                'Fim das atividades letivas — 9.º ano',
            ],
            // Quatro anos e por isso «anos»; e o «º» que o documento escreve uma
            // vez ao fim de todos passa a acompanhar cada algarismo.
            'quatro anos de escolaridade' => [
                'Fim 5/6/7/8.º',
                'Fim das atividades letivas — 5.º/6.º/7.º/8.º anos',
            ],
            // «Pré» é o pré-escolar e «1.ºC» é o 1.º Ciclo — as duas únicas
            // leituras possíveis, e nenhuma delas acrescenta um ano de
            // escolaridade que a célula não nomeie.
            'o pré-escolar e um ciclo' => [
                'Fim Pré/1.ºC',
                'Fim das atividades letivas — Pré-escolar e 1.º Ciclo',
            ],
        ];
    }

    #[Test]
    #[DataProvider('realDocumentLabels')]
    public function it_writes_each_real_cohort_marker_out_in_full(string $raw, string $expected): void
    {
        $this->assertSame($expected, CohortMarkerTitle::normalise($raw));
    }

    /**
     * O MESMO ANO DE ESCOLARIDADE ESCRITO DE VÁRIAS MANEIRAS dá a mesma frase. O
     * documento do ano seguinte não é obrigado a repetir a pontuação deste.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function spellings(): array
    {
        return [
            'sem ordinal' => ['Fim 9', 'Fim das atividades letivas — 9.º ano'],
            'sem ponto' => ['Fim 9º ano', 'Fim das atividades letivas — 9.º ano'],
            'com artigo' => ['Fim do 9.º ano', 'Fim das atividades letivas — 9.º ano'],
            'ciclo por extenso' => [
                'Fim 1.º Ciclo',
                'Fim das atividades letivas — 1.º Ciclo',
            ],
            'pré-escolar por extenso' => [
                'Fim Pré-escolar',
                'Fim das atividades letivas — Pré-escolar',
            ],
        ];
    }

    #[Test]
    #[DataProvider('spellings')]
    public function it_reads_the_same_cohort_written_in_more_than_one_way(string $raw, string $expected): void
    {
        $this->assertSame($expected, CohortMarkerTitle::normalise($raw));
    }

    /**
     * NADA DE ADIVINHAR. Um rótulo que não nomeia uma coorte reconhecível vai
     * para o calendário do professor tal como o documento o escreveu — dizer
     * menos é honesto, dizer errado não é.
     *
     * @return array<string, array{0: string}>
     */
    public static function labelsThatAreNotACohort(): array
    {
        return [
            'uma frase sem coorte nenhuma' => ['Fim do ano letivo'],
            'uma coorte que ninguém sabe ler' => ['Fim Turmas CEF'],
            'um número que não é um ano de escolaridade' => ['Fim 30'],
            'metade reconhecível e metade não' => ['Fim 9.º ano/Turmas CEF'],
            'só a palavra' => ['Fim'],
        ];
    }

    #[Test]
    #[DataProvider('labelsThatAreNotACohort')]
    public function a_label_it_cannot_read_comes_out_exactly_as_it_went_in(string $raw): void
    {
        $this->assertSame($raw, CohortMarkerTitle::normalise($raw));
    }

    /**
     * O PLURAL CONTA-SE. «5.º/6.º anos» e «5.º ano» — o «s» segue o número de
     * anos nomeados e não uma regra fixa, que era o erro fácil de escrever.
     */
    #[Test]
    public function the_plural_follows_how_many_grades_the_label_actually_names(): void
    {
        $this->assertSame(
            'Fim das atividades letivas — 5.º ano',
            CohortMarkerTitle::normalise('Fim 5.º'),
        );
        $this->assertSame(
            'Fim das atividades letivas — 5.º/6.º anos',
            CohortMarkerTitle::normalise('Fim 5/6.º'),
        );
    }
}
