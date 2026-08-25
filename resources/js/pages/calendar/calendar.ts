/**
 * The shapes the «Calendário do Ano Letivo» server sends, and the visual
 * vocabulary its two views share.
 *
 * Both views read the same four things and nothing else: the year's own
 * períodos (structural context), the avaliações applied on a date (Instrument),
 * the teacher's own acontecimentos (Fase 5.3), e as exceções letivas do ano —
 * os dias em que NÃO há aula (Fase 5.4). There is deliberately no lesson type
 * here — aulas belong to «Horário do Professor», not to this calendar.
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

/**
 * As três espécies de exceção letiva, e só estas três (Fase 5.4). Um conjunto
 * fechado à volta de UMA pergunta — «isto impede a aula de acontecer?» — que é
 * exatamente a pergunta a que nenhum dos quatro `CalendarEventType` responde
 * que sim.
 */
export type CalendarExceptionType =
    | 'holiday'
    | 'school_break'
    | 'non_teaching_day';

/**
 * Um feriado, uma interrupção letiva ou um dia não letivo.
 *
 * NÃO É UM `CalendarEvent`, e a diferença não é de arrumação: um acontecimento
 * é pessoal e nunca apaga uma aula; isto é do ano letivo inteiro e é a única
 * coisa deste calendário que diz «neste dia não há aula». São por isso dois
 * tipos, duas leituras e dois tratamentos visuais, e nunca uma lista só com
 * espécies misturadas lá dentro.
 */
export type CalendarException = {
    ulid: string;
    type: CalendarExceptionType;
    type_label: string;
    type_short_label: string;
    title: string;
    starts_on: string;
    /** Never null: uma exceção de um dia só tem `ends_on === starts_on`. */
    ends_on: string;
    note: string | null;
};

export type CalendarDay = {
    date: string;
    day: number;
    in_month: boolean;
    is_today: boolean;
    period: CalendarPeriod | null;
    /**
     * A exceção que COBRE este dia, ou nenhuma — uma, e nunca uma lista, tal
     * como o `period` acima e ao contrário dos `events` abaixo. Uma interrupção
     * de onze dias é UMA coisa com onze dias, e a célula do meio dela não tem
     * nada de novo para dizer que a do dia anterior já não tenha dito.
     */
    exception: CalendarException | null;
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
 * OS TONS DA ESTRUTURA — um por período, e três ao todo.
 *
 * UM TOM POR PERÍODO, E NÃO UM SÓ PARA TODOS. Com um tom único, a forma do ano
 * lia-se como uma mancha contínua: setembro e março tinham exatamente a mesma
 * cor, e a fronteira entre os dois semestres — que é a divisão estrutural do
 * ano letivo — não estava desenhada em lado nenhum. Um tom por período dá-a a
 * ver de relance, e as duas metades do ano deixam de ser a mesma coisa.
 *
 * E BAIXO, MUITO BAIXO. O arco-íris que aqui esteve, de peso 100, pintava a
 * página inteira de azul só porque se estava a meio de um semestre. Estes são
 * de peso 50 — o degrau mais pálido que o Tailwind tem — e o do dia ainda leva
 * 70% de opacidade por cima disso: um sopro de cor, e nunca um banho.
 *
 * PELA `sequence` DO PERÍODO, e não pela ordem em que ele calhou vir na lista:
 * é o número que o próprio período traz, o mesmo por que o servidor já os
 * ordena. O 1.º período de um ano tem o mesmo tom em setembro, na vista de Ano
 * e na faixa do mês, hoje e no próximo ecrã. São três e não dois para um ano de
 * trimestres não ficar sem cor no terceiro; a partir do quarto, repetem-se.
 *
 * E NENHUM DELES É O ÂMBAR DA «VISITA DE ESTUDO», embora o primeiro venha da
 * mesma família de matiz: aquele é uma MOLDURA e um TEXTO saturados, de peso
 * 600/700 (EVENT_TREATMENTS, aqui em baixo); este é um ENCHIMENTO pálido, de
 * peso 50, com moldura NEUTRA e texto por omissão. São dois pesos diferentes e
 * leem-se como duas coisas diferentes — e é por isso que nenhuma superfície que
 * os use leva moldura de âmbar.
 *
 * E CONTINUA A NÃO SER A COR QUE DIZ QUAL É O PERÍODO: onde quer que um destes
 * tons apareça, o nome do período — e, num mês de transição, o «desde»/«até» —
 * está escrito ao lado. A página lê-se inteira num ecrã monocromático, e quem
 * não distingue estes tons não perde informação nenhuma.
 */
const PERIOD_TINTS = [
    'bg-amber-50 dark:bg-amber-950/20',
    'bg-blue-50 dark:bg-blue-950/20',
    'bg-emerald-50 dark:bg-emerald-950/20',
];

/**
 * O MESMO TOM, MAIS FRACO, PARA A CÉLULA DE UM DIA. Uma célula do mês é uma
 * superfície de altura inteira, e a mesma tinta que numa tira baixa é discreta
 * repetida por trinta células passa a ser o fundo da página. O que a faixa diz
 * a meia-voz, a grelha diz num sussurro.
 */
const PERIOD_DAY_TINTS = [
    'bg-amber-50/70 dark:bg-amber-950/15',
    'bg-blue-50/70 dark:bg-blue-950/15',
    'bg-emerald-50/70 dark:bg-emerald-950/15',
];

/** O índice do tom deste período, sempre dentro da paleta e nunca negativo. */
function tintIndex(period: CalendarPeriod, palette: string[]): number {
    return (
        (((period.sequence - 1) % palette.length) + palette.length) %
        palette.length
    );
}

/**
 * O tom deste período nas superfícies estruturais: a faixa do mês, o cartão de
 * um mês inteiramente dentro dele, a sua linha no resumo do ano.
 */
export function periodTint(period: CalendarPeriod): string {
    return PERIOD_TINTS[tintIndex(period, PERIOD_TINTS)] as string;
}

/**
 * O tom deste período na célula de um dia da grelha — e SÓ nos dias que ele
 * cobre mesmo. É o `period` que o servidor põe em cada `CalendarDay`, que é
 * exato ao dia: setembro de um ano que abre a 11 tem dez células por pintar e
 * dezanove pintadas, e nunca um mês inteiro pintado por causa de metade dele.
 */
export function periodDayTint(period: CalendarPeriod): string {
    return PERIOD_DAY_TINTS[tintIndex(period, PERIOD_DAY_TINTS)] as string;
}

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

// ------------------------------- os dias em que NÃO há aula (Fase 5.4)

/**
 * «11/09 – 29/01», ou «5/10» quando é um dia só — porque uma exceção de um dia
 * é o caso mais comum que existe (um feriado), e escrever «5/10 – 5/10» era
 * dizer duas vezes a mesma data para não ter de fazer uma pergunta.
 */
export function exceptionRange(exception: CalendarException): string {
    return exception.ends_on === exception.starts_on
        ? formatDay(exception.starts_on)
        : `${formatDay(exception.starts_on)} – ${formatDay(exception.ends_on)}`;
}

/**
 * As exceções que TOCAM este intervalo — o mês que se está a ver, um cartão de
 * mês da vista de Ano. Sobreposição e não contenção, exatamente como os
 * períodos: uma interrupção de 21 de dezembro a 3 de janeiro é estrutura dos
 * dois meses, e os dois têm de a mostrar.
 */
export function exceptionsTouching(
    range: CalendarDateRange,
    exceptions: CalendarException[],
): CalendarException[] {
    return exceptions.filter(
        (exception) =>
            exception.starts_on <= range.ends_on &&
            exception.ends_on >= range.starts_on,
    );
}

/**
 * ESTA CÉLULA É A QUE NOMEIA A EXCEÇÃO? — verdadeiro no primeiro dia da grelha
 * que ela cobre, e falso em todos os que vêm a seguir.
 *
 * É ISTO QUE IMPEDE UMA INTERRUPÇÃO DE ONZE DIAS DE SE LER COMO ONZE COISAS.
 * Um acontecimento de vários dias aparece, hoje, em cada célula que atravessa,
 * e está certo que assim seja: uma visita de estudo de segunda a quinta é uma
 * coisa que está mesmo a acontecer em cada um daqueles quatro dias, e cada um
 * deles tem a sua própria hora. Uma interrupção de Natal não é isso — é UM
 * intervalo com um nome — e onze cartões iguais empilhados de 21 a 31 de
 * dezembro seriam onze vezes a mesma frase. Diz-se onde ela começa, e os
 * restantes dias mostram-se pelo tom, tal como já acontece a um período.
 *
 * Compara-se com a célula ANTERIOR da grelha inteira (e não a do início da
 * semana): uma exceção que já vinha do mês passado não se volta a nomear, e uma
 * que atravessa duas linhas nomeia-se uma vez só, na primeira.
 */
export function namesException(days: CalendarDay[], index: number): boolean {
    const current = days[index]?.exception ?? null;

    if (current === null) {
        return false;
    }

    const previous = days[index - 1]?.exception ?? null;

    return previous === null || previous.ulid !== current.ulid;
}

/**
 * O TOM DE UM DIA NÃO LETIVO — e porque é que ele não é nenhum dos tons de
 * período aqui em cima.
 *
 * UM CINZENTO NEUTRO, E DE PROPÓSITO. Os períodos ficaram com três tons pálidos
 * de matiz (âmbar, azul, verde), de peso 50, que dizem «estamos a meio do 1.º
 * semestre». Isto diz outra coisa inteiramente — «neste dia não há aula» — e
 * uma quarta cor da mesma família seria lida como um quarto período. Um neutro
 * mais escuro, fora da paleta dos períodos, é a convenção que todos os
 * calendários do mundo já usam para um dia que não se trabalha, e lê-se como tal
 * mesmo num ecrã a preto e branco: é uma diferença de LUMINOSIDADE, e não de
 * matiz.
 *
 * E UMA MOLDURA TRACEJADA POR DENTRO, que é o que nenhuma célula de período tem
 * e nenhuma célula de período jamais terá. É a segunda pista, e é de FORMA e não
 * de cor: para quem não distingue o cinzento do âmbar pálido, o tracejado
 * continua lá.
 *
 * E GANHA AO TOM DO PERÍODO, quando o dia é dos dois — 21 de dezembro está
 * dentro do 1.º Semestre E dentro da Interrupção de Natal. A regra é a do
 * enunciado e é a certa: para AQUELE dia, o facto operacionalmente relevante é
 * que não há aula; que ele pertença ao 1.º semestre continua escrito na faixa
 * por cima da grelha, que é onde o período já se dizia de qualquer maneira.
 *
 * O QUE NÃO GANHA A NINGUÉM É AO CONTEÚDO DA CÉLULA: uma avaliação e um
 * acontecimento trazem moldura e fundo próprios (`bg-background/…`) que assentam
 * POR CIMA disto, e continuam a ser a coisa mais forte da célula — que é como
 * tem de ser, porque um teste marcado num dia que passou a não letivo é
 * exatamente a coisa que o professor tem de ver.
 */
export const EXCEPTION_DAY_TINT =
    'bg-slate-200/70 outline-dashed outline-1 -outline-offset-1 outline-slate-400/70 dark:bg-slate-700/40 dark:outline-slate-500/60';

/**
 * A superfície de uma exceção fora da grelha: a faixa por cima do mês, a
 * etiqueta no cartão de um mês da vista de Ano.
 *
 * TRACEJADA E CINZENTA, onde a faixa de um período é SÓLIDA e de matiz — as duas
 * são estrutura e vivem no mesmo sítio da página, pelo que a diferença entre
 * elas tem de ser visível de relance e sem ler. E, ao contrário da faixa de um
 * período, esta traz sempre ícone (`CalendarOff`) e uma palavra escrita
 * («FERIADO», «INTERRUPÇÃO», «NÃO LETIVO»), que é o que a mantém distinta de um
 * período E de um acontecimento sem depender de cor nenhuma.
 */
export const EXCEPTION_SURFACE =
    'border-dashed border-slate-400/80 bg-slate-100 text-slate-900 dark:border-slate-500/70 dark:bg-slate-800/60 dark:text-slate-100';

/** O tom do ícone e da etiqueta de uma exceção dentro de uma célula. */
export const EXCEPTION_ACCENT = 'text-slate-700 dark:text-slate-300';

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
 * ser estrutura — sem moldura, sem ícone, sem peso — e é desenhada nos tons
 * pálidos de `periodTint`/`periodDayTint`, justamente para não competir com
 * nenhum dos quatro nem com uma avaliação. Uma avaliação e um acontecimento
 * trazem moldura e fundo próprios (`bg-background/…`), que assentam POR CIMA do
 * fundo da célula e continuam a destacar-se dele seja qual for o período.
 *
 * NEM UMA EXCEÇÃO LETIVA ENTRA NESTA ESCADA, e pela mesma razão: também ela é
 * estrutura. Não é uma entrada da célula — é uma propriedade DO DIA, como o
 * período, e por isso pinta o fundo em vez de ocupar uma linha da lista. É o que
 * garante que uma interrupção de onze dias nunca compete com o teste marcado
 * para o dia 22: o teste continua a ser a coisa com moldura, fundo e peso, e
 * está desenhado por cima.
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
