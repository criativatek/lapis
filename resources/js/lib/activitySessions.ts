import {
    CircleCheck,
    CircleDashed,
    ClipboardCheck,
    CircleX,
} from '@lucide/vue';
import type { Component } from 'vue';

/**
 * AS SESSÕES DAS «OUTRAS ATIVIDADES» (0.170.0), como o servidor as envia
 * (`ActivitySessionPresenter`): uma sessão que já existe ou a ocorrência do
 * bloco que ainda ninguém registou (`session_ulid: null`).
 *
 * NÃO SÃO AULAS. Nada aqui passa por `WeekLesson`, pelos contadores das aulas,
 * pelo fecho em lote nem pela numeração das lições.
 */
export type ActivitySessionPresentation =
    'planned' | 'prepared' | 'done' | 'not_held';

export type ActivitySessionItem = {
    /** Chave estável na lista (a ulid da sessão, ou atividade + data). */
    key: string;
    session_ulid: string | null;
    /** `null` quando a sessão já não tem bloco no horário. */
    activity_ulid: string | null;
    title: string;
    kind: string;
    kind_label: string;
    /** `Y-m-d`. */
    occurs_on: string;
    /** ISO 8601 com o desvio de Lisboa — o que a ordenação compara. */
    starts_at: string;
    ends_at: string;
    /** `HH:MM`. */
    start_time: string;
    end_time: string;
    school_class: { ulid: string; label: string } | null;
    status: 'planned' | 'done' | 'not_held';
    presentation: ActivitySessionPresentation;
    presentation_label: string;
    summary: string | null;
    has_summary: boolean;
    session_number: number | null;
    numbering_enabled: boolean;
    /** Falso num dia não letivo: a sessão ainda não existe e não se pode criar. */
    registrable: boolean;
    /** Falso quando a sessão ficou fora do padrão atual do bloco. */
    follows_schedule: boolean;
};

export type ActivitySessionAction = 'save' | 'done' | 'not_held' | 'reopen';

const ICONS: Record<ActivitySessionPresentation, Component> = {
    planned: CircleDashed,
    prepared: ClipboardCheck,
    done: CircleCheck,
    not_held: CircleX,
};

export function activitySessionIcon(
    presentation: ActivitySessionPresentation,
): Component {
    return ICONS[presentation] ?? CircleDashed;
}

/** A chave de `statusTone` deste estado. */
export function activitySessionToneKey(
    presentation: ActivitySessionPresentation,
): string {
    return `session_${presentation}`;
}

/**
 * Ordena por início (instante real, não texto: o desvio de Lisboa muda na
 * última semana de outubro), depois por título e chave. O servidor não
 * desempata o que o ecrã junta com as aulas, por isso a ordem é explícita.
 */
export function compareActivitySessions(
    a: Pick<ActivitySessionItem, 'starts_at' | 'title' | 'key'>,
    b: Pick<ActivitySessionItem, 'starts_at' | 'title' | 'key'>,
): number {
    return (
        Date.parse(a.starts_at) - Date.parse(b.starts_at) ||
        a.title.localeCompare(b.title, 'pt-PT') ||
        a.key.localeCompare(b.key)
    );
}

/** «Sessão 3» — ou nada, quando a atividade não numera: só a data. */
export function activitySessionNumberLabel(
    session: Pick<ActivitySessionItem, 'session_number'>,
): string | null {
    return session.session_number === null
        ? null
        : `Sessão ${session.session_number}`;
}

const dateFormatter = new Intl.DateTimeFormat('pt-PT', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
});

/** «ter., 13 de out.» a partir de `Y-m-d`. */
export function activitySessionDateLabel(date: string): string {
    return dateFormatter.format(new Date(`${date}T12:00:00Z`));
}
