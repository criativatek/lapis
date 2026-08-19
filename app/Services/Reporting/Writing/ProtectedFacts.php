<?php

namespace App\Services\Reporting\Writing;

/**
 * Every figure in the text, taken out before it leaves and put back after (§11).
 *
 * THE MODEL NEVER SEES A NUMBER. «A Média Ponderada Acumulada da turma foi de
 * 60,3% e a taxa de sucesso foi de 83,3%» arrives as «... foi de [[FA]] e a taxa
 * de sucesso foi de [[FB]]». It can move the markers, build a better sentence
 * around them and change everything between them; it cannot turn 60,3 into 61,3,
 * because it was never told what 60,3 was. This is not a filter that catches a
 * wrong number afterwards — it is an arrangement in which producing a wrong
 * number is not an available move.
 *
 * THE INVARIANT THAT MAKES THE GUARD SIMPLE: the redacted text contains no digit
 * anywhere, because the markers are lettered. So any digit in an answer is a
 * digit the model wrote itself, and no parsing is needed to notice.
 *
 * WORDS COUNT AS NUMBERS. Portuguese school prose says «três alunos», not «3
 * alunos», and the deterministic composers deliberately spell small quantities
 * out. A protection that only understood digits would leave every count in a
 * class report unguarded, so cardinal words from two upwards are extracted the
 * same way.
 *
 * «UM» AND «UMA» ARE LEFT ALONE, and that is a considered exception rather than
 * an oversight. They are also the indefinite article, and a text where every
 * «uma» is a marker is a text no model can rewrite well. The gap is closed on
 * the other side instead: RewriteGuard refuses an answer that grows the number
 * of «um aluno»-shaped phrases, so one cannot appear out of nothing.
 *
 * ORDINAL PERIODS ARE FIGURES TOO. «no segundo período» is the temporal scope of
 * everything around it, and §3 forbids changing that — so it travels as a marker
 * like any other value.
 *
 * The same value always gets the same marker. Two mentions of 60,3% are one
 * fact, and requiring the answer to carry [[F1]] twice is a stricter and simpler
 * check than tracking two markers that must stay equal.
 */
readonly class ProtectedFacts
{
    /**
     * @param  array<string, string>  $values  marker => the original text it replaced
     * @param  array<string, bool>  $caseFlexible  marker => whether its capitalisation may be adjusted on the way back
     */
    protected function __construct(
        public string $redacted,
        public array $values,
        protected array $caseFlexible,
    ) {}

    /**
     * The patterns, in the order they are tried.
     *
     * ORDER IS THE WHOLE DESIGN. Dates first, because «12/03/2026» contains
     * three digit runs that would otherwise be protected separately and could be
     * reassembled in a different order. Percentages before decimals, because
     * «60,3%» is one fact and «60,3» followed by a stray «%» is two. Bare digits
     * last, so they only ever catch what nothing more specific claimed.
     *
     * They are alternatives of ONE regex, applied in ONE pass, and that is not a
     * micro-optimisation. Running ten patterns in sequence means the later ones
     * scan text the earlier ones have already rewritten — and the first version
     * of this class did exactly that and protected the digits inside its own
     * markers, turning [[F1]] into [[F[[F3]]]]. PCRE tries alternatives left to
     * right at each position and never re-scans a replacement, so the order
     * below is the precedence and nothing can be protected twice.
     *
     * `caseFlexible` marks patterns whose text is a word: a word that moves from
     * the start of a sentence to the middle has to stop being capitalised.
     *
     * @return list<array{body: string, caseFlexible: bool}>
     */
    protected static function patterns(): array
    {
        $boundary = ['(?<![\p{L}\p{N}])', '(?![\p{L}\p{N}])'];

        $months = 'janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro';

        // Two upwards. See the note above on «um».
        $cardinals = 'dois|duas|tr[êe]s|quatro|cinco|seis|sete|oito|nove|dez|onze|doze|treze|catorze|quinze'
            .'|dezasseis|dezassete|dezoito|dezanove|vinte|trinta|quarenta|cinquenta|sessenta|setenta'
            .'|oitenta|noventa|cem|cento|duzentos|duzentas|trezentos|trezentas|mil|ambos|ambas';

        $ordinals = 'primeiro|primeira|segundo|segunda|terceiro|terceira|quarto|quarta|último|última';

        $periods = 'per[íi]odo|semestre|trimestre|momento de avalia[çc][ãa]o';

        return [
            // 12 de março de 2026
            ['body' => $boundary[0].'\d{1,2}\s+de\s+(?:'.$months.')\s+de\s+\d{4}', 'caseFlexible' => false],
            // março de 2026
            ['body' => $boundary[0].'(?:'.$months.')\s+de\s+\d{4}', 'caseFlexible' => true],
            ['body' => '\d{1,2}\/\d{1,2}\/\d{2,4}', 'caseFlexible' => false],
            ['body' => '\d{4}-\d{2}-\d{2}', 'caseFlexible' => false],
            // 60,3% and 60,3 % and 60%
            ['body' => '\d+(?:[.,]\d+)?\s*%', 'caseFlexible' => false],
            // «nível 3» travels whole: §14 forbids swapping the word for a
            // mention if that changes what is being claimed, and a marker that
            // contains both cannot be half-changed.
            ['body' => $boundary[0].'n[íi]ve(?:l|is)\s+\d+'.$boundary[1], 'caseFlexible' => true],
            ['body' => '\d+[.,]\d+', 'caseFlexible' => false],
            // «no segundo período» — the temporal scope (§3).
            ['body' => $boundary[0].'(?:'.$ordinals.')\s+(?:'.$periods.')'.$boundary[1], 'caseFlexible' => true],
            ['body' => $boundary[0].'(?:'.$cardinals.')'.$boundary[1], 'caseFlexible' => true],
            // Whatever is left that is a number at all.
            ['body' => '\d+', 'caseFlexible' => false],
        ];
    }

    public static function extract(string $text): self
    {
        $rules = self::patterns();

        $combined = '/'.implode('|', array_map(
            fn (int $index, array $rule): string => '(?<p'.$index.'>'.$rule['body'].')',
            array_keys($rules),
            $rules,
        )).'/iu';

        $values = [];
        $flexible = [];
        $byValue = [];
        $next = 'A';

        $redacted = (string) preg_replace_callback(
            $combined,
            function (array $match) use ($rules, &$values, &$flexible, &$byValue, &$next): string {
                $original = $match[0];
                $caseFlexible = false;

                foreach (array_keys($rules) as $index) {
                    if (($match['p'.$index] ?? '') !== '') {
                        $caseFlexible = $rules[$index]['caseFlexible'];

                        break;
                    }
                }

                // A word is matched case-insensitively, so «Três» and «três»
                // are one value. The marker stores the lowercase form and
                // restore decides the capitalisation from where it lands.
                $canonical = $caseFlexible ? self::lowerFirst($original) : $original;
                $lookup = $caseFlexible ? mb_strtolower($original) : $original;

                if (! array_key_exists($lookup, $byValue)) {
                    // LETTERS, NOT NUMBERS. A marker containing a digit would be
                    // a digit in the text that goes out, which would cost the
                    // guard its simplest and strongest rule: an answer with any
                    // digit in it is an answer that wrote a number of its own.
                    $marker = '[[F'.$next.']]';
                    $next++;

                    $byValue[$lookup] = $marker;
                    $values[$marker] = $canonical;
                    $flexible[$marker] = $caseFlexible;
                }

                return $byValue[$lookup];
            },
            $text,
        );

        return new self($redacted, $values, $flexible);
    }

    /** Whether there was anything worth protecting. */
    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * How many times each marker appears in the text that was sent.
     *
     * @return array<string, int>
     */
    public function expectedCounts(): array
    {
        return $this->countIn($this->redacted);
    }

    /**
     * How many times each marker appears in some other text.
     *
     * @return array<string, int>
     */
    public function countIn(string $text): array
    {
        $counts = [];

        foreach (array_keys($this->values) as $marker) {
            $counts[$marker] = substr_count($text, $marker);
        }

        return $counts;
    }

    /**
     * Markers in a text that this extraction never produced.
     *
     * An invented marker is not a harmless typo: [[FZ]] in an answer to a text
     * that only had three facts is the model producing a reference to a figure
     * that does not exist.
     *
     * @return list<string>
     */
    public function unknownMarkersIn(string $text): array
    {
        preg_match_all('/\[\[[^\]]*\]\]/u', $text, $matches);

        return array_values(array_unique(array_filter(
            $matches[0],
            fn (string $marker): bool => ! array_key_exists($marker, $this->values),
        )));
    }

    /**
     * Put the real values back.
     *
     * The capitalisation of a word-shaped value follows where it ended up: a
     * «três» that the rewrite moved out of the first position of a sentence
     * comes back lowercase, and one that moved into it comes back capitalised.
     * Nothing else about the text is touched.
     */
    public function restore(string $text): string
    {
        return (string) preg_replace_callback(
            '/\[\[F[A-Z]+\]\]/u',
            function (array $match) use ($text): string {
                // PREG_OFFSET_CAPTURE turns each group into [text, byte offset].
                [$marker, $offset] = $match[0];

                $value = $this->values[$marker] ?? $marker;

                if (($this->caseFlexible[$marker] ?? false) !== true) {
                    return $value;
                }

                return $this->startsSentence($text, (int) $offset)
                    ? self::upperFirst($value)
                    : self::lowerFirst($value);
            },
            $text,
            flags: PREG_OFFSET_CAPTURE,
        );
    }

    /**
     * Whether the character before this position ends a sentence (or there is none).
     *
     * The offset is in bytes, and the cut lands immediately before a `[`, which
     * is ASCII — so the prefix is always valid UTF-8 and can be measured.
     */
    protected function startsSentence(string $text, int $byteOffset): bool
    {
        $before = rtrim(substr($text, 0, $byteOffset));

        if ($before === '') {
            return true;
        }

        return in_array(mb_substr($before, -1), ['.', '!', '?', ':', ';'], strict: true);
    }

    protected static function lowerFirst(string $value): string
    {
        return mb_strtolower(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }

    protected static function upperFirst(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }
}
