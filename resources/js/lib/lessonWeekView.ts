import {
    isTaughtLesson,
    lessonDisplayState,
    lessonQuickCloseState,
} from '@/lib/lessons';
import type { WeekLesson } from '@/lib/lessons';

/**
 * O ESTADO DE LEITURA de Aulas e Sumários — vista, filtros, densidade e, na
 * vista por turma, a turma, o grupo e o intervalo — vive no URL (0.158.0).
 *
 * No URL, e não no browser nem no servidor, porque é o que deixa abrir uma aula
 * e voltar exatamente ao mesmo sítio, partilhar a ligação, e recarregar sem
 * perder nada. O servidor só lê o que precisa (`week`, e `view`/`class`/
 * `group`/`range` para a vista por turma); o resto ignora sem erro
 * (WeeklyLessonsRequest só valida o que conhece).
 */
export type WeekView = 'semana' | 'turma' | 'horario';
export type SummaryFilter = 'todos' | 'com' | 'sem';
export type Density = 'completo' | 'compacto';
export type ClassRange = '1' | '2' | '4' | 'ano';

/** Os estados que se filtram — os valores de `lessonDisplayState`. */
export const FILTERABLE_STATES = [
    'preparation',
    'prepared',
    'taught',
    'teacher_absent',
    'class_external_activity',
] as const;
export type FilterableState = (typeof FILTERABLE_STATES)[number];

export const STATE_FILTER_LABELS: Record<FilterableState, string> = {
    preparation: 'Por preparar',
    prepared: 'Preparada',
    taught: 'Lecionada',
    teacher_absent: 'Professor ausente',
    class_external_activity: 'Outras atividades',
};

export type WeekViewState = {
    view: WeekView;
    /** Turmas visíveis (ulids). Vazio = todas. */
    classes: string[];
    states: FilterableState[];
    /** Só as aulas terminadas ainda por confirmar. */
    attention: boolean;
    summary: SummaryFilter;
    density: Density;
    /** Vista por turma. */
    classUlid: string | null;
    group: string;
    range: ClassRange;
};

export const DEFAULT_VIEW_STATE: WeekViewState = {
    view: 'semana',
    classes: [],
    states: [],
    attention: false,
    summary: 'todos',
    density: 'completo',
    classUlid: null,
    group: 'todos',
    range: '1',
};

function list(value: string | null): string[] {
    return value === null
        ? []
        : value
              .split(',')
              .map((item) => item.trim())
              .filter((item) => item !== '');
}

/** Lê o estado a partir de um URL (`usePage().url`, que também existe no SSR). */
export function parseWeekViewState(url: string): WeekViewState {
    const query = new URLSearchParams(
        url.includes('?') ? url.slice(url.indexOf('?') + 1).split('#')[0] : '',
    );
    const view = query.get('view');
    const summary = query.get('summary');
    const range = query.get('range');

    return {
        view: view === 'turma' || view === 'horario' ? view : 'semana',
        classes: list(query.get('classes')),
        states: list(query.get('states')).filter(
            (state): state is FilterableState =>
                (FILTERABLE_STATES as readonly string[]).includes(state),
        ),
        attention: query.get('attention') === '1',
        summary: summary === 'com' || summary === 'sem' ? summary : 'todos',
        density: query.get('density') === 'compacto' ? 'compacto' : 'completo',
        classUlid: query.get('class') || null,
        group: query.get('group') || 'todos',
        range: range === '2' || range === '4' || range === 'ano' ? range : '1',
    };
}

/**
 * Os parâmetros de URL deste estado, sem os valores por omissão — o URL de
 * quem nunca mexeu em nada continua a ser só `/lessons?week=…`.
 */
export function weekViewQuery(
    state: WeekViewState,
    week: string,
): Record<string, string> {
    const query: Record<string, string> = { week };

    if (state.view !== 'semana') {
        query.view = state.view;
    }

    if (state.classes.length > 0) {
        query.classes = state.classes.join(',');
    }

    if (state.states.length > 0) {
        query.states = state.states.join(',');
    }

    if (state.attention) {
        query.attention = '1';
    }

    if (state.summary !== 'todos') {
        query.summary = state.summary;
    }

    if (state.density !== 'completo') {
        query.density = state.density;
    }

    if (state.view === 'turma') {
        if (state.classUlid) {
            query.class = state.classUlid;
        }

        if (state.group !== 'todos') {
            query.group = state.group;
        }

        if (state.range !== '1') {
            query.range = state.range;
        }
    }

    return query;
}

export function weekViewUrl(state: WeekViewState, week: string): string {
    return `/lessons?${new URLSearchParams(weekViewQuery(state, week)).toString()}`;
}

/**
 * Os filtros SECUNDÁRIOS (estado, atenção, sumário) — os que vivem no painel
 * recolhível. As turmas estão sempre à vista e contam à parte.
 */
export function secondaryFilterCount(state: WeekViewState): number {
    return (
        state.states.length +
        (state.attention ? 1 : 0) +
        (state.summary !== 'todos' ? 1 : 0)
    );
}

export function activeFilterCount(
    state: WeekViewState,
    includeClasses = true,
): number {
    return (
        (includeClasses ? state.classes.length : 0) +
        secondaryFilterCount(state)
    );
}

/**
 * Os filtros comuns às três vistas (estado, atenção, sumário). Dentro de um
 * grupo de filtros é «ou»; entre grupos é «e».
 */
export function matchesSecondaryFilters(
    lesson: WeekLesson,
    state: WeekViewState,
    now: Date,
): boolean {
    if (
        state.states.length > 0 &&
        !state.states.includes(
            lessonDisplayState(lesson).value as FilterableState,
        )
    ) {
        return false;
    }

    if (state.attention && lessonQuickCloseState(lesson, now) !== 'ended') {
        return false;
    }

    if (state.summary === 'com' && !lesson.has_summary) {
        return false;
    }

    return !(state.summary === 'sem' && lesson.has_summary);
}

export function matchesWeekFilters(
    lesson: WeekLesson,
    state: WeekViewState,
    now: Date,
): boolean {
    if (
        state.classes.length > 0 &&
        !state.classes.includes(lesson.school_class.ulid)
    ) {
        return false;
    }

    return matchesSecondaryFilters(lesson, state, now);
}

/** Factos da semana para a linha de resumo — contados, nunca inferidos. */
export function weekFacts(
    lessons: readonly WeekLesson[],
    now: Date,
): {
    total: number;
    withSummary: number;
    taughtWithoutSummary: number;
    toConfirm: number;
} {
    return {
        total: lessons.length,
        withSummary: lessons.filter((lesson) => lesson.has_summary).length,
        taughtWithoutSummary: lessons.filter(
            (lesson) => isTaughtLesson(lesson) && !lesson.has_summary,
        ).length,
        toConfirm: lessons.filter(
            (lesson) => lessonQuickCloseState(lesson, now) === 'ended',
        ).length,
    };
}

export function toggled<T>(values: readonly T[], value: T): T[] {
    return values.includes(value)
        ? values.filter((item) => item !== value)
        : [...values, value];
}

/** Uma turma do professor no ano letivo selecionado (prop `classes`). */
export type TeacherClass = {
    ulid: string;
    label: string;
    subject: string;
    is_support_class: boolean;
    archived: boolean;
    identity_tone: string | null;
    groups: { id: number; label: string }[];
};

/**
 * A vista por turma (prop `classView`), construída no servidor só para leitura:
 * nunca cria nem materializa aulas fora da semana selecionada.
 */
export type ClassViewData = {
    class: Omit<TeacherClass, 'archived'>;
    group: string;
    range: { key: ClassRange; start: string; end: string; clamped: boolean };
    lessons: WeekLesson[];
    /** O último sumário antes do intervalo, por grupo — do MESMO grupo, e de mais nenhum. */
    previous: {
        group_id: number | null;
        group_label: string | null;
        lesson: WeekLesson | null;
    }[];
};
