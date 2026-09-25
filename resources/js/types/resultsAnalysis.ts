/**
 * Anexo A — Contrato do payload (Inertia `instruments/Results` e
 * `instruments/ResultsReport`).
 *
 * Ver docs/superpowers/specs/2026-09-25-assessment-results-analysis-design.md
 * §Anexo A. Estes tipos são o contrato vinculativo com o backend: valores
 * numéricos viajam como strings decimais com ponto, já arredondados a 1 casa
 * para apresentação; `exact` leva o valor do motor (6 casas). `null` = sem
 * valor, nunca zero.
 */

export type Band = {
    key: string;
    code: string;
    label: string;
    sequence: number;
    is_negative: boolean;
};

export type Cell = {
    value: string | null;
    exact: string | null;
    band: Band | null;
    below_threshold: boolean | null;
    is_partial: boolean;
};

export type Count = {
    count: number;
    percent: string | null;
};

export type Analysis = {
    universe: number;
    classified: number;
    partial: number;
    out_of_scope: number;
    missing: {
        total: number;
        pending: number;
        under_review: number;
        absent: number;
        absent_justified: number;
        exempt: number;
        not_applicable: number;
        annulled: number;
    };
    mean: string | null;
    median: string | null;
    min: string | null;
    max: string | null;
    threshold: { value: string; below: Count; at_or_above: Count };
    quantitative: {
        total: number;
        classes: Array<{ key: string; label: string; count: number; percent: string | null; below_threshold: boolean }>;
    };
    qualitative: {
        available: boolean;
        total: number;
        unplaced: number;
        categories: Array<Band & Count>;
    };
};

export type ResultsContext = {
    kind: 'instrument';
    is_diagnostic: boolean;
    classificatory: boolean;
    counts_toward_classification: boolean;
    diagnostic_counts_warning: boolean;
    instrument: {
        ulid: string;
        title: string;
        applied_on: string;
        status: string;
        status_label: string;
        type: string | null;
        purpose: string;
        purpose_label: string;
    };
    class: { ulid: string; label: string };
    period: { label: string };
    absence_mode: string;
    absence_mode_label: string;
    threshold: { value: string; label: string };
    scale: { name: string | null; has_bands: boolean; bands: Band[] };
    domains: Array<{ key: string; id: number; name: string; weight_percent: string | null }>;
    items_without_domain: number;
    notes: string[];
};

export type ResultsStudent = {
    enrollment_id: number;
    class_number: number | null;
    name: string;
    status: string;
    status_label: string;
    global: Cell;
    domains: Record<string, Cell | null>;
};

export type Dimension = {
    key: 'global' | string;
    label: string;
    analysis: Analysis;
};

export type ReportSection = {
    key: string;
    title: string;
    paragraphs: string[];
    table: { columns: string[]; rows: string[][] } | null;
};

export type ResultsReport = {
    title: string;
    generated_at: string;
    sections: ReportSection[];
};

export type ResultsNote = {
    body: string;
    lock_version: number;
    updated_at: string | null;
    updated_by: string | null;
};

export type ResultsLinks = {
    grid: string;
    results: string;
    report: string;
    report_with_individual: string;
    note: string;
};

export type ResultsAnalysisProps = {
    context: ResultsContext;
    students: ResultsStudent[];
    dimensions: Dimension[];
    report: ResultsReport;
    note: ResultsNote;
    can_edit: boolean;
    include_individual: boolean;
    links: ResultsLinks;
};
