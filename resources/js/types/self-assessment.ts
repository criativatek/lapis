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
    /** Empty for a written question — and for a scale question whose scale has no levels to choose from. */
    levels: SelfAssessmentLevel[];
    /** The name of the scale a scale question is asked on; null for a written question. */
    scale: string | null;
    answer_level_id: number | null;
    answer_text: string | null;
    /** How this scale question is answered — read from the scale itself. Null for a written question. */
    answer: SelfAssessmentAnswerScale | null;
    /** The numeric answer, without trailing zeros ('16', '16.5'); null when empty or when answered by level. */
    answer_number: string | null;
};

/**
 * HOW a scale question is answered: by choosing a level, by writing a number
 * inside the limits of the scale, or not at all (a scale with a fault).
 * Decided server-side from the class's scale; the page never infers it.
 */
export type SelfAssessmentAnswerScale = {
    mode: 'levels' | 'number' | 'unavailable';
    levels: SelfAssessmentLevel[];
    min: string | null;
    max: string | null;
    step: string;
    decimals: number;
    unit: '%' | null;
    instruction: string;
    /** The explanation shown to the teacher when mode is `unavailable`. */
    problem: string | null;
};

/** The criteria a self-assessment form was built from: the scale and the domains with their weights. */
export type SelfAssessmentCriteria = { scale: string | null; domains: { name: string; weight: string }[] };

/** A self-assessment of this student in this period, filled in under earlier criteria. Read-only. */
export type SelfAssessmentEarlierRound = { submitted_at: string | null; criteria: string; answers: { prompt: string; value: string | null }[] };

export type SelfAssessmentConfiguration = {
    profile: string | null;
    version: number | null;
    scale: string | null;
    domains: { name: string; weight: string }[];
    answer: SelfAssessmentAnswerScale | null;
    state: 'current' | 'previous' | 'no_profile';
    class_criteria: { profile: string | null; version: number | null; scale: string | null } | null;
    saved_count: number;
    can_renew: boolean;
    can_edit: boolean;
    class_url: string;
    review: { version: number; scale: string | null; url: string } | null;
};

export type SelfAssessmentReflectionQuestion = { id: number; role: string; role_label: string; prompt: string; answered: boolean };
