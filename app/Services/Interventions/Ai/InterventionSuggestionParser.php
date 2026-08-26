<?php

namespace App\Services\Interventions\Ai;

use App\Models\InterventionPurpose;

/**
 * Turns the engine's plain-text answer into StrategySuggestion objects.
 *
 * NOTHING LOAD-BEARING SURVIVES THAT IS NOT WELL-FORMED. A block missing
 * OBJETIVO or APLICACAO is dropped rather than guessed into shape — a
 * suggestion this application half-understood is not a suggestion it can
 * offer a teacher. NOME is deliberately optional: losing a short display
 * title must not discard an otherwise complete pedagogical proposal.
 */
class InterventionSuggestionParser
{
    public const MAX_SUGGESTIONS = 3;

    /**
     * @return list<StrategySuggestion>
     */
    public static function parse(string $text, InterventionPurpose $purpose): array
    {
        $blocks = preg_split('/\n-{3,}\n/', trim($text)) ?: [];
        $suggestions = [];

        foreach ($blocks as $block) {
            $fields = self::fields($block);

            $objective = trim($fields['OBJETIVO'] ?? '');
            $strategy = trim($fields['APLICACAO'] ?? '');

            if ($objective === '' || $strategy === '') {
                continue;
            }

            $suggestions[] = new StrategySuggestion(
                name: self::orNull($fields['NOME'] ?? null),
                purpose: $purpose->value,
                objective: $objective,
                strategy: $strategy,
                frequency: self::orNull($fields['FREQUENCIA'] ?? null),
                duration: self::orNull($fields['DURACAO'] ?? null),
                trackingIndicator: self::orNull($fields['INDICADOR'] ?? null),
                reviewSuggestion: self::orNull($fields['REVISAO'] ?? null),
            );

            if (count($suggestions) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * @return array<string, string>
     */
    protected static function fields(string $block): array
    {
        $fields = [];

        foreach (preg_split('/\r?\n/', trim($block)) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $fields[strtoupper(trim($key))] = trim($value);
        }

        return $fields;
    }

    protected static function orNull(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
