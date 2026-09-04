import type { Coverage } from './assessment';

/**
 * The Pauta de Avaliação read model — mirrors `BuildEvaluationSheet::for()`
 * on the server exactly, plus the presentation-only `color` the controller
 * injects per domain (never sent by the calculation engine itself).
 *
 * ONE SHAPE, NOT THREE VARIANTS. The page never asks the server for a
 * "quantitative view" or a "qualitative view" — every group of information
 * is always in this payload, and a toggle on the page only hides a group
 * visually. Nothing here is recomputed or reshaped client-side.
 */

export type EvaluationSheetDomain = {
    domain_id: number;
    name: string;
    sequence: number;
    weight_percent: string;
    /** Hex string. Identity only — never a "bom/mau" signal (§ briefing). */
    color: string;
};

export type EvaluationSheetOverall = {
    normalized_value: string | null;
    scale_value: string | null;
    scale_level_id: number | null;
    scale_level_label: string | null;
    result_state: string;
    has_coverage_warning: boolean;
};

export type EvaluationSheetStudentDomain = {
    domain_id: number;
    name: string;
    sequence: number;
    normalized_value: string | null;
    weight_percent_applied: string;
    scale_level_id: number | null;
    scale_level_label: string | null;
    has_coverage_warning: boolean;
    coverage: Coverage;
};

/**
 * `null` when the class has no classification row yet for this student and
 * period — the Pauta shows "—", never a proposal read as if it were one.
 */
export type EvaluationSheetClassification = {
    status: string;
    proposed_value: string | null;
    proposed_scale_level_id: number | null;
    proposed_scale_level_label: string | null;
    final_value: string | null;
    final_scale_level_id: number | null;
    final_scale_level_label: string | null;
    override_reason: string | null;
} | null;

export type EvaluationSheetStudent = {
    enrollment_id: number;
    class_number: number | null;
    name: string;
    overall: EvaluationSheetOverall;
    domains: EvaluationSheetStudentDomain[];
    classification: EvaluationSheetClassification;
    coverage: Coverage;
};

export type EvaluationSheet = {
    class_id: number;
    academic_period_id: number;
    scope: string;
    domains: EvaluationSheetDomain[];
    students: EvaluationSheetStudent[];
};

/** A period in the selector — labels are always dynamic, never hardcoded. */
export type EvaluationSheetPeriod = {
    ulid: string;
    label: string;
    kind_label: string;
    selected: boolean;
};
