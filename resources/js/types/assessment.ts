/**
 * Why a coverage ⚠ was raised on one value.
 *
 * Built server-side by CoverageExplanation from the engine's own explanation —
 * the UI formats these facts into a sentence but never decides which elements
 * caused the flag, so the note can never contradict the calculation.
 */
export type CoverageExclusion = {
    /** The instrument it was recorded on, as the teacher named it. */
    instrument: string;
    /** Already formatted dd/mm/yyyy: the day it was applied. */
    applied_on: string;
    /** The recorded ResultState value — `absent`, `absent_justified`, … The page words it; it never infers it. */
    reason: string;
    /** How many of the instrument's questions this occurrence covers. */
    item_count: number;
};

export type Coverage = {
    absences: CoverageExclusion[];
    /** Nothing has been assessed here yet — the other reason for the flag, with no absence involved. */
    no_elements: boolean;
    /** Domains that carried no value and were left out, their weight renormalized over the rest. */
    excluded_domain_ids: number[];
};
