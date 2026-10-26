import { describe, expect, it } from 'vitest';
import { allDone, buildQueue, dateLabel, eyebrowFor, greetingFor, minutesOf, statusPhrase, whenLabel } from './dashboard';
import type { DashboardData, PendingWork } from './dashboard';

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
        expect(statusPhrase(dashboard, queue)).toBe('Tudo em dia.');
        expect(allDone(dashboard, queue)).toBe(true);
    });

    it('sem turmas não há fecho nem frase inventada', () => {
        const dashboard = data({ context: { ...data().context, has_classes: false, class_count: 0 } });

        expect(statusPhrase(dashboard, [])).toBe('Ainda sem turmas neste ano letivo.');
        expect(allDone(dashboard, [])).toBe(false);
    });

    it('uma aula de hoje por registar impede o fecho mesmo com a fila vazia', () => {
        const dashboard = data({
            day: { available: true, date: '2026-10-26', bounds: { start: '08:00', end: '17:00' }, totals: { lessons: 1, summaries_registered: 0, sessions: 0, sessions_done: 0, absences_recorded: 0 }, items: [{ kind: 'lesson', key: 'k', starts_at: '2026-10-26T08:30:00+00:00', ends_at: '2026-10-26T10:00:00+00:00', start_time: '08:30', end_time: '10:00', title: '7.º A', subtitle: 'Português', class: turma, lesson: null, session: null, state: 'pending', href: '/lessons/x' }] },
        });

        expect(allDone(dashboard, [])).toBe(false);
        expect(statusPhrase(dashboard, [])).toBe('0 de 1 sumários de hoje registados. O resto está em dia.');
    });

    it('a frase do dia conta o que falta', () => {
        const dashboard = data({ pending: { ...emptyPending, classifications: [{ class: turma, proposed: 12, confirmed: 0, href: '/x' }], record_alerts: { count: 1, href: '/records' } } });
        const queue = buildQueue(dashboard.pending, '2026-10-26');

        expect(statusPhrase(dashboard, queue)).toBe('2 coisas pedem a tua decisão; a primeira: confirmar 12 classificações — 7.º A.');
    });
});
