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
 * Um intervalo de datas «Y-m-d», e nada mais: um mês da vista de Ano, o mês que
 * a vista de Mês está a mostrar, ou o próprio período. É o menor denominador
 * comum das perguntas estruturais aqui em baixo, e é de propósito que não é o
 * tipo de nenhuma das duas vistas — a pergunta «este período cobre isto de uma
 * ponta à outra?» é a mesma pergunta seja o «isto» o que for.
 */
export type CalendarDateRange = {
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

// ------------------------------------------ as datas, escritas uma só vez

/**
 * As datas do calendário são «Y-m-d» canónicas, sempre em UTC, e são lidas
 * assim em toda a parte: assim comparam-se como texto (a ordem lexicográfica É
 * a ordem cronológica) e escrevem-se sem o fuso do navegador lhes mexer no dia.
 */
export function asDate(date: string): Date {
    return new Date(`${date}T00:00:00Z`);
}

const dayFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
});

/** «11/09» — o mesmo dia escrito da mesma maneira nas duas vistas. */
export function formatDay(date: string): string {
    return dayFormatter.format(asDate(date));
}

/** «11/09 – 29/01»: o período de uma ponta à outra, tal como ele é. */
export function periodRange(period: CalendarPeriod): string {
    return `${formatDay(period.starts_on)} – ${formatDay(period.ends_on)}`;
}

// -------------------------------- a estrutura do ano, respondida uma só vez

/**
 * O TOM ÚNICO DA ESTRUTURA — um bege quente, discreto e de baixa saturação, e
 * um só: o arco-íris por índice de período pintava a página inteira de azul só
 * porque se estava a meio de um semestre, e a estrutura do ano letivo tem de
 * estar VISÍVEL sem MANDAR na página.
 *
 * `stone` de propósito, e nunca `amber`: o âmbar já é da «Visita de estudo»
 * (EVENT_TREATMENTS, aqui em baixo) e do próprio `--brand-amber`, e repeti-lo
 * aqui faria a estrutura do ano colidir com uma das quatro espécies de
 * acontecimento. Fica um cinzento-quente que não compete com nada.
 *
 * E CONTINUA A NÃO SER A COR QUE DIZ QUAL É O PERÍODO: onde quer que este tom
 * apareça, o nome do período — e, num mês de transição, o «desde»/«até» — está
 * escrito ao lado. A página lê-se inteira num ecrã monocromático.
 */
export const PERIOD_TINT = 'bg-stone-100/70 dark:bg-stone-800/50';

/**
 * O período que cobre este intervalo DE UMA PONTA À OUTRA, e só esse — ou
 * nenhum.
 *
 * É a pergunta de que depende o tom de um mês, e é deliberadamente estreita: um
 * mês tocado por dois períodos, ou por um só que começa ou acaba a meio dele,
 * NÃO é de nenhum deles. Pintar setembro inteiro com o tom do 1.º Semestre
 * quando o semestre só abre no dia 11 é dizer uma coisa falsa sobre os dez
 * primeiros dias, e a mesma tinta apaga o intervalo entre dois períodos.
 *
 * Comparação de strings «Y-m-d», que é o mesmo idioma que o servidor já usa
 * para esta mesma pergunta.
 *
 * `touching` são os períodos que TOCAM o intervalo, já filtrados por quem
 * chama: a vista de Ano sabe-os pelos `period_ulids` que o servidor lhe manda,
 * a vista de Mês compara as datas do mês que está a ver. A resposta é a mesma.
 */
export function fullyContainedPeriod<TPeriod extends CalendarPeriod>(
    range: CalendarDateRange,
    touching: TPeriod[],
): TPeriod | null {
    if (touching.length !== 1) {
        return null;
    }

    const only = touching[0] as TPeriod;

    return only.starts_on <= range.starts_on && only.ends_on >= range.ends_on
        ? only
        : null;
}

/**
 * ONDE É QUE O PERÍODO REALMENTE COMEÇA, OU ACABA, DENTRO DESTE INTERVALO —
 * «desde 11/09», «até 29/01», «de 5/10 a 23/10» — e nada quando ele atravessa
 * o intervalo inteiro sem abrir nem fechar lá dentro, porque aí não há fronteira
 * nenhuma para dizer.
 */
export function periodBoundaryNote(
    range: CalendarDateRange,
    period: CalendarPeriod,
): string {
    const startsHere =
        period.starts_on >= range.starts_on &&
        period.starts_on <= range.ends_on;
    const endsHere =
        period.ends_on >= range.starts_on && period.ends_on <= range.ends_on;

    if (startsHere && endsHere) {
        return `de ${formatDay(period.starts_on)} a ${formatDay(period.ends_on)}`;
    }

    if (startsHere) {
        return `desde ${formatDay(period.starts_on)}`;
    }

    if (endsHere) {
        return `até ${formatDay(period.ends_on)}`;
    }

    return '';
}

/** O nome do período mais a fronteira que ele tem dentro deste intervalo. */
function periodNote(range: CalendarDateRange, period: CalendarPeriod): string {
    const boundary = periodBoundaryNote(range, period);

    return boundary === '' ? period.label : `${period.label} ${boundary}`;
}

/**
 * O que um intervalo de transição diz em vez do nome seco do período. Dois
 * períodos a tocarem o mesmo mês aparecem OS DOIS, e não só o primeiro.
 */
export function periodContext(
    range: CalendarDateRange,
    touching: CalendarPeriod[],
): string {
    return touching.map((period) => periodNote(range, period)).join(' · ');
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
 * ser estrutura — sem moldura, sem ícone, sem peso — e passou a ser desenhada no
 * tom único e discreto de PERÍODO_TINT, justamente para não competir com nenhum
 * dos quatro nem com uma avaliação.
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
