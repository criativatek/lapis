import { fullDateLabel } from '@/lib/lessonDates';
import type { LessonStatus, WeekLesson } from '@/lib/lessons';
import type { TeacherClass } from '@/lib/lessonWeekView';

/**
 * A PESQUISA NOS SUMÁRIOS (0.162.0) — o estado dela, no URL.
 *
 * Por CIMA dos parâmetros do calendário, e nunca no lugar deles: `week`,
 * `view`, `classes`, `states`… ficam como estavam enquanto se pesquisa, e por
 * isso «Voltar ao calendário» é só retirar os parâmetros `q*` — regressa a
 * mesma semana, a mesma vista e os mesmos filtros, sem nada guardado à parte.
 * As turmas da pesquisa são um filtro PRÓPRIO (`q_classes`): mudá-las a meio
 * de uma pesquisa não muda o calendário.
 *
 * No URL, e não só em memória, pela mesma razão que o resto do estado de
 * leitura da página: abrir uma aula e voltar repõe a expressão, os filtros e a
 * página de resultados.
 *
 * O SERVIDOR DECIDE O QUE CORRESPONDE (`SummarySearchText`). Daqui só sai o
 * pedido; a normalização, a contagem e o destaque vêm prontos, e o destaque
 * chega em segmentos de texto — nunca em HTML.
 */

/** Lecionadas, preparadas ou ambas — os estados que a pesquisa oferece à vista. */
export type SummarySearchStates = 'taught' | 'prepared' | 'both';

export type SummarySearchState = {
    term: string;
    /** Turmas (ulids). Vazio = todas as turmas do professor no ano letivo. */
    classes: string[];
    states: SummarySearchStates;
    /** Incluir as aulas fechadas sem lecionação (professor ausente, outras atividades). */
    other: boolean;
    /** Dias locais («2026-10-01»), inclusivos. */
    from: string | null;
    to: string | null;
    page: number;
};

export const SUMMARY_SEARCH_PARAMS = [
    'q',
    'q_classes',
    'q_states',
    'q_other',
    'q_from',
    'q_to',
    'q_page',
] as const;

export const SUMMARY_SEARCH_STATE_LABELS: Record<SummarySearchStates, string> = {
    taught: 'Lecionadas',
    prepared: 'Preparadas',
    both: 'Ambas',
};

/** O mínimo que o servidor aceita, depois de normalizar. */
export const SUMMARY_SEARCH_MIN_LENGTH = 2;

const DATE = /^\d{4}-\d{2}-\d{2}$/;

function queryOf(url: string): URLSearchParams {
    return new URLSearchParams(
        url.includes('?') ? url.slice(url.indexOf('?') + 1).split('#')[0] : '',
    );
}

function list(value: string | null): string[] {
    return value === null
        ? []
        : value
              .split(',')
              .map((item) => item.trim())
              .filter((item) => item !== '');
}

function date(value: string | null): string | null {
    return value !== null && DATE.test(value) ? value : null;
}

/**
 * O estado da pesquisa a partir de um URL — `null` quando não se está a
 * pesquisar (sem `q`, ou só espaços).
 */
export function parseSummarySearchState(url: string): SummarySearchState | null {
    const query = queryOf(url);
    const term = query.get('q') ?? '';

    if (term.trim() === '') {
        return null;
    }

    const states = query.get('q_states');
    const page = Number.parseInt(query.get('q_page') ?? '1', 10);

    return {
        term,
        classes: list(query.get('q_classes')),
        states: states === 'prepared' || states === 'both' ? states : 'taught',
        other: query.get('q_other') === '1',
        from: date(query.get('q_from')),
        to: date(query.get('q_to')),
        // O servidor aceita até à página 10000; um URL com mais não é uma
        // pesquisa válida e não pode dar um 422 sem explicação.
        page: Number.isFinite(page) && page > 1 ? Math.min(page, 10000) : 1,
    };
}

/**
 * O mesmo URL, com os parâmetros da pesquisa trocados pelos deste estado — ou
 * retirados, com `null`. Todos os outros parâmetros ficam como estão.
 */
export function summarySearchUrl(
    url: string,
    state: SummarySearchState | null,
): string {
    const parsed = new URL(url, 'http://lapis.local');

    for (const parameter of SUMMARY_SEARCH_PARAMS) {
        parsed.searchParams.delete(parameter);
    }

    if (state !== null && state.term.trim() !== '') {
        parsed.searchParams.set('q', state.term);

        if (state.classes.length > 0) {
            parsed.searchParams.set('q_classes', state.classes.join(','));
        }

        parsed.searchParams.set('q_states', state.states);

        if (state.other) {
            parsed.searchParams.set('q_other', '1');
        }

        if (state.from) {
            parsed.searchParams.set('q_from', state.from);
        }

        if (state.to) {
            parsed.searchParams.set('q_to', state.to);
        }

        if (state.page > 1) {
            parsed.searchParams.set('q_page', String(state.page));
        }
    }

    return `${parsed.pathname}${parsed.search}${parsed.hash}`;
}

/** Os estados no pedido ao servidor (`taught,prepared,other`). */
export function summarySearchServerStates(state: SummarySearchState): string[] {
    const states =
        state.states === 'both' ? ['taught', 'prepared'] : [state.states];

    return state.other ? [...states, 'other'] : states;
}

/** A query string do pedido `GET /lessons/search` — também a chave da cache. */
export function summarySearchRequestQuery(state: SummarySearchState): string {
    const query = new URLSearchParams({ q: state.term.trim() });

    if (state.classes.length > 0) {
        query.set('classes', state.classes.join(','));
    }

    query.set('states', summarySearchServerStates(state).join(','));

    if (state.from) {
        query.set('from', state.from);
    }

    if (state.to) {
        query.set('to', state.to);
    }

    query.set('page', String(state.page));

    return query.toString();
}

/**
 * Se vale a pena pedir — uma aproximação do que o servidor aceita (aspas à
 * volta e espaços não contam). O servidor continua a ser quem decide: um 422
 * aparece como mensagem, não como falha.
 */
export function isSearchableTerm(term: string): boolean {
    const trimmed = term
        .trim()
        .replace(/^(["“«„])(.*)(["”»“])$/u, '$2')
        .replace(/[\s ]+/gu, ' ')
        .trim();

    return [...trimmed].length >= SUMMARY_SEARCH_MIN_LENGTH;
}

// ------------------------------------------------------------------ resposta

export type SummarySearchSegment = { text: string; match: boolean };

export type SummarySearchResult = {
    ulid: string;
    starts_at: string;
    ends_at: string | null;
    school_class: { ulid: string; label: string; is_support_class: boolean };
    context_label: string;
    class_group_id: number | null;
    class_group_label: string | null;
    subject: string;
    status: LessonStatus;
    status_label: string;
    outcome: WeekLesson['outcome'];
    outcome_label: string | null;
    lesson_number: number | null;
    lesson_number_end: number | null;
    identity_tone: string | null;
    occurrences: number;
    /** O sumário COMPLETO, em pedaços; a concatenação é o texto gravado. */
    segments: SummarySearchSegment[];
};

export type SummarySearchResponse = {
    term: string;
    /** O ano letivo que o servidor pesquisou (o da sessão); null sem ano letivo. */
    academic_year: string | null;
    /** As turmas aplicadas — as pedidas que são do professor nesse ano. */
    classes: string[];
    total: number;
    occurrences: number;
    page: number;
    last_page: number;
    per_page: number;
    results: SummarySearchResult[];
};

// -------------------------------------------------------------------- âmbito

function shortDate(value: string): string {
    const [year, month, day] = value.split('-');

    return `${day}/${month}/${year}`;
}

function statesLabel(state: SummarySearchState): string {
    const base = {
        taught: 'Aulas lecionadas',
        prepared: 'Aulas preparadas',
        both: 'Aulas lecionadas e preparadas',
    }[state.states];

    return state.other ? `${base} e outras ocorrências` : base;
}

/** O nome com que uma turma aparece na pesquisa — com a disciplina quando é preciso distinguir. */
export function searchClassLabel(
    schoolClass: Pick<TeacherClass, 'label' | 'subject'>,
    showSubject: boolean,
): string {
    return showSubject
        ? `${schoolClass.label} · ${schoolClass.subject}`
        : schoolClass.label;
}

/**
 * Se a disciplina tem de aparecer ao lado da turma: quando o professor tem mais
 * do que uma disciplina no ano, ou duas turmas com o mesmo nome.
 */
export function needsSubject(classes: readonly Pick<TeacherClass, 'label' | 'subject'>[]): boolean {
    const subjects = new Set(classes.map((schoolClass) => schoolClass.subject));
    const labels = new Set(classes.map((schoolClass) => schoolClass.label));

    return subjects.size > 1 || labels.size < classes.length;
}

/**
 * «Ano letivo 2026/2027 · Todas as turmas · Aulas lecionadas» — onde se está a
 * procurar, dito por inteiro, para ninguém julgar que é só a semana aberta.
 */
export function summarySearchScope(
    academicYear: string,
    classes: readonly TeacherClass[],
    state: SummarySearchState,
): string[] {
    const showSubject = needsSubject(classes);
    const chosen = state.classes
        .map((ulid) => classes.find((schoolClass) => schoolClass.ulid === ulid))
        .filter((schoolClass): schoolClass is TeacherClass => schoolClass !== undefined)
        .map((schoolClass) => searchClassLabel(schoolClass, showSubject));

    const parts = [
        `Ano letivo ${academicYear}`,
        chosen.length === 0
            ? 'Todas as turmas'
            : chosen.length <= 3
              ? chosen.join(', ')
              : `${chosen.length} turmas`,
        statesLabel(state),
    ];

    if (state.from && state.to) {
        parts.push(`De ${shortDate(state.from)} a ${shortDate(state.to)}`);
    } else if (state.from) {
        parts.push(`Desde ${shortDate(state.from)}`);
    } else if (state.to) {
        parts.push(`Até ${shortDate(state.to)}`);
    }

    return parts;
}

/** «Quinta-feira, 8 de outubro de 2026». */
export function resultDateLabel(result: Pick<SummarySearchResult, 'starts_at'>): string {
    return fullDateLabel(result.starts_at.slice(0, 10));
}

// --------------------------------------------- lembrar a escolha de estados

const STATES_KEY = 'lapis.lessons.search.states';

/** A última escolha de estados neste browser; na primeira utilização, só as lecionadas. */
export function storedSummarySearchStates(): SummarySearchStates {
    try {
        const stored = window.localStorage.getItem(STATES_KEY);

        return stored === 'prepared' || stored === 'both' ? stored : 'taught';
    } catch {
        return 'taught';
    }
}

export function rememberSummarySearchStates(states: SummarySearchStates): void {
    try {
        window.localStorage.setItem(STATES_KEY, states);
    } catch {
        // Sem persistência, a próxima pesquisa volta a começar nas lecionadas.
    }
}
