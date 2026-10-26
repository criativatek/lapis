/**
 * O Painel do Professor — as regras de apresentação que não são da base de
 * dados: a saudação pela hora de Lisboa, a frase do dia em prosa, a FILA
 * (Agora → A seguir) construída a partir das pendências que o servidor prova,
 * e a condição única em que a frase de fecho aparece.
 *
 * Tudo puro: recebe a prop `dashboard` (DashboardComposer) e devolve dados
 * para os blocos. Nada aqui inventa uma pendência — só ordena as que vêm.
 */

export type TurmaRef = { ulid: string; label: string; subject: string | null; tone: string | null };

export type DayItem = {
    kind: 'lesson' | 'activity';
    key: string;
    starts_at: string;
    ends_at: string | null;
    start_time: string;
    end_time: string | null;
    title: string;
    subtitle: string | null;
    class: TurmaRef | null;
    lesson: {
        ulid: string;
        number: string | null;
        status: string;
        outcome: string | null;
        outcome_label: string | null;
        has_summary: boolean;
        attendance_recorded: boolean;
        absent_count: number | null;
        summary_registered_at: string | null;
    } | null;
    session: {
        ulid: string | null;
        activity_ulid: string | null;
        status: string;
        presentation: string;
        presentation_label: string;
        has_summary: boolean;
    } | null;
    state: 'done' | 'pending' | 'current' | 'upcoming' | 'unopened';
    href: string;
};

export type DayAgenda = {
    available: boolean;
    date: string;
    bounds: { start: string; end: string } | null;
    items: DayItem[];
    totals: { lessons: number; summaries_registered: number; sessions: number; sessions_done: number; absences_recorded: number };
};

export type WeekLesson = { start_time: string; end_time: string | null; class: TurmaRef; label: string; state: DayItem['state']; lesson_ulid: string | null; href: string };

export type WeekEvent = { kind: 'calendar' | 'instrument' | 'activity'; type: string; type_label: string; title: string; start_time: string | null; class_label: string | null; href: string; state?: string };

export type WeekDay = { date: string; day: number; weekday: string; is_today: boolean; is_past: boolean; non_teaching: string | null; lessons: WeekLesson[]; events: WeekEvent[] };

export type WeekAgenda = { week: { start: string; end: string; is_current: boolean }; has_timetable: boolean; days: WeekDay[]; href: string };

export type UpcomingItem = { date: string; days_away: number; kind: 'calendar' | 'instrument' | 'period_end'; title: string; subtitle: string; class: TurmaRef | null; href: string };

export type PendingSummary = { ulid: string; starts_at: string; date: string; start_time: string; label: string; number: string | null; class: TurmaRef; attendance_recorded: boolean; href: string };

export type PendingWork = {
    summaries: { available: boolean; recent: PendingSummary[]; recent_count: number; older_count: number; older_href: string | null };
    classifications: { class: TurmaRef; proposed: number; confirmed: number; href: string }[];
    corrections: { items: { ulid: string; title: string; applied_on: string | null; pending_students: number; applicable_students: number; class: TurmaRef; href: string }[]; applied_without_correction: number };
    record_alerts: { count: number; href: string } | null;
    reviews: { ulid: string; title: string; target_label: string; review_on: string | null; support_measure_level: string | null; class: TurmaRef; href: string }[];
    per_class: Record<string, { summaries_missing: number; classifications_proposed: number; classifications_confirmed: number; corrections: number; alerts: number; reviews: number }>;
    counts: { summaries_missing: number; classifications_proposed: number; classifications_confirmed: number; corrections: number; record_alerts: number; reviews: number };
};

export type DoneItem = { at: string; time: string; kind: string; title: string; class: TurmaRef | null; href: string; count: number };

export type ClassCard = {
    class: TurmaRef;
    label: string;
    subject: string | null;
    students: number;
    has_profile: boolean;
    next_lesson: { starts_at: string; date: string; start_time: string; is_today: boolean } | null;
    absences_this_week: number | null;
    open_interventions: number | null;
    instruments: { period_label: string; total: number; corrected: number; in_correction: number; applied: number; to_apply: number; drafts: number } | null;
    records_in_period: number | null;
    last_record_at: string | null;
    self_assessments: { submitted: number; total: number } | null;
    hrefs: { class: string; assessments: string | null; classifications: string | null; records: string | null };
    pending: PendingWork['per_class'][string] | null;
};

export type PeriodRuler = {
    period: { ulid: string; label: string; starts_on: string; ends_on: string; day: number; days: number; progress: number; days_left: number };
    marks: { ulid: string; date: string; title: string; class: TurmaRef; state: 'corrected' | 'in_correction' | 'applied' | 'scheduled'; position: number; href: string }[];
} | null;

export type DashboardContext = {
    date: string;
    weekday: string;
    now: string;
    academic_year: { ulid: string; label: string } | null;
    period: { ulid: string; label: string } | null;
    week_number: number | null;
    has_classes: boolean;
    class_count: number;
    modules: { lessons: boolean; calendar: boolean; assessments: boolean; records: boolean; interventions: boolean; self_assessments: boolean };
};

export type DashboardData = {
    context: DashboardContext;
    day: DayAgenda;
    week: WeekAgenda;
    upcoming: UpcomingItem[];
    pending: PendingWork;
    done: DoneItem[];
    classes: ClassCard[];
    period: PeriodRuler;
};

/** Uma linha da fila: o que fazer, onde, e com que ação. */
export type QueueItem = {
    key: string;
    kind: 'summary' | 'summaries_older' | 'classifications' | 'alerts' | 'review' | 'correction';
    title: string;
    detail: string;
    action: string;
    href: string;
    class: TurmaRef | null;
    /** «âmbar» = alerta de acumulação, o único que leva a cor do cuidado no ícone. */
    emphasis: 'ink' | 'amber' | 'neutral';
    count: number;
};

const HOUR = (now: string): number => {
    const value = Number(new Intl.DateTimeFormat('pt-PT', { hour: 'numeric', hour12: false, timeZone: 'Europe/Lisbon' }).format(new Date(now)));

    return Number.isNaN(value) ? 12 : value;
};

/** Saudação pela hora de Lisboa — a app vive em Europe/Lisbon. */
export function greetingFor(now: string): string {
    const hour = HOUR(now);

    if (hour < 6 || hour >= 20) {
        return 'Boa noite';
    }

    return hour < 13 ? 'Bom dia' : 'Boa tarde';
}

const WEEKDAY_LABEL: Record<string, string> = {
    'segunda-feira': 'Segunda-feira',
    'terça-feira': 'Terça-feira',
    'quarta-feira': 'Quarta-feira',
    'quinta-feira': 'Quinta-feira',
    'sexta-feira': 'Sexta-feira',
    'sábado': 'Sábado',
    'domingo': 'Domingo',
};

/** Abreviaturas próprias, e não as do `Intl`: o ICU do Node escreve «quinta», o do browser «qui.». */
const WEEKDAY_SHORT = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

function weekdayShort(date: string): string {
    return WEEKDAY_SHORT[new Date(`${date}T12:00:00`).getDay()] ?? '';
}

const MONTHS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

/** «Segunda-feira, 26 de outubro · 1.º Período · 9.ª semana» */
export function eyebrowFor(context: DashboardContext): string {
    const [, month, day] = context.date.split('-').map(Number);
    const parts = [`${WEEKDAY_LABEL[context.weekday] ?? context.weekday}, ${day} de ${MONTHS[(month ?? 1) - 1]}`];

    if (context.period) {
        parts.push(context.period.label);
    }

    if (context.week_number) {
        parts.push(`${context.week_number}.ª semana`);
    }

    return parts.join(' · ');
}

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

/** «ter, 10:20» a partir de um ISO e do dia de hoje. */
export function whenLabel(startsAt: string, today: string): string {
    const date = startsAt.slice(0, 10);
    const time = startsAt.slice(11, 16);

    if (date === today) {
        return `hoje, ${time}`;
    }

    return `${weekdayShort(date).toLowerCase()}, ${time}`;
}

/** «Seg 3», «17 dez» — o rótulo curto de uma data do calendário. */
export function dateLabel(date: string, today: string): string {
    const target = new Date(`${date}T12:00:00`);
    const [, month, day] = date.split('-').map(Number);
    const days = Math.round((target.getTime() - new Date(`${today}T12:00:00`).getTime()) / 86_400_000);

    // Uma semana para cada lado fala-se pelo dia da semana; mais longe, pela data.
    if (days > -7 && days < 7) {
        return `${weekdayShort(date)} ${day}`;
    }

    return `${day} ${MONTHS[(month ?? 1) - 1].slice(0, 3)}`;
}

/**
 * A fila, por esta ordem de peso: sumários por registar (o mais antigo
 * primeiro), classificações propostas, alertas de acumulação, medidas em
 * atraso, elementos em correção, e por fim a contagem dos sumários antigos.
 * O primeiro é o «Agora»; o resto é «A seguir».
 */
export function buildQueue(pending: PendingWork, today: string): QueueItem[] {
    const queue: QueueItem[] = [];

    for (const lesson of pending.summaries.recent) {
        queue.push({
            key: `summary-${lesson.ulid}`,
            kind: 'summary',
            title: `Registar o sumário — ${lesson.label}`,
            detail: `${lesson.date === today ? 'Hoje' : dateLabel(lesson.date, today)}, ${lesson.start_time}${lesson.number ? ` · aula n.º ${lesson.number}` : ''}${lesson.attendance_recorded ? '' : ' · sem assiduidade'}`,
            action: 'Registar',
            href: lesson.href,
            class: lesson.class,
            emphasis: 'ink',
            count: 1,
        });
    }

    for (const item of pending.classifications) {
        if (item.proposed > 0) {
            queue.push({
                key: `confirm-${item.class.ulid}`,
                kind: 'classifications',
                title: `Confirmar ${plural(item.proposed, 'classificação', 'classificações')} — ${item.class.label}`,
                detail: 'Propostas do Lapispro à espera da tua decisão',
                action: 'Continuar',
                href: item.href,
                class: item.class,
                emphasis: 'ink',
                count: item.proposed,
            });
        }
    }

    if (pending.record_alerts && pending.record_alerts.count > 0) {
        queue.push({
            key: 'alerts',
            kind: 'alerts',
            title: pending.record_alerts.count === 1 ? 'Tratar um alerta de registos' : `Tratar ${pending.record_alerts.count} alertas de registos`,
            detail: 'TPC, atrasos ou faltas de material à 3.ª ocorrência · registar no Inovar ou dispensar',
            action: 'Abrir',
            href: pending.record_alerts.href,
            class: null,
            emphasis: 'amber',
            count: pending.record_alerts.count,
        });
    }

    for (const review of pending.reviews) {
        queue.push({
            key: `review-${review.ulid}`,
            kind: 'review',
            title: `Rever a medida de ${review.target_label} — ${review.class.label}`,
            detail: `${review.title}${review.review_on ? ` · revisão prevista para ${dateLabel(review.review_on, today)}` : ''} · em atraso`,
            action: 'Rever',
            href: review.href,
            class: review.class,
            emphasis: 'ink',
            count: 1,
        });
    }

    for (const item of pending.corrections.items) {
        queue.push({
            key: `correction-${item.ulid}`,
            kind: 'correction',
            title: `Corrigir «${item.title}» — ${item.class.label}`,
            detail: `${item.pending_students} de ${item.applicable_students} alunos por corrigir`,
            action: 'Corrigir',
            href: item.href,
            class: item.class,
            emphasis: 'ink',
            count: item.pending_students,
        });
    }

    for (const item of pending.classifications) {
        if (item.confirmed > 0) {
            queue.push({
                key: `publish-${item.class.ulid}`,
                kind: 'classifications',
                title: `Publicar ${plural(item.confirmed, 'classificação confirmada', 'classificações confirmadas')} — ${item.class.label}`,
                detail: 'Quando quiseres: confirmadas, ainda não publicadas',
                action: 'Publicar',
                href: item.href,
                class: item.class,
                emphasis: 'neutral',
                count: item.confirmed,
            });
        }
    }

    if (pending.summaries.older_count > 0 && pending.summaries.older_href) {
        queue.push({
            key: 'summaries-older',
            kind: 'summaries_older',
            title: `${plural(pending.summaries.older_count, 'sumário anterior por registar', 'sumários anteriores por registar')}`,
            detail: 'Há mais de catorze dias · abre a semana mais antiga em falta',
            action: 'Consultar',
            href: pending.summaries.older_href,
            class: null,
            emphasis: 'neutral',
            count: pending.summaries.older_count,
        });
    }

    return queue;
}

/** O estado do dia em PROSA, só com factos que a base sabe. */
export function statusPhrase(data: DashboardData, queue: QueueItem[]): string {
    if (!data.context.has_classes) {
        return 'Ainda sem turmas neste ano letivo.';
    }

    const day = data.day;
    const parts: string[] = [];

    if (day.available && day.totals.lessons > 0) {
        const lessons = plural(day.totals.lessons, 'aula', 'aulas');
        const sessions = day.totals.sessions > 0 ? ` e ${plural(day.totals.sessions, 'outra atividade', 'outras atividades')}` : '';
        const registered = day.totals.summaries_registered;

        if (registered === 0 && day.items.every((item) => item.state === 'upcoming')) {
            parts.push(`${lessons}${sessions} hoje; a primeira é às ${day.items[0]?.start_time}.`);
        } else if (registered === day.totals.lessons) {
            parts.push(`${lessons}${sessions} hoje, tudo sumariado.`);
        } else {
            parts.push(`${registered} de ${day.totals.lessons} sumários de hoje registados.`);
        }
    }

    if (queue.length === 0) {
        parts.push(parts.length > 0 ? 'O resto está em dia.' : 'Tudo em dia.');

        return parts.join(' ');
    }

    const first = queue[0];
    const rest = queue.length - 1;

    parts.push(
        rest === 0
            ? `Falta ${first?.title.charAt(0).toLowerCase()}${first?.title.slice(1)}.`
            : `${queue.length} coisas pedem a tua decisão; a primeira: ${first?.title.charAt(0).toLowerCase()}${first?.title.slice(1)}.`,
    );

    return parts.join(' ');
}

/**
 * A frase-assinatura só quando é verdade: há turmas, a fila está vazia e
 * nenhuma aula de hoje ficou por registar (uma ocorrência por abrir não
 * conta — não há aula).
 */
export function allDone(data: DashboardData, queue: QueueItem[]): boolean {
    return data.context.has_classes
        && queue.length === 0
        && !data.day.items.some((item) => item.state === 'pending');
}

/** Minutos desde a meia-noite de um «HH:MM». */
export function minutesOf(time: string): number {
    const [hours, minutes] = time.split(':').map(Number);

    return (hours ?? 0) * 60 + (minutes ?? 0);
}
