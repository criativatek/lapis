<?php

namespace Tests\Unit\Lessons;

use App\Services\Lessons\SummarySearchText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A regra única da pesquisa nos sumários: o que corresponde, quantas vezes e
 * onde se destaca. Os invariantes de `segments()` são o que impede o filtro, a
 * contagem e o destaque de divergirem.
 */
class SummarySearchTextTest extends TestCase
{
    private function occurrencesOf(string $text, string $term): int
    {
        return SummarySearchText::occurrences(
            SummarySearchText::normalize($text),
            SummarySearchText::normalize(SummarySearchText::cleanTerm($term)),
        );
    }

    #[Test]
    public function capitals_make_no_difference(): void
    {
        $this->assertSame(1, $this->occurrencesOf('Frações Equivalentes', 'FRAÇÕES equivalentes'));
    }

    #[Test]
    public function accents_are_ignored_in_both_directions(): void
    {
        $this->assertSame(1, $this->occurrencesOf('Frações', 'fracoes'));
        $this->assertSame(1, $this->occurrencesOf('fracoes', 'Frações'));
        $this->assertSame(1, $this->occurrencesOf('Çà é ü ñ', 'ca e u n'));
        $this->assertSame(1, $this->occurrencesOf('Tal’s', "tal's"));
    }

    #[Test]
    public function decomposed_text_is_worth_the_same_as_composed_text(): void
    {
        $decomposed = "cafe\u{0301}";

        $this->assertSame('cafe', SummarySearchText::normalize($decomposed));
        $this->assertSame(1, $this->occurrencesOf($decomposed, 'café'));
        $this->assertSame(1, $this->occurrencesOf('café', $decomposed));
    }

    #[Test]
    public function invisible_formatting_characters_pasted_from_a_document_do_not_split_a_word(): void
    {
        // Hífen opcional (U+00AD), espaço de largura zero (U+200B) e marca de
        // ordem de bytes (U+FEFF), como vêm de um Word ou de um PDF.
        $text = "Revisão de fra\u{00AD}ções e de equa\u{200B}ções.\u{FEFF}";

        $this->assertSame('revisao de fracoes e de equacoes.', SummarySearchText::normalize($text));
        $this->assertSame(1, $this->occurrencesOf($text, 'frações'));

        $segments = SummarySearchText::segments($text, SummarySearchText::normalize('frações'));
        $this->assertSame($text, implode('', array_column($segments, 'text')));
        $this->assertSame(["fra\u{00AD}ções"], array_values(array_column(array_filter($segments, fn (array $segment): bool => $segment['match']), 'text')));
    }

    #[Test]
    public function no_break_spaces_tabs_and_line_breaks_collapse_into_one_space(): void
    {
        $this->assertSame('a b c d', SummarySearchText::normalize("  a\u{00A0}\u{00A0}b\t\tc\r\n\nd \n"));
    }

    #[Test]
    public function a_phrase_crossing_a_line_break_is_found_but_not_in_another_order(): void
    {
        $text = "Frações\nequivalentes e simplificação";

        $this->assertSame(1, $this->occurrencesOf($text, 'frações equivalentes'));
        $this->assertSame(0, $this->occurrencesOf($text, 'equivalentes frações'));
    }

    #[Test]
    public function the_quotes_around_the_term_are_removed(): void
    {
        $this->assertSame('frações equivalentes', SummarySearchText::cleanTerm('"frações equivalentes"'));
        $this->assertSame('frações', SummarySearchText::cleanTerm(' “frações” '));
        $this->assertSame('frações', SummarySearchText::cleanTerm('«frações»'));
        $this->assertSame('frações', SummarySearchText::cleanTerm('„frações“'));
        $this->assertSame('"frações', SummarySearchText::cleanTerm('"frações'));
        $this->assertSame('', SummarySearchText::cleanTerm('""'));
    }

    #[Test]
    public function percent_underscore_backslash_and_html_are_plain_text(): void
    {
        $this->assertSame(1, $this->occurrencesOf('Desconto de 50% hoje', '50%'));
        $this->assertSame(0, $this->occurrencesOf('Desconto de 50 hoje', '50%'));
        $this->assertSame(1, $this->occurrencesOf('a_b', 'a_b'));
        $this->assertSame(0, $this->occurrencesOf('axb', 'a_b'));
        $this->assertSame(1, $this->occurrencesOf('C:\\pasta', '\\p'));
        $this->assertSame(1, $this->occurrencesOf('Usar <b>negrito</b>', '<b>'));
        $this->assertSame(0, $this->occurrencesOf('Usar negrito', '<b>'));
    }

    #[Test]
    public function occurrences_do_not_overlap_and_an_empty_term_counts_nothing(): void
    {
        $this->assertSame(1, SummarySearchText::occurrences('aaa', 'aa'));
        $this->assertSame(2, SummarySearchText::occurrences('aaaa', 'aa'));
        $this->assertSame(0, SummarySearchText::occurrences('aaa', ''));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function segmentCases(): array
    {
        return [
            'simples' => ['Frações equivalentes e frações próprias.', 'fracoes'],
            'maiúsculas e acentos' => ['FRAÇÕES, frações, Fracoes', 'fraçoes'],
            'quebra de linha' => ["Frações\nequivalentes\u{00A0}  e mais", 'fracoes equivalentes'],
            'texto decomposto' => ["cafe\u{0301} e cafe\u{0301}s", 'café'],
            'emoji' => ['Aula 😀 sobre frações 😀 e 🎉 frações', 'frações'],
            'ordinal' => ['Resolver o 2.º problema; 2.º ano e 12.º ano', '2.º'],
            'html' => ['Usar <b>negrito</b> e <b>itálico</b>', '<b>'],
            'espaços nas pontas' => ["  \n frações \n ", 'fracoes'],
            'sem correspondência' => ['Nada a ver', 'fracoes'],
            'colada ao fim' => ['frações', 'ões'],
            'termo com espaço' => ['a b a b', 'a b'],
            'apóstrofo' => ['It’s the d’Artagnan', "'s"],
        ];
    }

    #[Test]
    #[DataProvider('segmentCases')]
    public function segments_rebuild_the_original_and_mark_exactly_the_counted_occurrences(string $original, string $rawTerm): void
    {
        $term = SummarySearchText::normalize(SummarySearchText::cleanTerm($rawTerm));
        $segments = SummarySearchText::segments($original, $term);

        $this->assertSame($original, implode('', array_column($segments, 'text')));
        $this->assertSame(
            SummarySearchText::occurrences(SummarySearchText::normalize($original), $term),
            count(array_filter($segments, fn (array $segment): bool => $segment['match'])),
        );

        foreach ($segments as $index => $segment) {
            $this->assertNotSame('', $segment['text']);

            // Segmentos adjacentes sem correspondência nunca ficam partidos.
            if ($index > 0 && ! $segment['match']) {
                $this->assertTrue($segments[$index - 1]['match']);
            }
        }
    }

    #[Test]
    public function a_match_highlights_the_original_characters_including_their_combining_marks(): void
    {
        $segments = SummarySearchText::segments("um cafe\u{0301} quente", 'cafe');

        $this->assertSame([
            ['text' => 'um ', 'match' => false],
            ['text' => "cafe\u{0301}", 'match' => true],
            ['text' => ' quente', 'match' => false],
        ], $segments);
    }

    #[Test]
    public function a_match_across_a_line_break_covers_the_whole_original_gap(): void
    {
        $segments = SummarySearchText::segments("ver frações\n\nequivalentes hoje", 'fracoes equivalentes');

        $this->assertSame("frações\n\nequivalentes", $segments[1]['text']);
        $this->assertTrue($segments[1]['match']);
    }

    #[Test]
    public function no_match_gives_a_single_unmarked_segment_and_empty_text_gives_none(): void
    {
        $this->assertSame([['text' => 'abc', 'match' => false]], SummarySearchText::segments('abc', 'xyz'));
        $this->assertSame([], SummarySearchText::segments('', 'xyz'));
    }
}
