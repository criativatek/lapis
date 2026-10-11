<?php

namespace App\Services\Lessons;

/**
 * O QUE CORRESPONDE NA PESQUISA DOS SUMÁRIOS, decidido num sítio só.
 *
 * Filtro, contagem de ocorrências e destaque usam esta classe e nenhuma outra
 * coisa: se cada um normalizasse à sua maneira, haveria resultados com «0
 * ocorrências» ou texto destacado que a contagem não viu.
 *
 * Sem a extensão `intl` (não está declarada no composer.json, e o resultado
 * não pode depender de ela existir no servidor): a normalização é feita
 * carácter a carácter com uma tabela de letras latinas acentuadas.
 */
final class SummarySearchText
{
    /**
     * Letras latinas minúsculas acentuadas → letra base. Mantêm-se de fora
     * `æ`, `œ`, `ß`, `º` e `ª`: não são «letra com acento», e tratá-las como
     * se fossem daria equivalências que ninguém espera (`º` = `o`).
     *
     * @var array<string, string>
     */
    private const FOLD = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c',
        'ď' => 'd', 'đ' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
        'ĥ' => 'h', 'ħ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
        'ĵ' => 'j',
        'ķ' => 'k',
        'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ŀ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
        'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w',
        'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
        // Apóstrofos tipográficos → o da máquina de escrever.
        '’' => "'", '‘' => "'", 'ʼ' => "'", '‛' => "'",
        // O sigma final vale o sigma normal.
        'ς' => 'σ',
    ];

    /**
     * Memo por carácter: um sumário repete as mesmas poucas dezenas de
     * caracteres milhares de vezes, e o universo de uma pesquisa são milhares
     * de sumários.
     *
     * @var array<string, string>
     */
    private static array $memo = [];

    /**
     * O termo como o professor o escreveu, sem as aspas que o rodeiam: a
     * pesquisa já é a da expressão exata, e as aspas seriam procuradas como
     * texto.
     */
    public static function cleanTerm(string $raw): string
    {
        $term = trim($raw);

        foreach ([['"', '"'], ['“', '”'], ['«', '»'], ['„', '“']] as [$open, $close]) {
            if (mb_strlen($term) >= 2 && str_starts_with($term, $open) && str_ends_with($term, $close)) {
                return trim(mb_substr($term, 1, mb_strlen($term) - 2));
            }
        }

        return $term;
    }

    public static function normalize(string $text): string
    {
        $map = null;

        return self::walk($text, $map)['normalized'];
    }

    /**
     * Ocorrências não sobrepostas, da esquerda para a direita (`aa` em `aaa`
     * é uma).
     */
    public static function occurrences(string $normalizedText, string $normalizedTerm): int
    {
        return $normalizedTerm === '' ? 0 : substr_count($normalizedText, $normalizedTerm);
    }

    /**
     * O texto ORIGINAL partido em pedaços, com as ocorrências marcadas. O
     * destaque viaja como segmentos de texto e nunca como HTML: o ecrã
     * escreve-os como texto, e um `<b>` no sumário continua a ser um `<b>`
     * escrito, não uma etiqueta.
     *
     * Duas ocorrências coladas (`aa` em `aaaa`) ficam em dois segmentos
     * `match`, e não fundidos num: o número de segmentos marcados é sempre o
     * número de ocorrências contadas.
     *
     * @return list<array{text: string, match: bool}>
     */
    public static function segments(string $original, string $normalizedTerm): array
    {
        if ($original === '') {
            return [];
        }

        if ($normalizedTerm === '') {
            return [['text' => $original, 'match' => false]];
        }

        $map = [];
        $walked = self::walk($original, $map);
        $characters = $walked['characters'];
        $folded = $walked['folded'];
        $total = count($characters);
        $segments = [];
        $cursor = 0;
        $offset = 0;
        $termLength = strlen($normalizedTerm);

        while (($position = strpos($walked['normalized'], $normalizedTerm, $offset)) !== false) {
            $first = $map[$position];
            $last = $map[$position + $termLength - 1];

            // As marcas combinantes que vêm colar-se ao último carácter
            // fazem parte dele: destacar «e» e deixar o acento de fora
            // partiria um carácter visual ao meio.
            while ($last + 1 < $total && $folded[$last + 1] === '') {
                $last++;
            }

            if ($first > $cursor) {
                $segments[] = ['text' => implode('', array_slice($characters, $cursor, $first - $cursor)), 'match' => false];
            }

            $segments[] = ['text' => implode('', array_slice($characters, $first, $last - $first + 1)), 'match' => true];
            $cursor = $last + 1;
            $offset = $position + $termLength;
        }

        if ($cursor < $total) {
            $segments[] = ['text' => implode('', array_slice($characters, $cursor)), 'match' => false];
        }

        return $segments;
    }

    /**
     * Percorre o texto uma vez e devolve o normalizado, os caracteres
     * originais e o que cada um deu. `$map` (quando pedido) diz, para cada
     * BYTE do normalizado, o índice do carácter original que o originou — os
     * espaços colapsados apontam para o primeiro da sequência.
     *
     * @param  list<int>|null  $map
     * @return array{normalized: string, characters: list<string>, folded: list<string>}
     */
    private static function walk(string $text, ?array &$map): array
    {
        $characters = $text === '' ? [] : mb_str_split($text);
        $folded = [];
        $normalized = '';
        $previousWasSpace = true; // espaços iniciais não se emitem

        foreach ($characters as $index => $character) {
            $piece = self::$memo[$character] ??= self::foldCharacter($character);
            $folded[] = $piece;

            if ($piece === '' || ($piece === ' ' && $previousWasSpace)) {
                continue;
            }

            $previousWasSpace = $piece === ' ';
            $normalized .= $piece;

            if ($map !== null) {
                for ($byte = 0, $length = strlen($piece); $byte < $length; $byte++) {
                    $map[] = $index;
                }
            }
        }

        // Espaço final: o trim.
        if ($normalized !== '' && str_ends_with($normalized, ' ')) {
            $normalized = substr($normalized, 0, -1);

            if ($map !== null) {
                array_pop($map);
            }
        }

        return ['normalized' => $normalized, 'characters' => $characters, 'folded' => $folded];
    }

    private static function foldCharacter(string $character): string
    {
        if (preg_match('/^[\s\p{Z}]$/u', $character) === 1) {
            return ' ';
        }

        // Marcas combinantes e caracteres de formatação invisíveis: o hífen
        // opcional (U+00AD) e o espaço de largura zero (U+200B) que vêm
        // colados de um Word ou de um PDF partiriam uma palavra ao meio.
        if (preg_match('/^[\p{Mn}\p{Cf}]$/u', $character) === 1) {
            return '';
        }

        // A minúscula de `İ` traz uma marca combinante atrás; sai.
        $lower = preg_replace('/\p{Mn}/u', '', mb_strtolower($character)) ?? '';

        return self::FOLD[$lower] ?? $lower;
    }
}
