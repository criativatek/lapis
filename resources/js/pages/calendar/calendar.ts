/**
 * The shapes the «Calendário do Ano Letivo» server sends, and the visual
 * vocabulary its two views share.
 *
 * Both views read the same three things and nothing else: the year's own
 * períodos (structural context), the avaliações applied on a date (Instrument),
 * and the teacher's own acontecimentos (Fase 5.3). There is deliberately no
 * lesson type here — aulas belong to «Horário do Professor», not to this
 * calendar.
 */

export type CalendarAssessment = {
    ulid: string;
    title: string;
    applied_on: string;
    class_ulid: string;
    class_label: string;
    subject: string;
    type: string;
    status: string;
    status_label: string;
    href: string;
};

export type CalendarPeriod = {
    ulid: string;
    label: string;
    kind: string;
    kind_label: string;
    sequence: number;
    starts_on: string;
    ends_on: string;
};

/**
 * The four kinds of acontecimento, and only these four. A closed set: each one
 * is a dated thing with no other home in the application, and anything that
 * already has one does not belong here.
 */
export type CalendarEventType = 'meeting' | 'activity' | 'field_trip' | 'other';

export type CalendarEventSchoolClass = {
    ulid: string;
    label: string;
};

export type CalendarEvent = {
    ulid: string;
    type: CalendarEventType;
    type_label: string;
    type_short_label: string;
    title: string;
    starts_on: string;
    /** Never null: «igual à inicial se ausente» is written down on the server. */
    ends_on: string;
    /** Null on both means «dia inteiro» — an absence, not an unknown. */
    starts_at: string | null;
    ends_at: string | null;
    description: string | null;
    school_classes: CalendarEventSchoolClass[];
};

/** A turma the acting teacher may attach an acontecimento to. */
export type CalendarTeacherClass = {
    ulid: string;
    label: string;
    subject: string;
};

export type CalendarDay = {
    date: string;
    day: number;
    in_month: boolean;
    is_today: boolean;
    period: CalendarPeriod | null;
    assessments: CalendarAssessment[];
    /** Every acontecimento COVERING this day, not only those beginning on it. */
    events: CalendarEvent[];
};

/**
 * The tints the período BANDS are drawn in, in the order the períodos of the
 * year run. Deliberately quiet, and deliberately never the only thing that
 * separates a período from an avaliação: a band has no border, no icon and no
 * emphasis, while an avaliação has all three, so the two stay distinguishable
 * on a monochrome screen and for a reader who does not see the difference in
 * hue. Within the structure itself the tint is a convenience — each band is
 * also named, in the legend and where it begins.
 *
 * There is no setting behind this: one deliberate treatment, applied the same
 * way in both views.
 */
const TINTS = [
    'bg-sky-100/70 dark:bg-sky-950/40',
    'bg-amber-100/70 dark:bg-amber-950/40',
    'bg-emerald-100/70 dark:bg-emerald-950/40',
    'bg-violet-100/70 dark:bg-violet-950/40',
    'bg-rose-100/70 dark:bg-rose-950/40',
    'bg-teal-100/70 dark:bg-teal-950/40',
];

export function periodTint(periods: CalendarPeriod[], ulid: string): string {
    const index = periods.findIndex((period) => period.ulid === ulid);

    return index === -1 ? '' : (TINTS[index % TINTS.length] as string);
}

/**
 * A ESCADA DE PESO VISUAL DO CALENDÁRIO, de cima para baixo — and the order is
 * a product decision, not a palette:
 *
 *   1. UMA AVALIAÇÃO é o mais forte e o mais identificável da página, e assim
 *      fica: moldura sólida, fundo cheio, texto com peso, ícone próprio. Nada
 *      do que a Fase 5.3 acrescenta lhe pode fazer sombra, porque é a coisa da
 *      página que tem consequências para os alunos.
 *   2. UMA REUNIÃO tem peso intermédio: moldura sólida, mas mais leve, e texto
 *      com peso — é um compromisso a sério, mas não é uma avaliação.
 *   3. UMA ATIVIDADE e UMA VISITA DE ESTUDO são distintas uma da outra e
 *      distintas de tudo o resto, com moldura tracejada e texto normal: leem-se
 *      bem, mas não competem com o que está acima.
 *   4. «OUTRO» é o mais neutro de todos, e é assim que deve ser: é a gaveta
 *      para o que não é nenhuma das outras três, e não deve gritar.
 *
 * E, ACIMA DE TUDO: A FAIXA DE UM PERÍODO NÃO ENTRA NESTA ESCADA. Continua a
 * ser estrutura — sem moldura, sem ícone, sem peso — exatamente como a Fase 5.2
 * a deixou, e esta fase não lhe toca.
 *
 * NENHUM DESTES QUATRO SE DISTINGUE DOS OUTROS — NEM DE UMA AVALIAÇÃO — SÓ PELA
 * COR. Cada um traz sempre também um ícone próprio e uma etiqueta escrita
 * («REUNIÃO», «ATIVIDADE», «VISITA», «OUTRO»), pelo que a página continua
 * legível num ecrã monocromático, num ecrã de fraco contraste, e para quem não
 * distingue as cores. Não há aqui nenhuma opção de personalização, e não é um
 * esquecimento: uma cor escolhida por cada professor tornaria impossível
 * garantir justamente isto.
 */
const EVENT_TREATMENTS: Record<CalendarEventType, { entry: string; badge: string }> = {
    meeting: {
        entry: 'border-indigo-500/70 bg-background/70 font-medium dark:border-indigo-400/70',
        badge: 'text-indigo-700 dark:text-indigo-300',
    },
    activity: {
        entry: 'border-dashed border-emerald-600/70 bg-background/50 dark:border-emerald-400/70',
        badge: 'text-emerald-700 dark:text-emerald-300',
    },
    field_trip: {
        entry: 'border-dashed border-amber-600/70 bg-background/50 dark:border-amber-400/70',
        badge: 'text-amber-700 dark:text-amber-300',
    },
    other: {
        entry: 'border-dotted border-foreground/30 bg-background/40 text-muted-foreground',
        badge: 'text-muted-foreground',
    },
};

export function eventEntryClasses(type: CalendarEventType): string {
    return EVENT_TREATMENTS[type].entry;
}

export function eventBadgeClasses(type: CalendarEventType): string {
    return EVENT_TREATMENTS[type].badge;
}

/**
 * «9:30 – 11:00», «a partir das 9:30», ou «dia inteiro» quando não há hora
 * nenhuma — porque a ausência de hora é informação, e não uma hora que ninguém
 * chegou a escrever.
 */
export function eventTimeLabel(event: CalendarEvent): string {
    if (event.starts_at === null) {
        return 'Dia inteiro';
    }

    return event.ends_at === null
        ? `A partir das ${event.starts_at}`
        : `${event.starts_at} – ${event.ends_at}`;
}
