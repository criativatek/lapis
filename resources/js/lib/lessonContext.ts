/**
 * O CONTEXTO DE PREPARAÇÃO DE UMA AULA — «Aula anterior».
 *
 * Tipos e funções puras partilhados pela página da aula e pelo editor no
 * cartão. A regra de QUE aula entra (uma só, a anterior mais próxima) vive no
 * servidor (LessonPreparationContext); aqui só se diz como a entrada se lê.
 */

export type LessonContextState = 'taught' | 'prepared';

export type LessonContextEntry = {
    ulid: string;
    class_ulid: string;
    starts_at: string;
    ends_at: string | null;
    lesson_number: number | null;
    lesson_number_end: number | null;
    context_label: string;
    state: LessonContextState;
    state_label: string;
    content: string;
    plan: string | null;
    resources: string | null;
    homework: string | null;
};

export type LessonContextResponse = {
    lesson: LessonContextEntry | null;
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

/**
 * «Ver histórico em Aulas e Sumários»: a vista por turma, na semana da aula
 * anterior. O `class` é o ulid da turma (nunca um id sequencial).
 */
export function contextHistoryUrl(entry: Pick<LessonContextEntry, 'class_ulid' | 'starts_at'>): string {
    const query = new URLSearchParams({
        view: 'turma',
        class: entry.class_ulid,
        week: entry.starts_at.slice(0, 10),
    });

    return `/lessons?${query.toString()}`;
}
