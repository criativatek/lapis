<?php

namespace App\Services\Help\Ai;

use App\Support\Help\HelpArticle;

/**
 * Turns the engine's plain-text answer into a `HelpAnswer`.
 *
 * THE REFERENCES ARE THE SECURITY BOUNDARY of this class, and the reason it
 * takes the grounded article set as an argument instead of trusting the ids
 * it reads. Whatever the engine cites is intersected with what this
 * application actually put in front of it; an id that was never supplied is
 * dropped silently. A hallucinated «tutorials.advanced» therefore cannot
 * reach the screen as a link to a 404, no matter how confidently it was
 * written.
 *
 * A CITATION THAT NAMES NOTHING FALLS BACK TO EVERYTHING SUPPLIED, rather
 * than to no references at all. The articles were the basis of the answer
 * whether or not the engine remembered to list them, and showing a teacher
 * where an answer came from is the point of the feature — so the honest
 * fallback is the grounding set, in the relevance order the Centro de Ajuda's
 * own search already put it in.
 *
 * NULL MEANS UNUSABLE, and is distinct from `HelpAnswer::insufficient()`.
 * «Insufficient» is a real answer — the documentation does not cover this —
 * and is shown calmly. Null is an engine that answered with nothing this
 * application can read, which is a failure and is reported as one by the
 * caller.
 */
class HelpAnswerParser
{
    /**
     * @param  array<string, HelpArticle>  $grounding  the articles supplied to the engine, keyed by id, in relevance order.
     */
    public static function parse(string $text, array $grounding): ?HelpAnswer
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return null;
        }

        $body = self::section($trimmed, 'RESPOSTA');
        $cited = self::section($trimmed, 'ARTIGOS');

        // No RESPOSTA label at all: the whole reply is the answer, minus any
        // ARTIGOS line it did remember. Lenient on purpose — a well-formed
        // paragraph that forgot its label is still a usable answer, and the
        // part that has to be strict (the references) is strict below.
        if ($body === null) {
            $body = trim((string) preg_replace('/^\s*ARTIGOS\s*:.*$/mu', '', $trimmed));
        }

        if (self::saysInsufficient($body)) {
            return HelpAnswer::insufficient();
        }

        if ($body === '') {
            return null;
        }

        return new HelpAnswer(
            text: $body,
            references: self::references($cited, $grounding),
            sufficient: true,
        );
    }

    /**
     * The sentinel, and only the sentinel. Matched against the WHOLE answer
     * rather than searched for inside it: an answer that happens to quote the
     * word while explaining something is an answer, not a refusal.
     */
    protected static function saysInsufficient(string $body): bool
    {
        $folded = trim(preg_replace('/[^A-Z_]/u', '', mb_strtoupper($body, 'UTF-8')) ?? '');

        return $folded === HelpAssistantPrompt::INSUFFICIENT;
    }

    /**
     * The text under a `RÓTULO:` label, up to the next label or the end.
     * Null when the label is absent — distinct from present-but-empty.
     */
    protected static function section(string $text, string $label): ?string
    {
        $pattern = '/^\s*'.preg_quote($label, '/').'\s*:(.*?)(?=^\s*(?:RESPOSTA|ARTIGOS)\s*:|\z)/msu';

        if (preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * @param  array<string, HelpArticle>  $grounding
     * @return list<HelpAnswerReference>
     */
    protected static function references(?string $cited, array $grounding): array
    {
        $ids = $cited === null ? [] : array_filter(array_map(
            fn (string $id): string => trim($id, " \t\n\r\0\x0B\"'«»`.;"),
            preg_split('/[,\n;]+/u', $cited) ?: [],
        ));

        // The intersection, in the grounding's own order — never the order the
        // engine happened to list them in, which carries no meaning.
        $used = array_values(array_filter(
            $grounding,
            fn (HelpArticle $article): bool => in_array($article->id, $ids, strict: true),
        ));

        if ($used === []) {
            $used = array_values($grounding);
        }

        return array_map(HelpAnswerReference::fromArticle(...), $used);
    }
}
