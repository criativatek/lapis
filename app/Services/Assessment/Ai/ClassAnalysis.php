<?php

namespace App\Services\Assessment\Ai;

/**
 * One reading of a class's statistics: what the figures look like, what
 * stands out, what deserves a second look, and what a teacher might consider.
 *
 * FOUR BLOCKS, BECAUSE THE SCREEN HAS FOUR BLOCKS. The shape is the contract
 * between the parser and the panel, and it exists so that no JSON, no
 * markdown and no raw model output is ever put in front of a teacher — the
 * page renders four typed lists and cannot render anything else.
 *
 * THE SEPARATION IS PEDAGOGICAL, NOT COSMETIC. «Padrões observados» is a
 * description of what the numbers do; «Pontos de atenção» is where a
 * description stops and a judgement would begin; «Sugestões pedagógicas» is
 * explicitly a proposal. Collapsing them into one paragraph is how an
 * observation quietly becomes an instruction, which is the exact failure the
 * §5 principle — a IA sugere, o professor decide — exists to prevent.
 *
 * NOTHING HERE CAN BE ACTED ON BY THE APPLICATION. There is no id, no key, no
 * enrollment and no verb: every field is a sentence for a person to read.
 * That is deliberate and is the reason this object is safe to hand to a
 * screen — there is no endpoint that could accept it back and no field that
 * would mean anything to one if there were.
 */
readonly class ClassAnalysis
{
    /**
     * @param  list<string>  $patterns  What the figures do — descriptive.
     * @param  list<string>  $cautions  Where the figures may warrant a closer look.
     * @param  list<string>  $suggestions  What the teacher might consider. Proposals, never instructions.
     */
    public function __construct(
        public string $summary,
        public array $patterns,
        public array $cautions,
        public array $suggestions,
    ) {}

    /**
     * @return array{summary: string, patterns: list<string>, cautions: list<string>, suggestions: list<string>}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'patterns' => $this->patterns,
            'cautions' => $this->cautions,
            'suggestions' => $this->suggestions,
        ];
    }
}
