/**
 * O CONTEXTO DE PREPARAÇÃO DE UMA AULA — «Antes desta aula».
 *
 * Tipos e funções puras partilhados pela página da aula e pelo editor no
 * cartão. As regras de QUE aulas entram vivem no servidor
 * (LessonPreparationContext); aqui só se diz como cada entrada se lê.
 */

export type LessonContextState = 'taught' | 'prepared';

export type LessonContextEntry = {
    ulid: string;
    starts_at: string;
    ends_at: string | null;
    lesson_number: number | null;
    context_label: string;
    state: LessonContextState;
    state_label: string;
    content: string;
    resources: string | null;
    homework: string | null;
};

export type LessonContextResponse = {
    lessons: LessonContextEntry[];
    has_more: boolean;
};

/** O que `GET lessons/{lesson}/previous-summary` devolve (a base do «Basear…»). */
export type LessonBaseSource = {
    content: string;
    private_notes: string | null;
    resources: string | null;
    homework: string | null;
    starts_at?: string;
    state?: LessonContextState;
    state_label?: string;
};

export const CONTEXT_PAGE_SIZE = 5;
export const CONTEXT_MAX_LIMIT = 20;

/** Quantas aulas pedir da próxima vez que se carrega em «Mostrar mais». */
export function nextContextLimit(current: number): number {
    return Math.min(current + CONTEXT_PAGE_SIZE, CONTEXT_MAX_LIMIT);
}

/** Por ordem cronológica (hora, depois ulid), sem alterar a lista recebida. */
export function sortContextChronologically(
    entries: readonly LessonContextEntry[],
): LessonContextEntry[] {
    return [...entries].sort(
        (a, b) =>
            Date.parse(a.starts_at) - Date.parse(b.starts_at) ||
            a.ulid.localeCompare(b.ulid),
    );
}

/**
 * «07/10» — o dia local da aula. `starts_at` chega em ISO com o desvio de
 * Lisboa, por isso a data à cabeça já é a data local (a mesma leitura de
 * `lessonDate`).
 */
export function contextDayMonth(startsAt: string): string {
    const [, month = '', day = ''] = startsAt.slice(0, 10).split('-');

    return `${day}/${month}`;
}

/** «Preparada — por lecionar» / «Lecionada»: o rótulo do servidor, com recurso local. */
export function contextStateLabel(
    state: LessonContextState,
    label?: string | null,
): string {
    if (label) {
        return label;
    }

    return state === 'taught' ? 'Lecionada' : 'Preparada — por lecionar';
}

/** «Basear na aula de 07/10 (Preparada — por lecionar)». */
export function baseOnLabel(source: LessonBaseSource | null): string {
    if (source === null || !source.starts_at || !source.state) {
        return 'Basear no sumário anterior';
    }

    return `Basear na aula de ${contextDayMonth(source.starts_at)} (${contextStateLabel(source.state, source.state_label)})`;
}

/** A primeira linha não vazia de um texto, para a versão compacta. */
export function firstLine(text: string): string {
    return (
        text
            .replace(/\r\n/g, '\n')
            .split('\n')
            .map((line) => line.trim())
            .find((line) => line !== '') ?? ''
    );
}

/** Acima disto o texto fica recolhido atrás de «Ver mais». */
export const CONTEXT_LONG_TEXT = 280;

export function isLongContextText(text: string): boolean {
    return text.length > CONTEXT_LONG_TEXT || text.split('\n').length > 5;
}
