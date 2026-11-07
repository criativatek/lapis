<?php

namespace App\Support\Documents;

/**
 * Siglas para o papel — a mesma regra de `resources/js/lib/abbreviations.ts`.
 *
 * Uma grelha que cabe numa página só cabe porque os cabeçalhos encolhem: o
 * número da pergunta em vez do código, «G.V» em vez de «GRUPO V - ESCRITA»,
 * «Edu. Lit.» em vez de «Educação Literária». A regra vive aqui, uma vez, e a
 * legenda do documento desfaz cada sigla por extenso.
 */
final class Abbreviations
{
    /** Palavras que não contam para uma sigla. */
    private const PARTICLES = ['de', 'da', 'do', 'das', 'dos', 'e', 'a', 'o', 'as', 'os', 'em', 'no', 'na', 'nos', 'nas', 'com', 'para', 'por'];

    private const SUMMARY = [
        'raw' => 'Bruta',
        'result' => 'Res.',
        'level' => 'Nív.',
        'weighted_average' => 'Média',
        'assigned_level' => 'Nível',
    ];

    /** «Q12» → «12», «Item 3» → «3», «2.1» → «2.1», «A» → «A». */
    public static function questionNumber(string $code): string
    {
        $code = trim($code);

        if (preg_match('/^(?:q|item|pergunta|p)\s*[.\-]?\s*(\d.*)$/iu', $code, $match) === 1) {
            return trim($match[1]);
        }

        return $code;
    }

    /** «GRUPO V - ESCRITA» → «G.V»; «Expressão escrita» → «EE»; vazio → «». */
    public static function groupShort(?string $label): string
    {
        if ($label === null || trim($label) === '') {
            return '';
        }

        if (preg_match('/\b([IVXLC]+|\d+)\b/iu', $label, $match) === 1) {
            return 'G.'.mb_strtoupper($match[1]);
        }

        $initials = array_map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), array_slice(self::words($label), 0, 4));

        return implode('', $initials);
    }

    /**
     * «Oralidade» → «Oral.», «Educação Literária» → «Edu. Lit.»; com `$taken`,
     * acrescenta letras à primeira palavra até não colidir.
     *
     * @param  list<string>  $taken
     */
    public static function domainShort(string $name, array $taken = []): string
    {
        $parts = self::words($name);

        if ($parts === []) {
            return '';
        }

        $piece = fn (string $word, int $length): string => mb_strlen($word) <= $length
            ? self::capitalize($word)
            : self::capitalize(mb_substr($word, 0, $length)).'.';

        $build = function (int $firstLength) use ($parts, $piece): string {
            if (count($parts) === 1) {
                return $piece($parts[0], $firstLength);
            }

            $pieces = [];

            foreach ($parts as $index => $word) {
                $pieces[] = $piece($word, $index === 0 ? $firstLength : 3);
            }

            return implode(' ', $pieces);
        };

        $length = count($parts) === 1 ? 4 : 3;
        $candidate = $build($length);

        while (in_array($candidate, $taken, true) && $length < mb_strlen($parts[0])) {
            $length++;
            $candidate = $build($length);
        }

        return $candidate;
    }

    /** «Muito Bom» → «MB», «Insuficiente» → «I». */
    public static function mentionShort(string $label): string
    {
        return implode('', array_map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), self::words($label)));
    }

    /**
     * A sigla de cada menção — ou o CÓDIGO de todas quando duas colidem.
     *
     * @param  list<array{code: string, label: string}>  $bands
     * @return array<string, string> rótulo ⇒ sigla ou código
     */
    public static function uniqueMentionShorts(array $bands): array
    {
        $shorts = array_map(fn (array $band): string => self::mentionShort($band['label']), $bands);
        $collides = count(array_unique($shorts)) !== count($shorts);
        $result = [];

        foreach ($bands as $index => $band) {
            $result[$band['label']] = $collides ? $band['code'] : $shorts[$index];
        }

        return $result;
    }

    /** A sigla fixa de uma coluna-resumo, ou null quando a coluna não tem uma (domínios). */
    public static function summaryShort(string $kind): ?string
    {
        return self::SUMMARY[$kind] ?? null;
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $raw = preg_split('/[\s\-–—\/]+/u', trim($text)) ?: [];
        $words = [];

        foreach ($raw as $word) {
            $clean = (string) preg_replace('/[^\p{L}\p{N}]/u', '', $word);

            if ($clean !== '' && ! in_array(mb_strtolower($clean), self::PARTICLES, true)) {
                $words[] = $clean;
            }
        }

        return $words;
    }

    private static function capitalize(string $word): string
    {
        return mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1);
    }
}
