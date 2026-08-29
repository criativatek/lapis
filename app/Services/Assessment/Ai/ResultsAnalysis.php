<?php

namespace App\Services\Assessment\Ai;

/**
 * One reading of a period's RESULTS: how the assessment went, domain by
 * domain, on figures Lapispro had already decided.
 *
 * SIX BLOCKS, AND EACH ONE IS A DIFFERENT KIND OF STATEMENT. That is the whole
 * reason they are separate fields rather than one paragraph:
 *
 *   summary            what the period looks like, in three sentences.
 *   patterns           what the figures DO. Descriptive, checkable against
 *                      the table above it.
 *   strengths          where the evidence is solid — «consolidação».
 *   attentionPoints    where it is not — «recuperação». Still descriptive:
 *                      a domain, a coverage warning, a dispersion.
 *   suggestions        what a teacher MIGHT consider. Proposals, explicitly.
 *   cautions           what this reading cannot support — too few results,
 *                      partial coverage, a period with no comparison.
 *
 * Collapsing any two of them is how an observation quietly becomes an
 * instruction, and how «faltam elementos neste domínio» becomes «este domínio
 * correu mal». The panel renders six typed lists and can render nothing else.
 *
 * `cautions` IS THE ONE THAT MUST NOT BE OPTIONAL IN PRACTICE. A reading of an
 * assessment whose coverage is partial and that says nothing about it is worse
 * than no reading: it reads as a verdict on evidence that is not all there.
 * The prompt asks for it explicitly and `ResultsAnalysisContext` sends the
 * coverage figures that make it answerable — but the parser does not REQUIRE
 * it, because a period with complete coverage genuinely has nothing to say
 * here, and a parser that demanded a caution would be asking the model to
 * invent one.
 *
 * NOTHING HERE CAN BE ACTED ON BY THE APPLICATION. No id, no key, no
 * enrolment, no verb: every field is a sentence for a person to read. There is
 * no endpoint that accepts one of these back, and no field on it that would
 * mean anything to one if there were.
 */
readonly class ResultsAnalysis
{
    /**
     * @param  list<string>  $patterns  What the figures do — descriptive.
     * @param  list<string>  $strengths  Where the evidence is solid.
     * @param  list<string>  $attentionPoints  Where it may deserve a second look.
     * @param  list<string>  $suggestions  What the teacher might consider. Proposals, never instructions.
     * @param  list<string>  $cautions  What this reading cannot support.
     */
    public function __construct(
        public string $summary,
        public array $patterns,
        public array $strengths,
        public array $attentionPoints,
        public array $suggestions,
        public array $cautions,
    ) {}

    /**
     * @return array{summary: string, patterns: list<string>, strengths: list<string>, attention_points: list<string>, suggestions: list<string>, cautions: list<string>}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'patterns' => $this->patterns,
            'strengths' => $this->strengths,
            'attention_points' => $this->attentionPoints,
            'suggestions' => $this->suggestions,
            'cautions' => $this->cautions,
        ];
    }
}
