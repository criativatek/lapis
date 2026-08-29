<?php

namespace App\Services\Progress\Ai;

/**
 * One synthesis of a student's Evolução: what the record shows, what changed,
 * and what a teacher might do next.
 *
 * SIX BLOCKS, AND THE FIRST THREE ARE THE PANEL'S OWN PHILOSOPHY. Evolução do
 * Aluno was built on the rule that attention and progress are shown TOGETHER —
 * `BuildStudentFactualAlerts` and `BuildStudentStrengths` are computed on every
 * request, never one without the other, because a panel that only lists
 * problems teaches a teacher to read a child as a problem. This reading obeys
 * the same rule: `positiveSignals` is not optional decoration beside
 * `attentionSignals`, it is half of the answer.
 *
 *   summary            the 360º reading, in three sentences.
 *   positiveSignals    what is going well, and what is consolidated.
 *   attentionSignals   what may deserve a second look. Descriptive.
 *   whatChanged        the trend: since the last period, since an intervention.
 *   nextSteps          what the teacher MIGHT consider — including how to open
 *                      a conversation with the student or the family.
 *   cautions           what this reading cannot support.
 *
 * THE FACTS ARE NOT IN HERE, AND THAT IS DELIBERATE (§8 of the brief:
 * «distinguir FACTO calculado pelo sistema vs INTERPRETAÇÃO da IA»). The panel
 * already shows the factual alerts and the strengths, computed
 * deterministically, above where this appears. Everything in this object is
 * INTERPRETATION, is labelled as such on screen, and is written in the language
 * of possibility. A reader who wants to know what the system actually counted
 * looks at the block above; a reader who wants a way into a conversation looks
 * at this one.
 *
 * NOTHING HERE CAN BE ACTED ON BY THE APPLICATION. No id, no key, no
 * enrolment, no verb — every field is a sentence for a person to read. There is
 * no endpoint that accepts one of these back, and no field on it that would
 * mean anything to one if there were.
 */
readonly class FollowupSynthesis
{
    /**
     * @param  list<string>  $positiveSignals  What is going well.
     * @param  list<string>  $attentionSignals  What may deserve a second look.
     * @param  list<string>  $whatChanged  The trend, and what moved since when.
     * @param  list<string>  $nextSteps  What the teacher might consider. Proposals, never instructions.
     * @param  list<string>  $cautions  What this reading cannot support.
     */
    public function __construct(
        public string $summary,
        public array $positiveSignals,
        public array $attentionSignals,
        public array $whatChanged,
        public array $nextSteps,
        public array $cautions,
    ) {}

    /**
     * @return array{summary: string, positive_signals: list<string>, attention_signals: list<string>, what_changed: list<string>, next_steps: list<string>, cautions: list<string>}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'positive_signals' => $this->positiveSignals,
            'attention_signals' => $this->attentionSignals,
            'what_changed' => $this->whatChanged,
            'next_steps' => $this->nextSteps,
            'cautions' => $this->cautions,
        ];
    }
}
