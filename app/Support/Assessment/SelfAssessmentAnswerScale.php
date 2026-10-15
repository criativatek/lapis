<?php

namespace App\Support\Assessment;

use App\Domain\Assessment\Bc;
use App\Models\Scale;

/**
 * COMO se responde a uma pergunta de escala da autoavaliação, lido da própria
 * escala e da precisão da versão do perfil.
 *
 * A autoavaliação herda a escala da turma, e as escalas não são todas iguais:
 * uma «1 a 5» é uma lista fechada de níveis; uma «0 a 20», uma percentagem ou
 * uma escala própria da organização são intervalos, onde o aluno escreve um
 * número. Descrever isto uma só vez é o que impede o formulário, a validação no
 * servidor e a leitura de discordarem sobre o que é uma resposta válida.
 *
 * Nada aqui é inventado: um nível que a escala não tem nunca é oferecido, e uma
 * escala sem níveis nem limites válidos não se responde — diz-se porquê, ao
 * aluno e ao professor, em vez de a deixar como um seletor vazio.
 *
 * O NÚMERO É UM TEXTO (§24.4). Entra e sai como string decimal, comparado com
 * `Bc`; nunca passa por um float.
 */
final readonly class SelfAssessmentAnswerScale
{
    public const MODE_LEVELS = 'levels';

    public const MODE_NUMBER = 'number';

    public const MODE_UNAVAILABLE = 'unavailable';

    /** The column is DECIMAL(6,3): three places is the most it can keep. */
    private const MAX_DECIMALS = 3;

    /** …and a magnitude of 1000 or more does not fit in it. */
    private const LIMIT = '1000';

    /**
     * @param  list<array{id: int, code: string, label: string}>  $levels
     */
    private function __construct(
        private ?Scale $scale,
        private int $decimals,
        private string $mode,
        private ?string $problem,
        private array $levels,
        private ?string $min,
        private ?string $max,
    ) {}

    /**
     * @param  Scale|null  $scale  With its levels loaded, or they are loaded here.
     */
    public static function for(?Scale $scale, int $decimals): self
    {
        $decimals = max(0, min(self::MAX_DECIMALS, $decimals));

        if ($scale === null) {
            return new self(null, $decimals, self::MODE_UNAVAILABLE, 'no_scale', [], null, null);
        }

        if ($scale->classifiesByLevel()) {
            $scale->loadMissing('levels');

            $levels = [];

            foreach ($scale->levels as $level) {
                $levels[] = ['id' => (int) $level->id, 'code' => (string) $level->code, 'label' => (string) $level->label];
            }

            return $levels === []
                ? new self($scale, $decimals, self::MODE_UNAVAILABLE, 'levels_missing', [], null, null)
                : new self($scale, $decimals, self::MODE_LEVELS, null, $levels, null, null);
        }

        $min = $scale->min_value === null ? null : self::trimZeros((string) $scale->min_value);
        $max = $scale->max_value === null ? null : self::trimZeros((string) $scale->max_value);

        if ($min === null || $max === null
            || Bc::compare(Bc::of($min), Bc::of($max)) >= 0
            || Bc::compare(Bc::of(self::absolute($min)), Bc::of(self::LIMIT)) >= 0
            || Bc::compare(Bc::of(self::absolute($max)), Bc::of(self::LIMIT)) >= 0) {
            return new self($scale, $decimals, self::MODE_UNAVAILABLE, 'limits_missing', [], null, null);
        }

        return new self($scale, $decimals, self::MODE_NUMBER, null, [], $min, $max);
    }

    /** 'levels' | 'number' | 'unavailable' */
    public function mode(): string
    {
        return $this->mode;
    }

    /** null | 'no_scale' | 'levels_missing' | 'limits_missing' */
    public function problem(): ?string
    {
        return $this->problem;
    }

    /**
     * @return list<array{id: int, code: string, label: string}>
     */
    public function levels(): array
    {
        return $this->levels;
    }

    public function min(): ?string
    {
        return $this->min;
    }

    public function max(): ?string
    {
        return $this->max;
    }

    public function decimals(): int
    {
        return $this->decimals;
    }

    /** The increment of a numeric field: «1», «0.1», «0.01»… */
    public function step(): string
    {
        return $this->decimals === 0 ? '1' : '0.'.str_repeat('0', $this->decimals - 1).'1';
    }

    public function isPercentage(): bool
    {
        return $this->scale?->kind === 'percentage';
    }

    public function scaleName(): ?string
    {
        return $this->scale === null ? null : (string) $this->scale->name;
    }

    /**
     * What the student is told about how to answer.
     */
    public function instruction(): string
    {
        if ($this->mode === self::MODE_LEVELS) {
            $first = $this->levels[0];
            $last = $this->levels[count($this->levels) - 1];

            return __('Escolhe um nível de :first (:firstLabel) a :last (:lastLabel).', [
                'first' => $first['code'],
                'firstLabel' => $first['label'],
                'last' => $last['code'],
                'lastLabel' => $last['label'],
            ]);
        }

        if ($this->mode === self::MODE_NUMBER) {
            return __($this->isPercentage()
                ? 'Escreve uma percentagem de :min a :max:precision.'
                : 'Escreve um valor de :min a :max:precision.', [
                    'min' => self::display((string) $this->min),
                    'max' => self::display((string) $this->max),
                    'precision' => $this->precisionPhrase(),
                ]);
        }

        return __('Esta pergunta não pode ser respondida por agora. Avisa o teu professor.');
    }

    /**
     * What the teacher is told when the scale cannot be answered.
     */
    public function teacherProblem(): ?string
    {
        $name = $this->scaleName();

        if ($this->problem === 'no_scale') {
            return __('A turma não tem uma escala de avaliação associada, por isso as perguntas por domínio e a classificação proposta não podem ser respondidas.');
        }

        if ($this->problem === 'levels_missing') {
            return __('A escala «:name» é de níveis, mas não tem nenhum nível definido.', ['name' => $name]);
        }

        if ($this->problem === 'limits_missing') {
            return __('A escala «:name» não tem limites mínimo e máximo válidos.', ['name' => $name]);
        }

        return null;
    }

    /**
     * The error for a numeric answer, or null when it is valid or left empty.
     * Only meaningful in `number` mode; the caller decides what the other modes
     * accept.
     */
    public function validate(mixed $raw): ?string
    {
        $text = $this->clean($raw);

        if ($text === null) {
            return null;
        }

        $message = __($this->isPercentage()
            ? 'Indica uma percentagem de :min a :max:precision.'
            : 'Indica um valor de :min a :max:precision.', [
                'min' => self::display((string) $this->min),
                'max' => self::display((string) $this->max),
                'precision' => $this->precisionPhrase(),
            ]);

        if ($this->mode !== self::MODE_NUMBER || preg_match('/^-?\d+([.,]\d+)?$/', $text) !== 1) {
            return $message;
        }

        $value = self::trimZeros(str_replace(',', '.', $text));
        $fraction = str_contains($value, '.') ? substr($value, (int) strpos($value, '.') + 1) : '';

        if (strlen($fraction) > $this->decimals
            || Bc::compare(Bc::of($value), Bc::of((string) $this->min)) < 0
            || Bc::compare(Bc::of($value), Bc::of((string) $this->max)) > 0) {
            return $message;
        }

        return null;
    }

    /**
     * The value as it is stored: «16.5» from «16,5», without trailing zeros,
     * and null for an empty answer. Call it after validate().
     */
    public function normalise(mixed $raw): ?string
    {
        $text = $this->clean($raw);

        return $text === null ? null : self::trimZeros(str_replace(',', '.', $text));
    }

    /**
     * A stored numeric answer, ready for the screens that read it back.
     *
     * `sequence` is null on purpose: an interval has no band, so there is
     * nothing to rank one answer against another by — screens that compare do
     * so only when both sides have a sequence.
     *
     * `display` is what a cell shows: the number, with «%» when the scale is a
     * percentage. `label` is the sentence a tooltip or a report can carry.
     *
     * @return array{code: string, label: string, display: string, sequence: null, is_negative: bool, scale: string|null}
     */
    public function readable(string $numericValue): array
    {
        $value = self::display(self::trimZeros($numericValue));

        return [
            'code' => $value,
            'label' => match (true) {
                $this->isPercentage() => $value.'%',
                $this->mode === self::MODE_NUMBER => __(':value numa escala de :min a :max', [
                    'value' => $value,
                    'min' => self::display((string) $this->min),
                    'max' => self::display((string) $this->max),
                ]),
                // An answer kept from a scale that can no longer be described:
                // the number alone, never a made-up range.
                default => $value,
            },
            'display' => $this->isPercentage() ? $value.'%' : $value,
            'sequence' => null,
            'is_negative' => false,
            'scale' => $this->scaleName(),
        ];
    }

    /**
     * @return array{mode: string, levels: list<array{id: int, code: string, label: string}>, min: string|null, max: string|null, step: string, decimals: int, unit: string|null, instruction: string, problem: string|null}
     */
    public function toPayload(): array
    {
        return [
            'mode' => $this->mode,
            'levels' => $this->levels,
            'min' => $this->min,
            'max' => $this->max,
            'step' => $this->step(),
            'decimals' => $this->decimals,
            'unit' => $this->isPercentage() ? '%' : null,
            'instruction' => $this->instruction(),
            'problem' => $this->teacherProblem(),
        ];
    }

    private function precisionPhrase(): string
    {
        return match (true) {
            $this->decimals === 0 => ', sem casas decimais',
            $this->decimals === 1 => ', com até 1 casa decimal',
            default => ", com até {$this->decimals} casas decimais",
        };
    }

    private function clean(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $text = trim(is_scalar($raw) ? (string) $raw : '');

        return $text === '' ? null : $text;
    }

    /** «16.50» → «16.5»; «20.000» → «20»; «-0» → «0». */
    public static function trimZeros(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return in_array($value, ['', '-', '-0'], true) ? '0' : $value;
    }

    /** pt-PT writes the decimal separator as a comma. */
    private static function display(string $value): string
    {
        return str_replace('.', ',', $value);
    }

    private static function absolute(string $value): string
    {
        return ltrim($value, '-');
    }
}
