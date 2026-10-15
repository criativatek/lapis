/**
 * The logbook consultation and its follow-up (spec 2026-10-12) — the shapes the
 * server sends and the few helpers the screen needs. Nothing here counts: the
 * numbers always come from the server (RecordFollowupCounter).
 */

export type FollowupCategory = 'homework_not_done' | 'lateness' | 'missing_material';

/**
 * The four direct accesses: the three logbook categories, and «Faltas de
 * presença» (`absences`), read from the lessons' attendance — consultation and
 * counts only, no alert and no message.
 */
export type FollowupView = FollowupCategory | 'absences';

export type CaseStatus = 'pending' | 'registered' | 'none';

/** «Registar TPC», «Panorama da turma», «Pesquisar registos» (0.165.0). */
export type RecordsTab = 'tpc' | 'panorama' | 'search';

export type ConsultationFilters = {
    year: string | null;
    subject: string | null;
    class: string | null;
    student: string | null;
    kind: string | null;
    mode: 'period' | 'range';
    period: string | null;
    from: string | null;
    to: string | null;
    searched: boolean;
    view: FollowupView | null;
    tab: RecordsTab;
};

/** «Registar TPC»: the class and the day of the grid (the grid loads itself). */
export type RegisterContext = {
    class: { ulid: string; label: string; subject: string; archived: boolean } | null;
    date: string;
    today: string;
    can_write: boolean;
};

export type PanoramaColumn = 'homework_not_done' | 'missing_material' | 'lateness' | 'absences';

export const PANORAMA_COLUMNS: { key: PanoramaColumn; label: string; short: string }[] = [
    { key: 'homework_not_done', label: 'TPC não realizados', short: 'TPC' },
    { key: 'missing_material', label: 'Faltas de material', short: 'Material' },
    { key: 'lateness', label: 'Atrasos', short: 'Atrasos' },
    { key: 'absences', label: 'Faltas de presença', short: 'Faltas' },
];

export type PanoramaRow = {
    ulid: string;
    number: number | null;
    name: string;
    enrolled_on: string;
    left_on: string | null;
    /** Entered or left during the window. */
    partial: boolean;
    /** null = nothing to count (see `missing`). */
    counts: Record<PanoramaColumn, number | null>;
    missing: Partial<Record<PanoramaColumn, 'no_data' | 'not_applicable'>>;
    homework: { done: number; partially_done: number; not_done: number; verified: number };
    absence_days: number;
    attendance_recorded: number;
    statuses: Partial<Record<FollowupCategory, CaseStatus | null>>;
    has_occurrences: boolean;
};

export type Panorama = {
    rows: PanoramaRow[];
    totals: Record<PanoramaColumn, number>;
    meta: {
        is_period: boolean;
        attendance_applicable: boolean;
        homework_days: number;
        lessons_counted: number;
        lessons_not_recorded: number;
    };
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_url: string | null;
    next_url: string | null;
};

export type Tile = {
    category: FollowupView;
    label: string;
    /** What is counted: logbook occurrences, or absences (one per lesson). */
    unit: 'occurrence' | 'absence';
    /** Distinct students, by the identity the application keeps — never by name. */
    students: number;
    /** Student × class: the lines of the list, and the unit alerts are kept by. */
    cases: number;
    occurrences: number;
    /** «Por tratar» in the period; null for a custom date range. */
    pending: number | null;
};

export type AlertRow = {
    case_ulid: string;
    class_ulid: string;
    class_label: string;
    subject: string;
    student_ulid: string;
    number: number | null;
    name: string;
    category: FollowupCategory;
    category_label: string;
    occurrences: number;
    period_ulid: string;
    period_label: string;
    alerted_at: string | null;
};

export type FollowupRow = {
    /** Same key on two lines = the same student, followed in two classes. */
    student_key: string | null;
    /** How many lines this student has in the whole list. */
    student_cases: number;
    class_ulid: string;
    class_label: string;
    subject: string;
    student_ulid: string;
    number: number | null;
    name: string;
    occurrences: number;
    /** Absences only: on how many distinct days. */
    days: number | null;
    first_date: string;
    last_date: string;
    status: CaseStatus | null;
    origin: 'alert' | 'existing' | null;
};

export type ResultRow = {
    ulid: string;
    class_ulid: string;
    class_label: string;
    subject: string;
    date: string;
    kind: string;
    kind_label: string;
    detail_label: string | null;
    severity_label: string | null;
    student: { number: number | null; name: string } | null;
    description: string;
};

export type CaseOccurrence = {
    ulid: string;
    date: string;
    detail_label: string | null;
    description: string;
};

export type CaseConfirmation = {
    ulid: string;
    confirmed_by: string | null;
    confirmed_at: string;
    count: number;
    dates: string[];
    /** Came in through a backup restore. */
    restored: boolean;
    /** Declared, but the record could not be recovered by the restore. */
    missing: string[];
    changes: { added: string[]; removed: string[] };
};

export type CaseDetail = {
    class: { ulid: string; label: string; subject: string };
    student: { ulid: string; number: number | null; name: string; short_name: string | null };
    category: FollowupCategory;
    category_label: string;
    window: { is_period: boolean; label: string; period_ulid: string | null };
    occurrences: CaseOccurrence[];
    count: number;
    status: CaseStatus | null;
    origin: 'alert' | 'existing' | null;
    alerted_at: string | null;
    alerted_count: number | null;
    message: { text: string; missing: ('number' | 'name')[] };
    confirmation: CaseConfirmation | null;
    history: {
        ulid: string;
        confirmed_by: string | null;
        confirmed_at: string;
        count: number;
        undone_by: string | null;
        undone_at: string | null;
        restored: boolean;
    }[];
    can_confirm: boolean;
    /**
     * TPC only: what was marked for the student in the window, by state, and
     * on how many days the class had homework marked without a marking of
     * theirs. Never «days homework was set» — the application does not know.
     */
    homework?: { done: number; partially_done: number; not_done: number; verified: number; class_days: number; unmarked_days: number } | null;
};

export type AbsenceDetail = {
    kind: 'absences';
    class: { ulid: string; label: string; subject: string };
    student: { ulid: string; number: number | null; name: string };
    category_label: string;
    window: { is_period: boolean; label: string; period_ulid: string | null };
    absences: { ulid: string; lesson_ulid: string; date: string; time: string; lesson_number: number | null; lesson_number_end: number | null; context_label: string }[];
    count: number;
    days: number;
    /** Taught lessons still without attendance in the window — never absences. */
    not_recorded: number;
};

/** The query keys that open a case — removed again when it closes. */
export const CASE_KEYS = ['case_class', 'case_student', 'case_category'] as const;

/** «03/10/2026» from «2026-10-03» — read as a calendar day, never shifted by time zone. */
export function formatDay(isoDay: string): string {
    const [year, month, day] = isoDay.slice(0, 10).split('-');

    return `${day}/${month}/${year}`;
}

const WEEKDAYS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];

export function weekday(isoDay: string): string {
    const [year, month, day] = isoDay.slice(0, 10).split('-').map(Number);

    return WEEKDAYS[new Date(Date.UTC(year, month - 1, day)).getUTCDay()];
}

/** «12/10/2026 às 14:05», in the browser's (Lisbon) clock. */
export function formatInstant(iso: string): string {
    const date = new Date(iso);

    return `${date.toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit', year: 'numeric' })} às ${date.toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' })}`;
}

export function studentLabel(number: number | null, name: string): string {
    return number === null ? name : `n.º ${number} · ${name}`;
}

/**
 * Who marked or undid a declaration. A restore keeps the author only when it
 * is the account restoring (the email matches); otherwise the declaration
 * comes in without one — never attributed to whoever imported it.
 */
export function declarationAuthor(name: string | null, restored: boolean): string {
    return name ?? (restored ? 'autor não associado no restauro' : 'um utilizador removido');
}

export function occurrencesLabel(count: number): string {
    return count === 1 ? '1 ocorrência' : `${count} ocorrências`;
}

export function studentsLabel(count: number): string {
    return count === 1 ? '1 aluno' : `${count} alunos`;
}

export function casesLabel(count: number): string {
    return count === 1 ? '1 caso' : `${count} casos`;
}

export function absencesLabel(count: number): string {
    return count === 1 ? '1 falta' : `${count} faltas`;
}

export function daysLabel(count: number): string {
    return count === 1 ? '1 dia' : `${count} dias`;
}

/**
 * «6 alunos · 14 ocorrências», or «1 aluno · 2 casos · 5 ocorrências» when a
 * student is followed in more than one class — the two numbers are never
 * presented as if they were the same thing.
 */
export function tileFigures(tile: Pick<Tile, 'students' | 'cases' | 'occurrences'> & { unit?: Tile['unit'] }): string {
    const parts = [studentsLabel(tile.students)];

    if (tile.cases !== tile.students) {
        parts.push(casesLabel(tile.cases));
    }

    parts.push(tile.unit === 'absence' ? absencesLabel(tile.occurrences) : occurrencesLabel(tile.occurrences));

    return parts.join(' · ');
}

export function statusLabel(status: CaseStatus | null): string | null {
    if (status === 'pending') {
        return 'Por tratar';
    }

    if (status === 'registered') {
        return 'Registado no Inovar';
    }

    return null;
}

/**
 * The query string of the CURRENT page, changed — `null`/'' removes a key.
 * Everything else (filters, page, open case) is kept, so a return lands where
 * the teacher left.
 */
export function withQuery(currentUrl: string, changes: Record<string, string | null | undefined>): string {
    const url = new URL(currentUrl, 'http://lapis.local');

    for (const [key, value] of Object.entries(changes)) {
        if (value === null || value === undefined || value === '') {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, value);
        }
    }

    const query = url.searchParams.toString();

    return `${url.pathname}${query === '' ? '' : `?${query}`}`;
}

/** The filters as the query string a search sends — empty values left out. */
/**
 * The URL of a tab. The search keeps every filter; registering and the
 * panorama keep only what they use — the year, the class and (panorama) the
 * period or date range — so a student or a kind chosen in the search never
 * hides anyone in the grid or the panorama.
 */
export function tabQuery(filters: ConsultationFilters, tab: RecordsTab, extra: Record<string, string> = {}): Record<string, string> {
    if (tab === 'search') {
        return { ...filtersToQuery(filters), tab, ...extra };
    }

    const query: Record<string, string> = { tab };

    if (filters.year) {
        query.year = filters.year;
    }

    if (filters.class) {
        query.class = filters.class;
    }

    if (tab === 'panorama') {
        const all = filtersToQuery(filters);

        for (const key of ['mode', 'period', 'from', 'to'] as const) {
            if (all[key]) {
                query[key] = all[key];
            }
        }
    }

    return { ...query, ...extra };
}

export function filtersToQuery(filters: ConsultationFilters): Record<string, string> {
    const query: Record<string, string> = {};
    const keys: (keyof ConsultationFilters)[] = ['year', 'subject', 'class', 'student', 'kind'];

    for (const key of keys) {
        const value = filters[key];

        if (typeof value === 'string' && value !== '') {
            query[key] = value;
        }
    }

    if (filters.mode === 'range') {
        query.mode = 'range';

        if (filters.from) {
            query.from = filters.from;
        }

        if (filters.to) {
            query.to = filters.to;
        }
    } else if (filters.period) {
        query.period = filters.period;
    }

    return query;
}

/**
 * Copies text to the clipboard. Returns false when the browser refuses (no
 * Clipboard API, an insecure origin, a denied permission) — the caller then
 * selects the text so the teacher can copy it by hand.
 */
export async function copyText(text: string): Promise<boolean> {
    try {
        if (typeof navigator === 'undefined' || !navigator.clipboard?.writeText) {
            return false;
        }

        await navigator.clipboard.writeText(text);

        return true;
    } catch {
        return false;
    }
}
