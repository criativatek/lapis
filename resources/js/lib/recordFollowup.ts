/**
 * The logbook consultation and its follow-up (spec 2026-10-12) — the shapes the
 * server sends and the few helpers the screen needs. Nothing here counts: the
 * numbers always come from the server (RecordFollowupCounter).
 */

export type FollowupCategory = 'homework_not_done' | 'lateness' | 'missing_material';

export type CaseStatus = 'pending' | 'registered' | 'none';

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
    view: FollowupCategory | null;
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
    category: FollowupCategory;
    label: string;
    students: number;
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
    class_ulid: string;
    class_label: string;
    subject: string;
    student_ulid: string;
    number: number | null;
    name: string;
    occurrences: number;
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
    }[];
    can_confirm: boolean;
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

export function occurrencesLabel(count: number): string {
    return count === 1 ? '1 ocorrência' : `${count} ocorrências`;
}

export function studentsLabel(count: number): string {
    return count === 1 ? '1 aluno' : `${count} alunos`;
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
