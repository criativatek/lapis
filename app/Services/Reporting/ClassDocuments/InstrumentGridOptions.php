<?php

namespace App\Services\Reporting\ClassDocuments;

/**
 * What the teacher chose to put on the printed grid of an instrument.
 *
 * PRESENTATION ONLY. None of these options changes a number: they choose which
 * columns and tables are drawn. The questions themselves are always there — a
 * grid without them would be the printout of Resultados.
 *
 * THE SERVER NORMALISES, NEVER TRUSTS. The options arrive as a query string and
 * `fromQuery()` reads them against a whitelist: a missing, repeated or forged
 * value becomes the default, never an error and never a surprise column. They
 * are all ON by default, as in the class documents: `?bruta=0` turns one off.
 *
 *   bruta       pontuação bruta (pontos / cotação)
 *   resultado   resultado do motor e nível
 *   dominios    um resultado por domínio — só quando o instrumento toca dois ou
 *               mais; com um só é igual ao global e nunca se imprime
 *   legenda     a tabela das questões (código, grupo, descrição, cotação, domínios)
 *   orientacao  auto | vertical | horizontal
 */
final readonly class InstrumentGridOptions
{
    public function __construct(
        public bool $rawScore = true,
        public bool $result = true,
        public bool $domains = true,
        public bool $legend = true,
        /** @var 'auto'|'portrait'|'landscape' */
        public string $orientation = InstrumentGridLayout::AUTO,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        return new self(
            rawScore: self::flag($query['bruta'] ?? null),
            result: self::flag($query['resultado'] ?? null),
            domains: self::flag($query['dominios'] ?? null),
            legend: self::flag($query['legenda'] ?? null),
            orientation: match ($query['orientacao'] ?? null) {
                'vertical' => InstrumentGridLayout::PORTRAIT,
                'horizontal' => InstrumentGridLayout::LANDSCAPE,
                default => InstrumentGridLayout::AUTO,
            },
        );
    }

    /**
     * The options as they travel in the document, for the preview's own form.
     *
     * @return array{raw_score: bool, result: bool, domains: bool, legend: bool, orientation: string}
     */
    public function toArray(): array
    {
        return [
            'raw_score' => $this->rawScore,
            'result' => $this->result,
            'domains' => $this->domains,
            'legend' => $this->legend,
            'orientation' => $this->orientation,
        ];
    }

    /**
     * On unless the value is a clear «no». Anything that is not a plain scalar —
     * an array in the query string, say — is the default, not a crash.
     */
    private static function flag(mixed $value): bool
    {
        if (! is_scalar($value) || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }
}
