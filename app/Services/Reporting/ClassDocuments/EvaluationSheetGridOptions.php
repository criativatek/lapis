<?php

namespace App\Services\Reporting\ClassDocuments;

/**
 * What the teacher chose to put on the printed grid of a class.
 *
 * PRESENTATION ONLY. Neither option changes a number: the columns of the Pauta
 * are always there — a grid without the percentages, the proposals or the
 * decisions would not be the Pauta. What is chosen is what is added and how the
 * page is turned.
 *
 * THE SERVER NORMALISES, NEVER TRUSTS (as in `InstrumentGridOptions`): the
 * options arrive as a query string and are read against a whitelist, so a
 * missing, repeated or forged value becomes the default, never an error and
 * never a surprise column.
 *
 *   autoavaliacao  what the students said of themselves — OFF by default: it is
 *                  supporting information, not part of the Pauta, and a sheet
 *                  handed round should not carry it unless it was asked for
 *   orientacao     horizontal (default) | vertical | auto — a Pauta is wide by
 *                  nature, so it is laid out landscape unless asked otherwise
 */
final readonly class EvaluationSheetGridOptions
{
    public function __construct(
        public bool $selfAssessment = false,
        /** @var 'auto'|'portrait'|'landscape' */
        public string $orientation = InstrumentGridLayout::LANDSCAPE,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        return new self(
            selfAssessment: self::flag($query['autoavaliacao'] ?? null),
            orientation: match ($query['orientacao'] ?? null) {
                'vertical' => InstrumentGridLayout::PORTRAIT,
                'auto' => InstrumentGridLayout::AUTO,
                default => InstrumentGridLayout::LANDSCAPE,
            },
        );
    }

    /**
     * @return array{self_assessment: bool, orientation: string}
     */
    public function toArray(): array
    {
        return [
            'self_assessment' => $this->selfAssessment,
            'orientation' => $this->orientation,
        ];
    }

    /**
     * The value as a yes or a no, OFF when it says neither. Anything that is not
     * a plain scalar — an array in the query string, say — is off, not a crash.
     */
    private static function flag(mixed $value): bool
    {
        if (! is_scalar($value) || $value === '') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
