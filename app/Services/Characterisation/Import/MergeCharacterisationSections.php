<?php

namespace App\Services\Characterisation\Import;

use App\Support\Characterisation\CharacterisationSection;
use App\Support\Characterisation\SectionMergeAction;

/**
 * Decides what an import does to a section a teacher may have already
 * written in — and it never decides "replace".
 *
 * IMPORTING IS ADDITIVE, ON PURPOSE. Handing this class the full new text and
 * letting it become the stored value would mean a spreadsheet could erase a
 * sentence a teacher spent ten minutes writing, simply because that
 * spreadsheet's column happened to exist. So the only things this class can
 * produce are: append (ADD), do nothing because it is already there
 * (ALREADY_PRESENT), do nothing because there is nowhere to put it
 * (NO_DESTINATION), or do nothing because there is nothing to put
 * (IGNORE). Replacing what is recorded is only ever a later, deliberate,
 * manual edit a teacher makes on the edit screen — this class has no path
 * that produces it.
 *
 * IDEMPOTENCY (§25): comparison is normalised — collapsed whitespace,
 * normalised line breaks, trimmed, case- and accent-insensitive — so
 * re-importing the exact same table twice does not duplicate a paragraph
 * merely because a line break or a capital letter differs. That normalisation
 * is used ONLY to decide whether to write; the text that gets appended is
 * always the incoming text exactly as it arrived, never a normalised
 * rewrite of it. There is deliberately no semantic dedup: "Dificuldade na
 * leitura" and "Apresenta dificuldade na leitura" compare as different
 * strings and both survive, because collapsing near-duplicate sentences would
 * be this class deciding they mean the same thing, which is not a decision an
 * importer gets to make about a child's record.
 */
class MergeCharacterisationSections
{
    /**
     * @param  array<string, string|null>  $current  Keyed by CharacterisationSection value.
     * @param  array<string, string|null>  $incoming  Keyed by CharacterisationSection value.
     * @return array<string, SectionMergeResult>
     */
    public function merge(array $current, array $incoming): array
    {
        $results = [];

        foreach (CharacterisationSection::keys() as $key) {
            $results[$key] = $this->mergeSection($key, $current[$key] ?? null, $incoming[$key] ?? null);
        }

        return $results;
    }

    private function mergeSection(string $section, ?string $currentValue, ?string $incomingValue): SectionMergeResult
    {
        $incomingTrimmed = trim((string) $incomingValue);

        if ($incomingTrimmed === '') {
            return new SectionMergeResult($section, SectionMergeAction::Ignore, $currentValue, $incomingValue, $currentValue);
        }

        $currentTrimmed = trim((string) $currentValue);

        if ($currentTrimmed === '') {
            return new SectionMergeResult($section, SectionMergeAction::Add, $currentValue, $incomingValue, $incomingTrimmed);
        }

        if ($this->contains($currentTrimmed, $incomingTrimmed)) {
            return new SectionMergeResult($section, SectionMergeAction::AlreadyPresent, $currentValue, $incomingValue, $currentValue);
        }

        // Appending joins with a blank line — the same paragraph convention
        // BuildCharacterisationPreview already uses when two columns map to
        // the same section (see sectionsFor()).
        $merged = $currentTrimmed."\n\n".$incomingTrimmed;

        return new SectionMergeResult($section, SectionMergeAction::Add, $currentValue, $incomingValue, $merged);
    }

    /**
     * Whether `$needle`, once normalised for comparison, is already contained
     * in `$haystack`. Never touches what actually gets stored.
     */
    private function contains(string $haystack, string $needle): bool
    {
        return str_contains($this->normalise($haystack), $this->normalise($needle));
    }

    /**
     * Comparison-only normalisation: collapsed whitespace, normalised line
     * breaks, trimmed, lower-cased, and stripped of accents. Two paragraphs
     * that read identically to a person once you ignore how they happened to
     * be typed compare equal here — nothing more permissive than that.
     */
    private function normalise(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);
        $value = mb_strtolower($value);

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $transliterated === false ? $value : $transliterated;
    }
}
