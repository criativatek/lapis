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

/**
 * «Preparar fecho» — the readiness reading over the sheet, mirroring
 * `EvaluationSheetReadiness::for()` exactly. THREE STATES AND NEVER MORE:
 * `ok`, `attention`, `neutral` («não aplicável»/informativo). There is no
 * "error" on purpose — a pending decision is the teacher's to make, not a
 * fault to fix, and nothing in this payload blocks anything (§3.3).
 */
export type EvaluationSheetReadinessState = 'ok' | 'attention' | 'neutral';

/** Where a line's «Ver» goes. The server names the destination, never the URL. */
export type EvaluationSheetReadinessAction =
    | 'classifications'
    | 'results'
    | 'self-assessments'
    | 'self-assessment'
    | 'inovar'
    | null;

export type EvaluationSheetReadinessItem = {
    key: string;
    state: EvaluationSheetReadinessState;
    label: string;
    detail: string | null;
    action: EvaluationSheetReadinessAction;
};

export type EvaluationSheetReadinessPending = {
    state: EvaluationSheetReadinessState;
    label: string;
    action: EvaluationSheetReadinessAction;
};

export type EvaluationSheetReadinessStudent = {
    enrollment_ulid: string | null;
    class_number: number | null;
    name: string;
    pending: EvaluationSheetReadinessPending[];
};

export type EvaluationSheetReadiness = {
    /** The moment named by its OWN configuration — never a hardcoded word. */
    moment: { period_label: string; kind_label: string; is_closing: boolean };
    summary: {
        students_total: number;
        students_with_notes: number;
        students_ready: number;
        attention_count: number;
    };
    items: EvaluationSheetReadinessItem[];
    students: EvaluationSheetReadinessStudent[];
};

/** A period in the selector — labels are always dynamic, never hardcoded. */
export type EvaluationSheetPeriod = {
    ulid: string;
    label: string;
    kind_label: string;
    selected: boolean;
};

/**
 * What the «Guardar esta pauta» form opens with. Both fields are editable —
 * the title is only a suggestion built from the period's own configuration,
 * and `starts_on`/`ends_on` are the boundaries the server will enforce anyway,
 * shown so the teacher is not refused after the fact.
 */
export type EvaluationSheetSaveDefaults = {
    period_ulid: string;
    moment_label: string;
    effective_at: string;
    starts_on: string;
    ends_on: string;
};

/**
 * The frozen document — `CaptureEvaluationSheet`'s payload, read back verbatim.
 *
 * EVERYTHING NEEDED TO REDRAW THE SCREEN IS IN HERE. Nothing on the snapshot
 * page joins anything live: not the class, not the period's current name, not
 * today's domain colours. `period` in particular is the label as it read at the
 * time, and renaming a period afterwards must never rewrite the past.
 */
export type EvaluationSheetSnapshot = {
    version: number;
    scope: string;
    class: { label: string; subject: string; academic_year: string };
    period: { label: string; kind_label: string };
    moment: { label: string; effective_at: string };
    author: { name: string };
    domains: EvaluationSheetDomain[];
    students: EvaluationSheetStudent[];
    /** Sentences in pt-PT, never raw payload — read months later by a person. */
    warnings: string[];
};

/**
 * One row of history. There is no persisted "is latest": the list arrives
 * ordered and the first row is the most recent one, marked in the UI only.
 */
export type EvaluationSheetHistoryEntry = {
    ulid: string;
    moment_label: string;
    /** From the SNAPSHOT, never from the period as it is configured today. */
    period_label: string | null;
    period_kind_label: string | null;
    scope: string;
    scope_label: string;
    effective_at: string | null;
    exported_at: string;
    author: string | null;
    status_label: string;
    /** What produced the record: 'snapshot' when it was merely kept, 'inovar' when a grid was generated. */
    adapter: string;
    has_file: boolean;
    warning_count: number;
    warnings: string[];
};

/**
 * A column of the uploaded grid that is NOT a domain — somewhere the level
 * could go.
 *
 * `header` is normally null: neither real INOVAR grid names the column that
 * carries the level, which is exactly why the teacher chooses it rather than
 * the system guessing «the one after the domains». The samples are the first
 * values found on the students' own rows, so the column can be recognised.
 */
export type InovarLevelCandidate = {
    column: string;
    header: string | null;
    samples: string[];
};

/** One mention that will be written into one cell of the grid. */
export type InovarExportCell = {
    column: string;
    domain: string | null;
    band: string | null;
    code: string | null;
    writable: boolean;
    partial: boolean;
};

/**
 * One line of the grid, as the FILE sees it: which student it matched, what
 * goes into it, and the level if the teacher includes one.
 *
 * `level` is the teacher's DECISION or null. Never a proposal, never a zero —
 * null leaves the cell exactly as the grid had it.
 */
export type InovarExportRow = {
    row: number;
    process_number: string | null;
    name: string;
    matched: boolean;
    issues: string[];
    domains: InovarExportCell[];
    level: string | null;
};

/** Everything the preparation screen shows, straight from the file on disk. */
export type InovarExportPreparation = {
    students: InovarExportRow[];
    domains: {
        inovar_column: string;
        inovar_header: string;
        lapis_domain: string | null;
        mapped: boolean;
        issues: string[];
    }[];
    summary: {
        matched_students: number;
        unmatched_students: number;
        mapped_domains: number;
        unmapped_domains: number;
        ready_cells: number;
        warnings: string[];
        blocking_errors: string[];
    };
    source: { label: string; reference_label: string | null };
    level: {
        candidates: InovarLevelCandidate[];
        /** On for a moment that closes the period, off for one taken along the way. */
        default_include: boolean;
        /** Only when the FILE names the column. Null means the teacher chooses. */
        suggested_column: string | null;
        unavailable_reason: string | null;
    };
    moment_label: string;
    effective_at: string;
};
