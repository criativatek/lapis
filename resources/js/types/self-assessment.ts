/**
 * One band of the scale the class is assessed on — 1–5, 0–20, whatever the
 * profile version says. Sent per question, never assumed by the page.
 */
export type SelfAssessmentLevel = {
    id: number;
    code: string;
    label: string;
};

/**
 * One question of the self-assessment, as the fill-in form receives it.
 *
 * `block` and the order of the list are decided server-side, from the question's
 * domain or its stated role — the page renders what it is given and never reads
 * a question's wording to work out what it is for.
 *
 * There is no calculated value in this shape, deliberately (§3): what is being
 * collected is the student's own reading of the period, and a percentage on
 * screen is an answer to copy.
 */
export type SelfAssessmentQuestion = {
    id: number;
    /** `performance` · `reflection` · `work` — plus `other`, for a question identified neither by a domain nor by a role. */
    block: string;
    /** `global`, `rationale`, `improvement`, `liked`, `struggled` — or null for a per-domain question. */
    role: string | null;
    /** The domain it is about, named. Null for every question that belongs to the period as a whole. */
    domain: string | null;
    prompt: string;
    answer_kind: string;
    /** Empty for a written question. */
    levels: SelfAssessmentLevel[];
    answer_level_id: number | null;
    answer_text: string | null;
};
