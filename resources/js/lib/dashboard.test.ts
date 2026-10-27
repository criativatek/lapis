import { describe, expect, it } from 'vitest';
import { allDone, buildQueue, dateLabel, doneClassHint, eyebrowFor, greetingFor, heroCards, heroExtraLines, heroGridClass, heroMinHeightClass, minutesOf, pendingLabel, whenLabel } from './dashboard';
import type { DashboardData, PendingWork, QueueItem } from './dashboard';

const turma = { ulid: 'c7a', label: '7.º A', subject: 'Português', tone: 'blue' };

const emptyPending: PendingWork = {
    summaries: { available: true, recent: [], recent_count: 0, older_count: 0, older_href: null },
    classifications: [],
    corrections: { items: [], applied_without_correction: 0 },
    record_alerts: null,
    reviews: [],
    per_class: {},
    counts: { summaries_missing: 0, classifications_proposed: 0, classifications_confirmed: 0, corrections: 0, record_alerts: 0, reviews: 0 },
};

function data(overrides: Partial<DashboardData> = {}): DashboardData {
    return {
        context: {
            date: '2026-10-26', weekday: 'segunda-feira', now: '2026-10-26T16:40:00+00:00',
            academic_year: { ulid: 'y', label: '2026/27' }, period: { ulid: 'p', label: '1.º Período' }, week_number: 9,
            has_classes: true, class_count: 1,
            modules: { lessons: true, calendar: true, assessments: true, records: true, interventions: true, self_assessments: true },
        },
        day: { available: true, date: '2026-10-26', bounds: { start: '08:00', end: '17:00' }, items: [], totals: { lessons: 0, summaries_registered: 0, sessions: 0, sessions_done: 0, absences_recorded: 0 } },
        week: { week: { start: '2026-10-26', end: '2026-10-30', is_current: true }, has_timetable: true, days: [], href: '/lessons' },
        upcoming: [],
        pending: emptyPending,
        done: [],
        classes: [],
        period: null,
        ...overrides,
    };
}

describe('dashboard — saudação e cabeçalho', () => {
    it('saúda pela hora de Lisboa', () => {
        expect(greetingFor('2026-10-26T08:05:00+00:00')).toBe('Bom dia');
        expect(greetingFor('2026-10-26T16:40:00+00:00')).toBe('Boa tarde');
        expect(greetingFor('2026-10-26T20:30:00+00:00')).toBe('Boa noite');
        expect(greetingFor('2026-07-26T12:30:00+00:00')).toBe('Boa tarde'); // 13:30 em Lisboa no verão
    });

    it('escreve a linha do dia com período e semana', () => {
        expect(eyebrowFor(data().context)).toBe('Segunda-feira, 26 de outubro · 1.º Período · 9.ª semana');
    });

    it('rotula datas próximas pelo dia da semana e as longínquas pelo mês', () => {
        expect(dateLabel('2026-10-29', '2026-10-26')).toBe('Qui 29');
        expect(dateLabel('2026-12-17', '2026-10-26')).toBe('17 dez');
        expect(whenLabel('2026-10-26T08:30:00+00:00', '2026-10-26')).toBe('hoje, 08:30');
        expect(minutesOf('08:30')).toBe(510);
    });
});

describe('dashboard — a fila', () => {
    it('ordena por peso: sumários, classificações propostas, alertas, medidas, correções, publicação, sumários antigos', () => {
        const pending: PendingWork = {
            ...emptyPending,
            summaries: { available: true, recent: [{ ulid: 'l1', starts_at: '2026-10-23T10:00:00+00:00', date: '2026-10-23', start_time: '10:00', label: '7.º A', number: '12', class: turma, attendance_recorded: false, href: '/lessons/l1' }], recent_count: 1, older_count: 2, older_href: '/lessons?week=2026-10-05&summary=sem' },
            classifications: [{ class: turma, proposed: 12, confirmed: 3, href: '/classes/c7a/classifications' }],
            record_alerts: { count: 1, href: '/records' },
            reviews: [{ ulid: 'i1', title: 'Leitura orientada', target_label: 'João M.', review_on: '2026-10-23', support_measure_level: null, class: turma, href: '/classes/c7a/interventions' }],
            corrections: { items: [{ ulid: 'in1', title: 'Ficha de leitura', applied_on: '2026-10-20', pending_students: 8, applicable_students: 24, class: turma, href: '/assessments/in1' }], applied_without_correction: 1 },
        };

        const queue = buildQueue(pending, '2026-10-26');

        expect(queue.map((item) => item.kind)).toEqual(['summary', 'classifications', 'alerts', 'review', 'correction', 'classifications', 'summaries_older']);
        expect(queue[0]?.title).toBe('Registar o sumário — 7.º A');
        expect(queue[0]?.detail).toBe('Sex 23, 10:00 · aula n.º 12 · sem assiduidade');
        expect(queue[1]?.title).toBe('Confirmar 12 classificações — 7.º A');
        expect(queue[2]?.emphasis).toBe('amber');
        expect(queue[6]?.title).toBe('2 sumários anteriores por registar');
    });

    it('uma fila vazia com turmas e nada por registar é o fecho', () => {
        const dashboard = data();
        const queue = buildQueue(dashboard.pending, '2026-10-26');

        expect(queue).toEqual([]);
        expect(allDone(dashboard, queue)).toBe(true);
    });

    it('sem turmas não há fecho', () => {
        const dashboard = data({ context: { ...data().context, has_classes: false, class_count: 0 } });

        expect(allDone(dashboard, [])).toBe(false);
    });

    it('uma aula de hoje por registar impede o fecho mesmo com a fila vazia', () => {
        const dashboard = data({
            day: { available: true, date: '2026-10-26', bounds: { start: '08:00', end: '17:00' }, totals: { lessons: 1, summaries_registered: 0, sessions: 0, sessions_done: 0, absences_recorded: 0 }, items: [{ kind: 'lesson', key: 'k', starts_at: '2026-10-26T08:30:00+00:00', ends_at: '2026-10-26T10:00:00+00:00', start_time: '08:30', end_time: '10:00', title: '7.º A', subtitle: 'Português', class: turma, lesson: null, session: null, state: 'pending', href: '/lessons/x' }] },
        });

        expect(allDone(dashboard, [])).toBe(false);
    });
});

const item = (kind: QueueItem['kind'], key: string = kind): QueueItem => ({ key, kind, title: 't', detail: 'd', action: 'a', href: '/x', class: null, emphasis: 'ink', count: 1 });

const lessonItem = (state: DashboardData['day']['items'][number]['state'], key: string): DashboardData['day']['items'][number] => ({
    kind: 'lesson', key, starts_at: '2026-10-26T08:30:00+00:00', ends_at: null, start_time: '08:30', end_time: null, title: '7.º A', subtitle: null, class: turma, lesson: null, session: null, state, href: '/lessons/x',
});

const summaryOn = (date: string, ulid: string): PendingWork['summaries']['recent'][number] => ({ ulid, starts_at: `${date}T10:00:00+00:00`, date, start_time: '10:00', label: '7.º A', number: null, class: turma, attendance_recorded: true, href: '/lessons/x' });

/** lessons = aulas de hoje; registered = sumários de hoje; pendingToday = aulas de hoje já passadas sem sumário; previous = atrasos anteriores a hoje. */
function withDay(lessons: number, registered: number, pendingToday = 0, previous = 0, overrides: Partial<DashboardData> = {}): DashboardData {
    const base = data();
    const recent = Array.from({ length: Math.min(previous, 1) }, (_, index) => summaryOn('2026-10-23', `r${index}`));

    return data({
        day: {
            ...base.day,
            totals: { ...base.day.totals, lessons, summaries_registered: registered },
            items: [...Array.from({ length: pendingToday }, (_, index) => lessonItem('pending', `p${index}`)), ...Array.from({ length: registered }, (_, index) => lessonItem('done', `d${index}`))],
        },
        pending: { ...emptyPending, summaries: { ...emptyPending.summaries, recent, older_count: Math.max(previous - recent.length, 0) } },
        ...overrides,
    });
}

describe('dashboard — rótulo das pendências', () => {
    it('um só tipo diz o tipo, com singular e plural', () => {
        expect(pendingLabel([item('correction')])).toBe('correção');
        expect(pendingLabel([item('correction', 'a'), item('correction', 'b')])).toBe('correções');
        expect(pendingLabel([item('summary'), item('summary', 'b')])).toBe('sumários');
        expect(pendingLabel([item('review')])).toBe('medida em atraso');
        expect(pendingLabel([item('review'), item('review', 'b')])).toBe('medidas em atraso');
        expect(pendingLabel([item('alerts')])).toBe('alerta');
    });

    it('tipos mistos ou sem nome próprio dizem «pendências»', () => {
        expect(pendingLabel([item('summary'), item('correction')])).toBe('pendências');
        expect(pendingLabel([item('classifications')])).toBe('pendência');
        expect(pendingLabel([item('classifications'), item('classifications', 'b')])).toBe('pendências');
        expect(pendingLabel([item('summaries_older')])).toBe('pendência');
    });
});

describe('dashboard — os três cartões do cabeçalho', () => {
    const find = (cards: ReturnType<typeof heroCards>, key: string) => cards?.find((card) => card.key === key);

    it('sem turmas não há cartões', () => {
        expect(heroCards(data({ context: { ...data().context, has_classes: false } }), [])).toBeNull();
    });

    it('Hoje, com horário: as aulas previstas, no singular e no plural', () => {
        expect(find(heroCards(withDay(1, 1, 0), []), 'today')).toMatchObject({ tone: 'sky', value: 1, label: 'aula' });
        expect(find(heroCards(withDay(3, 0, 0), []), 'today')).toMatchObject({ value: 3, label: 'aulas' });
        expect(find(heroCards(withDay(0, 0, 0), []), 'today')).toMatchObject({ value: 0, label: 'aulas' });
    });

    it('Hoje, na Base (sem horário): os acontecimentos de hoje, nada inventado', () => {
        const base = data();
        const event = { kind: 'calendar' as const, type: 'meeting', type_label: 'Reunião', title: 'R', start_time: null, class_label: null, href: '/c' };
        const day = { date: '2026-10-26', day: 26, weekday: 'Seg', is_today: true, is_past: false, non_teaching: null, lessons: [], events: [event, event] };
        const noTimetable = { ...base.day, available: false };

        expect(find(heroCards(data({ day: noTimetable, week: { ...base.week, days: [day] } }), []), 'today')).toMatchObject({ value: 2, label: 'acontecimentos' });
        expect(find(heroCards(data({ day: noTimetable, week: { ...base.week, days: [{ ...day, events: [event] }] } }), []), 'today')).toMatchObject({ value: 1, label: 'acontecimento' });
        expect(find(heroCards(data({ day: noTimetable }), []), 'today')).toMatchObject({ value: 0, label: 'acontecimentos' });
    });

    it('Sumários: 1/1 concluídos é verde e o aviso dos anteriores não toca na linha principal', () => {
        const card = find(heroCards(withDay(1, 1, 0, 2), []), 'summaries');

        expect(card).toMatchObject({ tone: 'mint', value: '1/1', label: 'concluídos', hint: null, warning: '2 sumários anteriores em falta' });
        expect(`${card?.value} ${card?.label} ${card?.hint ?? ''}`).not.toMatch(/anterior/);
    });

    it('Sumários: sem atrasos anteriores não há aviso', () => {
        expect(find(heroCards(withDay(1, 1), []), 'summaries')?.warning).toBeNull();
    });

    it('Sumários: 0/2 com aulas ainda por acontecer não é «em falta» (verde)', () => {
        expect(find(heroCards(withDay(2, 0, 0), []), 'summaries')).toMatchObject({ tone: 'mint', value: '0/2', label: 'concluídos', hint: null, warning: null });
    });

    it('Sumários: um sumário de HOJE em falta é âmbar com a nota, e sem anteriores', () => {
        expect(find(heroCards(withDay(2, 0, 1, 0), []), 'summaries')).toMatchObject({ tone: 'amber', value: '0/2', label: 'concluídos', hint: '1 de hoje em falta', warning: null });
    });

    it('Sumários: hoje e anteriores em falta convivem, cada um na sua linha', () => {
        expect(find(heroCards(withDay(3, 1, 2, 3), []), 'summaries')).toMatchObject({ tone: 'amber', value: '1/3', hint: '2 de hoje em falta', warning: '3 sumários anteriores em falta' });
    });

    it('Sumários: os anteriores somam os recentes de antes de hoje e os antigos; os de hoje não contam', () => {
        const base = withDay(1, 1);
        base.pending = { ...emptyPending, summaries: { ...emptyPending.summaries, recent: [summaryOn('2026-10-26', 'hoje'), summaryOn('2026-10-24', 'a')], older_count: 4 } };

        expect(find(heroCards(base, []), 'summaries')?.warning).toBe('5 sumários anteriores em falta');
    });

    it('Sumários: singular', () => {
        expect(find(heroCards(withDay(1, 1, 0, 1), []), 'summaries')?.warning).toBe('1 sumário anterior em falta');
    });

    it('Sumários: sem aulas hoje, «—» verde e o aviso se houver anteriores', () => {
        expect(find(heroCards(withDay(0, 0, 0, 3), []), 'summaries')).toMatchObject({ tone: 'mint', value: '—', label: 'sem aulas hoje', warning: '3 sumários anteriores em falta' });
        expect(find(heroCards(withDay(0, 0), []), 'summaries')).toMatchObject({ tone: 'mint', value: '—', label: 'sem aulas hoje', warning: null });
    });

    it('Sumários: sem horário nunca inventa um rácio', () => {
        const base = withDay(0, 0, 0, 2);
        base.day = { ...base.day, available: false };

        expect(find(heroCards(base, []), 'summaries')).toMatchObject({ tone: 'mint', value: '—', label: 'sem horário', warning: '2 sumários anteriores em falta' });
        base.pending = emptyPending;
        expect(find(heroCards(base, []), 'summaries')).toMatchObject({ value: '—', label: 'sem horário', warning: null });
    });

    it('sem o módulo das aulas não há cartão de Sumários: dois cartões', () => {
        const base = withDay(0, 0, 0);
        base.context.modules.lessons = false;
        const cards = heroCards(base, []);

        expect(cards?.map((card) => card.key)).toEqual(['today', 'pending']);
    });

    it('Pendentes: o número é o comprimento da lista e o rótulo diz o tipo', () => {
        const queue = [item('correction', 'a'), item('correction', 'b'), item('correction', 'c')];

        expect(find(heroCards(withDay(1, 1, 0), queue), 'pending')).toMatchObject({ tone: 'plain', attention: true, value: 3, label: 'correções' });
        expect(find(heroCards(withDay(1, 1, 0), [item('summary'), item('review')]), 'pending')).toMatchObject({ value: 2, label: 'pendências' });
        expect(find(heroCards(withDay(1, 1, 0), [item('classifications')]), 'pending')).toMatchObject({ value: 1, label: 'pendência' });
    });

    it('Pendentes: zero pendências é «Tudo em dia», verde', () => {
        expect(find(heroCards(withDay(1, 1, 0), []), 'pending')).toMatchObject({ tone: 'mint', attention: false, value: 0, label: 'Tudo em dia', hint: null, warning: null });
    });

    it('com módulo, horário e pendências saem os três, por esta ordem', () => {
        expect(heroCards(withDay(1, 1, 0), [item('summary')])?.map((card) => card.key)).toEqual(['today', 'summaries', 'pending']);
    });
});

describe('dashboard — altura uniforme dos cartões', () => {
    const card = (hint: string | null, warning: string | null) => ({ hint, warning });

    it('conta o máximo de linhas extra (0, 1 ou 2) entre os cartões', () => {
        expect(heroExtraLines([card(null, null), card(null, null)])).toBe(0);
        expect(heroExtraLines([card(null, null), card('1 de hoje em falta', null)])).toBe(1);
        expect(heroExtraLines([card(null, 'aviso'), card(null, null)])).toBe(1);
        expect(heroExtraLines([card(null, null), card('nota', 'aviso')])).toBe(2);
    });

    it('cada nível tem uma classe de altura mínima própria e crescente, só quando os cartões estão em colunas', () => {
        expect([0, 1, 2].map((n) => heroMinHeightClass(n as 0 | 1 | 2))).toEqual(['@min-[40.5rem]:min-h-[76px]', '@min-[40.5rem]:min-h-[94px]', '@min-[40.5rem]:min-h-[112px]']);
        expect([0, 1, 2].map((n) => heroMinHeightClass(n as 0 | 1 | 2, 2))).toEqual(['@min-[26.5rem]:min-h-[76px]', '@min-[26.5rem]:min-h-[94px]', '@min-[26.5rem]:min-h-[112px]']);
    });

    it('a grelha usa container queries: 3 colunas só com ~205 px por cartão, 2 com 2 cartões', () => {
        expect(heroGridClass(3)).toContain('@min-[40.5rem]:grid-cols-3');
        expect(heroGridClass(2)).toContain('@min-[26.5rem]:grid-cols-2');
        expect(heroGridClass(3)).toContain('grid-cols-1');
        // empilhados não se igualam (sem espaço desperdiçado): as alturas só se igualam em colunas
        expect(heroGridClass(3)).toContain('@min-[40.5rem]:auto-rows-fr');
        expect(heroGridClass(3).split(' ')).not.toContain('auto-rows-fr');
        expect(heroGridClass(2).split(' ')).not.toContain('auto-rows-fr');
    });
});

describe('dashboard — a turma de uma linha de «Arrumado hoje»', () => {
    // O servidor (DashboardDoneToday::item) escreve a turma no título das aulas,
    // classificações, alertas e medidas; só as sessões de outras atividades a trazem à parte.
    it('não repete a turma quando o título já a diz (aulas, grupos, classificações)', () => {
        expect(doneClassHint({ title: 'Sumário — 7.º A', class: turma })).toBeNull();
        expect(doneClassHint({ title: 'Sumário e assiduidade — 7.º A · T1', class: turma })).toBeNull();
        expect(doneClassHint({ title: '3 classificações confirmadas — 7.º A', class: turma })).toBeNull();
    });

    it('mostra a turma quando o título não a diz (sessões das outras atividades)', () => {
        expect(doneClassHint({ title: 'Realizada — Clube de Leitura', class: turma })).toBe('7.º A');
    });

    it('sem turma não há nada a mostrar', () => {
        expect(doneClassHint({ title: 'Realizada — Clube de Leitura', class: null })).toBeNull();
    });
});
