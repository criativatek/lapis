import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { DashboardData, DoneItem, PendingWork } from '@/lib/dashboard';
import Dashboard from './Dashboard.vue';

const pageProps = { modules: [] as string[], readOnlyModules: [] as string[] };

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: defineComponent({ setup: () => () => null }),
        Link: defineComponent({
            props: { href: { type: String, default: '' } },
            setup:
                (props, { slots }) =>
                () =>
                    h('a', { href: props.href }, slots.default?.()),
        }),
        router: { post: vi.fn(), visit: vi.fn() },
        usePage: () => ({ props: pageProps }),
    };
});

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
        day: {
            available: true, date: '2026-10-26', bounds: { start: '08:00', end: '17:00' },
            items: [{ kind: 'lesson', key: 'l1', starts_at: '2026-10-26T08:30:00+00:00', ends_at: '2026-10-26T10:00:00+00:00', start_time: '08:30', end_time: '10:00', title: '7.º A', subtitle: 'Português', class: turma, lesson: { ulid: 'l1', number: '23', status: 'taught', outcome: 'taught', outcome_label: 'Lecionada', has_summary: true, attendance_recorded: true, absent_count: 2, summary_registered_at: '2026-10-26T10:07:00+00:00' }, session: null, state: 'done', href: '/lessons/l1' }],
            totals: { lessons: 1, summaries_registered: 1, sessions: 0, sessions_done: 0, absences_recorded: 2 },
        },
        week: {
            week: { start: '2026-10-26', end: '2026-10-30', is_current: true }, has_timetable: true, href: '/lessons?view=semana&week=2026-10-26',
            days: [
                { date: '2026-10-26', day: 26, weekday: 'Seg', is_today: true, is_past: false, non_teaching: null, lessons: [{ start_time: '08:30', end_time: '10:00', class: turma, label: '7.º A', state: 'done', lesson_ulid: 'l1', href: '/lessons/l1' }], events: [{ kind: 'calendar', type: 'meeting', type_label: 'Reunião', title: 'Reunião de departamento', start_time: '14:30', class_label: null, href: '/calendar' }] },
                { date: '2026-10-27', day: 27, weekday: 'Ter', is_today: false, is_past: false, non_teaching: null, lessons: [{ start_time: '10:20', end_time: '11:50', class: turma, label: '7.º A', state: 'upcoming', lesson_ulid: null, href: '/lessons' }], events: [] },
            ],
        },
        upcoming: [{ date: '2026-12-17', days_away: 52, kind: 'period_end', title: 'Fim do 1.º Período', subtitle: 'Estrutura do ano letivo', class: null, href: '/academic-years' }],
        pending: emptyPending,
        done: [],
        classes: [{ class: turma, label: '7.º A', subject: 'Português', students: 26, has_profile: true, next_lesson: { starts_at: '2026-10-27T10:20:00+00:00', date: '2026-10-27', start_time: '10:20', is_today: false }, absences_this_week: 2, open_interventions: 1, instruments: { period_label: '1.º Período', total: 5, corrected: 3, in_correction: 1, applied: 0, to_apply: 1, drafts: 0 }, records_in_period: 4, last_record_at: null, self_assessments: null, hrefs: { class: '/classes/c7a', assessments: '/assessments', classifications: '/classes/c7a/classifications', records: '/records' }, pending: { summaries_missing: 0, classifications_proposed: 0, classifications_confirmed: 0, corrections: 0, alerts: 0, reviews: 0 } }],
        period: { period: { ulid: 'p', label: '1.º Período', starts_on: '2026-09-15', ends_on: '2026-12-17', day: 42, days: 94, progress: 0.44, days_left: 52 }, marks: [] },
        ...overrides,
    };
}

function dashboard(overrides: Partial<DashboardData> = {}, readiness = true) {
    return mount(Dashboard, {
        props: {
            teacherName: 'Ana Teste',
            readiness: { is_ready: readiness, items: [] },
            firstSteps: { dismissed: true, all_done: true, items: [] },
            dashboard: data(overrides),
        },
    });
}

describe('Dashboard — atividade', () => {
    beforeEach(() => {
        pageProps.modules = [];
        pageProps.readOnlyModules = [];
    });

    it('Base/Pro: «Minha atividade» sempre, sem link para a auditoria da organização', () => {
        pageProps.modules = ['reports', 'lessons', 'ai_reports'];
        const wrapper = dashboard();

        expect(wrapper.find('a[href="/activity"]').text()).toBe('Minha atividade');
        expect(wrapper.find('a[href="/activity/organization"]').exists()).toBe(false);
    });

    it('Institucional: as duas leituras', () => {
        pageProps.modules = ['audit_log'];
        const wrapper = dashboard();

        expect(wrapper.find('a[href="/activity"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/activity/organization"]').text()).toBe('Auditoria da organização');
    });

    it('uma organização suspensa que ainda pode consultar mantém a auditoria', () => {
        pageProps.readOnlyModules = ['audit_log'];

        expect(dashboard().find('a[href="/activity/organization"]').exists()).toBe(true);
    });
});

describe('Dashboard — Hoje', () => {
    beforeEach(() => {
        pageProps.modules = ['lessons', 'assessments', 'records'];
        pageProps.readOnlyModules = [];
    });

    it('com horário (Pro) a régua do dia existe e a régua do período fica na coluna lateral', () => {
        const wrapper = dashboard();

        expect(wrapper.find('[data-testid="day-ruler"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="period-ruler"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="day-ruler"]').text()).toContain('7.º A');
        expect(wrapper.find('[data-testid="day-ruler"]').text()).toContain('1 de 1 sumário registado');
        expect(wrapper.find('[data-testid="day-ruler"] a[href="/lessons?view=semana&week=2026-10-26"]').exists()).toBe(true);
    });

    it('sem horário (Base) o lugar do dia é da régua do período, com os acontecimentos de hoje', () => {
        pageProps.modules = ['assessments', 'records'];
        const base = data();
        base.context.modules.lessons = false;
        base.day = { available: false, date: '2026-10-26', bounds: null, items: [], totals: { lessons: 0, summaries_registered: 0, sessions: 0, sessions_done: 0, absences_recorded: 0 } };
        base.week.has_timetable = false;
        base.week.days[0]!.lessons = [];
        const wrapper = dashboard(base);

        expect(wrapper.find('[data-testid="day-ruler"]').exists()).toBe(false);
        const ruler = wrapper.find('[data-testid="period-ruler"]');
        expect(ruler.text()).toContain('Hoje e o período');
        expect(ruler.text()).toContain('Reunião de departamento');
        expect(wrapper.find('[data-testid="week-agenda"]').text()).toContain('Com o horário do Lapispro Pro');
        expect(wrapper.find('[data-testid="class-cards"]').text()).toContain('registos');
    });

    it('a saudação e a frase do dia vêm dos factos', () => {
        const wrapper = dashboard();

        expect(wrapper.find('h1').text()).toBe('Boa tarde, Ana.');
        expect(wrapper.find('[data-testid="status-phrase"]').text()).toBe('1 aula hoje, tudo sumariado. O resto está em dia.');
        expect(wrapper.find('[data-testid="closing-line"]').exists()).toBe(true);
    });
});

describe('Dashboard — a fila e o arrumado', () => {
    beforeEach(() => {
        pageProps.modules = ['lessons', 'assessments', 'records'];
        pageProps.readOnlyModules = [];
    });

    it('a primeira pendência é o «Agora» com a ação à vista e o resto espera em «A seguir»', () => {
        const pending: PendingWork = {
            ...emptyPending,
            summaries: { available: true, recent: [{ ulid: 'l9', starts_at: '2026-10-23T10:00:00+00:00', date: '2026-10-23', start_time: '10:00', label: '7.º A', number: '12', class: turma, attendance_recorded: false, href: '/lessons/l9' }], recent_count: 1, older_count: 0, older_href: null },
            classifications: [{ class: turma, proposed: 12, confirmed: 0, href: '/classes/c7a/classifications' }],
        };
        const wrapper = dashboard({ pending });

        expect(wrapper.find('[data-testid="agora"]').text()).toContain('Registar o sumário — 7.º A');
        expect(wrapper.find('[data-testid="agora"] a').attributes('href')).toBe('/lessons/l9');
        expect(wrapper.find('[data-testid="a-seguir"]').text()).toContain('Confirmar 12 classificações — 7.º A');
        expect(wrapper.find('[data-testid="closing-line"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="primary-action"]').attributes('href')).toBe('/lessons/l9');
    });

    it('«A seguir» esconde do quinto item em diante no telemóvel até se pedir o resto', async () => {
        const pending: PendingWork = {
            ...emptyPending,
            classifications: ['a', 'b', 'c', 'd', 'e', 'f', 'g'].map((ulid) => ({ class: { ...turma, ulid, label: `Turma ${ulid}` }, proposed: 2, confirmed: 0, href: `/classes/${ulid}/classifications` })),
        };
        const wrapper = dashboard({ pending });
        const section = wrapper.find('[data-testid="a-seguir"]');

        expect(section.findAll('li')).toHaveLength(6);
        expect(section.findAll('li[data-mobile-hidden="true"]')).toHaveLength(2);
        expect(wrapper.text()).toContain('por onde começar');
        expect(wrapper.text()).not.toContain('não deve esperar');

        await section.find('[data-testid="a-seguir-toggle"]').trigger('click');

        expect(section.findAll('li[data-mobile-hidden="true"]')).toHaveLength(0);
    });

    it('«Arrumado hoje» mostra três linhas e abre o resto do dia', async () => {
        const done: DoneItem[] = ['17:48', '16:31', '12:03', '10:07', '09:58'].map((time, index) => ({
            at: `2026-10-26T${time}:00+00:00`, time, kind: 'lesson', title: `Coisa ${index + 1}`, class: turma, href: '/lessons/x', count: 1,
        }));
        const wrapper = dashboard({ done });
        const section = wrapper.find('[data-testid="done-today"]');

        expect(section.findAll('li')).toHaveLength(3);
        expect(section.text()).toContain('as 3 mais recentes');

        await section.find('[data-testid="done-toggle"]').trigger('click');

        expect(section.findAll('li')).toHaveLength(5);
        expect(section.text()).toContain('Mostrar só as mais recentes');
    });

    it('sem turmas nada se inventa: o vazio diz o próximo passo e não há fecho', () => {
        const empty = data({ classes: [], done: [] });
        empty.context.has_classes = false;
        empty.context.class_count = 0;
        empty.day = { available: true, date: '2026-10-26', bounds: null, items: [], totals: { lessons: 0, summaries_registered: 0, sessions: 0, sessions_done: 0, absences_recorded: 0 } };
        const wrapper = dashboard(empty);

        expect(wrapper.find('[data-testid="agora-empty"]').text()).toContain('Ainda sem turmas');
        expect(wrapper.find('[data-testid="closing-line"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="class-cards"]').exists()).toBe(false);
    });

    it('com o ano por preparar só a lista de preparação aparece', () => {
        const wrapper = dashboard({}, false);

        expect(wrapper.text()).toContain('Prepare o ano letivo');
        expect(wrapper.find('[data-testid="day-ruler"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="agora"]').exists()).toBe(false);
    });
});
