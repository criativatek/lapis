<?php

namespace App\Services\Reporting\Export;

/**
 * The document's own heading and the line of metadata under it (§47).
 *
 * ONE HIERARCHY, SAID ONCE. A report's stored title is written for a listing —
 * «Relatório de turma · 7.º A · Ano letivo» tells a teacher which of eleven
 * rows to click. On the document itself that same string sat directly above a
 * subtitle that repeated most of it, so every export opened with the words
 * «Relatório de turma» twice. The title names the document; the line under it
 * carries the turma, the disciplina and the período. Neither says what the
 * other already said.
 *
 * IT DOES NOT REWRITE WHAT THE TEACHER TYPED. A title that is still the one
 * generated at creation is made of the metadata and therefore dissolves into
 * it, leaving the type label as the heading. A title the teacher wrote
 * («Balanço para o conselho de turma») shares nothing with the metadata, so
 * every word of it survives and the type label moves down into the subtitle.
 * The rule is the same in both cases — a segment that the metadata already
 * states is dropped — which is why there is no flag anywhere recording whether
 * a title was renamed.
 *
 * THE SAME ANSWER FEEDS ALL THREE RENDERINGS. The online preview, the .docx and
 * the PDF read this, so the three cannot disagree about what the document is
 * called.
 */
final class DocumentHeading
{
    /** The middot the whole application composes labels with. */
    public const SEPARATOR = ' · ';

    /**
     * @param  string  $title  The report's stored title.
     * @param  string  $typeLabel  «Relatório de turma», «Relatório individual», …
     * @param  list<string|null>  $metadata  Turma, disciplina, aluno, âmbito — atomic, in reading order.
     * @return array{title: string, subtitle: string}
     */
    public static function for(string $title, string $typeLabel, array $metadata): array
    {
        $typeLabel = trim($typeLabel);
        $parts = self::clean($metadata);

        // What the metadata line will say, plus the type label: a title segment
        // that repeats any of it is redundant on the document.
        $alreadySaid = array_values(array_filter([$typeLabel, ...$parts], fn (string $part) => $part !== ''));

        $own = array_values(array_filter(
            self::segments($title),
            fn (string $segment) => ! self::isSaidBy($segment, $alreadySaid),
        ));

        // Nothing of the title survived: it was made of metadata, so the
        // document is called by its kind.
        $heading = $own === [] ? $typeLabel : implode(self::SEPARATOR, $own);

        // The type label belongs in the subtitle only when the heading is not
        // already it — otherwise «Relatório de turma» would print twice again.
        $subtitle = self::isSame($heading, $typeLabel) ? $parts : [$typeLabel, ...$parts];

        $subtitle = array_values(array_filter(
            $subtitle,
            fn (string $part) => $part !== '' && ! self::contains($heading, $part),
        ));

        return [
            'title' => $heading === '' ? $title : $heading,
            'subtitle' => implode(self::SEPARATOR, $subtitle),
        ];
    }

    /**
     * @param  list<string|null>  $values
     * @return list<string>
     */
    private static function clean(array $values): array
    {
        $cleaned = [];

        foreach ($values as $value) {
            $trimmed = trim((string) $value);

            // Deduplicated as it is built: a Registos report about one class
            // can legitimately be handed the same label twice.
            if ($trimmed !== '' && ! in_array($trimmed, $cleaned, strict: true)) {
                $cleaned[] = $trimmed;
            }
        }

        return $cleaned;
    }

    /**
     * A title split on the separator the application composes titles with.
     *
     * @return list<string>
     */
    private static function segments(string $title): array
    {
        // Split on the middot with or without its spaces: a teacher typing a
        // title by hand does not reliably reproduce the thin spacing.
        $segments = preg_split('/\s*·\s*/u', trim($title)) ?: [];

        return array_values(array_filter(array_map('trim', $segments), fn (string $segment) => $segment !== ''));
    }

    /**
     * Whether one of the metadata parts already states this title segment.
     *
     * «Ano letivo» is said by «Ano letivo até ao momento» — the generated title
     * carries the short form and the scope label the long one, and printing
     * both is exactly the repetition this class exists to remove. A segment is
     * only absorbed by a part that STARTS with it, so «Leitura» is never
     * swallowed by «Educação Literária».
     *
     * @param  list<string>  $candidates
     */
    private static function isSaidBy(string $segment, array $candidates): bool
    {
        $needle = self::fold($segment);

        if ($needle === '') {
            return true;
        }

        foreach ($candidates as $candidate) {
            $haystack = self::fold($candidate);

            if ($haystack === $needle || str_starts_with($haystack, $needle.' ')) {
                return true;
            }
        }

        return false;
    }

    /** Whether the heading already contains this metadata part anywhere in it. */
    private static function contains(string $heading, string $part): bool
    {
        return str_contains(self::fold($heading), self::fold($part));
    }

    private static function isSame(string $left, string $right): bool
    {
        return self::fold($left) === self::fold($right);
    }

    /** Compared as the reader sees them, not as the database stores them. */
    private static function fold(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }
}
