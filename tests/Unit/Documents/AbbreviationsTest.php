<?php

namespace Tests\Unit\Documents;

use App\Support\Documents\Abbreviations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A mesma regra de `resources/js/lib/abbreviations.ts`: os casos são os mesmos
 * dos dois lados, e quem mudar um muda o outro.
 */
class AbbreviationsTest extends TestCase
{
    /** @return list<array{string, string}> */
    public static function questionCodes(): array
    {
        return [
            ['Q12', '12'], ['q3', '3'], ['Item 3', '3'], ['Pergunta 7', '7'], ['P2.1', '2.1'], ['Q 4a', '4a'],
            ['2.1', '2.1'], ['A', 'A'], ['Prova', 'Prova'], ['', ''],
        ];
    }

    #[Test]
    #[DataProvider('questionCodes')]
    public function a_question_keeps_only_its_number(string $code, string $expected): void
    {
        $this->assertSame($expected, Abbreviations::questionNumber($code));
    }

    #[Test]
    public function a_group_uses_its_numeral_or_its_initials(): void
    {
        $this->assertSame('G.V', Abbreviations::groupShort('GRUPO V - ESCRITA'));
        $this->assertSame('G.2', Abbreviations::groupShort('Grupo 2'));
        $this->assertSame('G.III', Abbreviations::groupShort('grupo iii — leitura'));
        $this->assertSame('EE', Abbreviations::groupShort('Expressão escrita'));
        $this->assertSame('COE', Abbreviations::groupShort('Compreensão do oral e da escrita'));
        $this->assertSame('UDTQ', Abbreviations::groupShort('Um Dois Três Quatro Cinco'));
        $this->assertSame('', Abbreviations::groupShort(''));
        $this->assertSame('', Abbreviations::groupShort(null));
    }

    #[Test]
    public function a_domain_is_shortened_and_never_collides(): void
    {
        $this->assertSame('Oral.', Abbreviations::domainShort('Oralidade'));
        $this->assertSame('Leit.', Abbreviations::domainShort('Leitura'));
        $this->assertSame('Escr.', Abbreviations::domainShort('Escrita'));
        $this->assertSame('Gram.', Abbreviations::domainShort('Gramática'));
        $this->assertSame('Arte', Abbreviations::domainShort('Arte'));
        $this->assertSame('Edu. Lit.', Abbreviations::domainShort('Educação Literária'));
        $this->assertSame('Com. Ora.', Abbreviations::domainShort('Compreensão do Oral'));
        $this->assertSame('Geome.', Abbreviations::domainShort('Geometria', ['Geom.']));
        $this->assertSame('Geomet.', Abbreviations::domainShort('Geometria', ['Geom.', 'Geome.']));
    }

    #[Test]
    public function a_mention_is_its_initials_and_codes_replace_colliding_ones(): void
    {
        $this->assertSame('MB', Abbreviations::mentionShort('Muito Bom'));
        $this->assertSame('I', Abbreviations::mentionShort('Insuficiente'));
        $this->assertSame('NS', Abbreviations::mentionShort('Não Satisfaz'));

        $unique = Abbreviations::uniqueMentionShorts([
            ['code' => '1', 'label' => 'Insuficiente'], ['code' => '3', 'label' => 'Suficiente'],
            ['code' => '4', 'label' => 'Bom'], ['code' => '5', 'label' => 'Muito Bom'],
        ]);
        $this->assertSame(['Insuficiente' => 'I', 'Suficiente' => 'S', 'Bom' => 'B', 'Muito Bom' => 'MB'], $unique);

        $colliding = Abbreviations::uniqueMentionShorts([
            ['code' => '2', 'label' => 'Satisfaz'], ['code' => '3', 'label' => 'Satisfaz Bem'], ['code' => '4', 'label' => 'Satisfaz Bastante'],
        ]);
        $this->assertSame(['Satisfaz' => '2', 'Satisfaz Bem' => '3', 'Satisfaz Bastante' => '4'], $colliding);
    }

    #[Test]
    public function the_summary_columns_have_fixed_shorts(): void
    {
        $this->assertSame('Bruta', Abbreviations::summaryShort('raw'));
        $this->assertSame('Res.', Abbreviations::summaryShort('result'));
        $this->assertSame('Nív.', Abbreviations::summaryShort('level'));
        $this->assertSame('Média', Abbreviations::summaryShort('weighted_average'));
        $this->assertSame('Nível', Abbreviations::summaryShort('assigned_level'));
        $this->assertNull(Abbreviations::summaryShort('domain'));
    }
}
