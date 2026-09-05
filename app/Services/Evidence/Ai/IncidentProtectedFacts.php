<?php

namespace App\Services\Evidence\Ai;

/**
 * Every figure in a draft incident description, taken out before it leaves and
 * put back after — the Registos equivalent of
 * `App\Services\Reporting\Writing\ProtectedFacts` (SUP-U8FMAE).
 *
 * SAME ALGORITHM, OWN CLASS. The rule that makes the report guard simple —
 * the redacted text has no digit in it anywhere, so any digit in an answer is
 * one the model invented — holds exactly as well over «às 14h, no 2.º período,
 * empurrou um colega». A disciplinary description carries dates, times and
 * counts of people just as often as a report section carries percentages, so
 * this module keeps its own copy of the extraction rather than importing
 * Reporting's, per the boundary the two features are kept behind.
 *
 * See `App\Services\Reporting\Writing\ProtectedFacts` for the full reasoning
 * behind the pattern order, the «um/uma» exception and the case-flexible
 * restoration — none of it changed here.
 */
readonly class IncidentProtectedFacts
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
     * @return list<array{body: string, caseFlexible: bool}>
     */
    protected static function patterns(): array
    {
        $boundary = ['(?<![\p{L}\p{N}])', '(?![\p{L}\p{N}])'];

        $months = 'janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro';

        $cardinals = 'dois|duas|tr[êe]s|quatro|cinco|seis|sete|oito|nove|dez|onze|doze|treze|catorze|quinze'
            .'|dezasseis|dezassete|dezoito|dezanove|vinte|trinta|quarenta|cinquenta|sessenta|setenta'
            .'|oitenta|noventa|cem|cento|duzentos|duzentas|trezentos|trezentas|mil|ambos|ambas';

        $ordinals = 'primeiro|primeira|segundo|segunda|terceiro|terceira|quarto|quarta|último|última';

        $periods = 'per[íi]odo|semestre|trimestre|tempo letivo|aula';

        return [
            // 12 de março de 2026
            ['body' => $boundary[0].'\d{1,2}\s+de\s+(?:'.$months.')\s+de\s+\d{4}', 'caseFlexible' => false],
            // março de 2026
            ['body' => $boundary[0].'(?:'.$months.')\s+de\s+\d{4}', 'caseFlexible' => true],
            ['body' => '\d{1,2}\/\d{1,2}\/\d{2,4}', 'caseFlexible' => false],
            ['body' => '\d{4}-\d{2}-\d{2}', 'caseFlexible' => false],
            // 14h30, 14:30, às 14 horas
            ['body' => $boundary[0].'\d{1,2}[h:]\d{2}(?:m)?'.$boundary[1], 'caseFlexible' => false],
            ['body' => $boundary[0].'\d{1,2}\s*(?:h|horas)'.$boundary[1], 'caseFlexible' => false],
            ['body' => '\d+(?:[.,]\d+)?\s*%', 'caseFlexible' => false],
            ['body' => $boundary[0].'n[íi]ve(?:l|is)\s+\d+'.$boundary[1], 'caseFlexible' => true],
            ['body' => '\d+[.,]\d+', 'caseFlexible' => false],
            // «no segundo período», «na terceira aula»
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

                $canonical = $caseFlexible ? self::lowerFirst($original) : $original;
                $lookup = $caseFlexible ? mb_strtolower($original) : $original;

                if (! array_key_exists($lookup, $byValue)) {
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

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * @return array<string, int>
     */
    public function expectedCounts(): array
    {
        return $this->countIn($this->redacted);
    }

    /**
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

    public function restore(string $text): string
    {
        return (string) preg_replace_callback(
            '/\[\[F[A-Z]+\]\]/u',
            function (array $match) use ($text): string {
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
