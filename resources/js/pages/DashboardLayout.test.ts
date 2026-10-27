import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import type { DashboardData, DoneItem, PendingWork } from '@/lib/dashboard';
import { layoutStorageKey } from '@/lib/dashboardLayout';
import Dashboard from './Dashboard.vue';

/**
 * A disposição do painel: ordem padrão, «Organizar painel», persistência por
 * utilizador e organização. O cabeçalho e a régua «Hoje» são fixos.
 */
const pageProps = {
    modules: ['lessons', 'assessments', 'records'] as string[],
    readOnlyModules: [] as string[],
    auth: { user: { id: 7 } as { id: number } | null, organization: { ulid: 'org-a' } as { ulid: string } | null },
};

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
        router: { post: vi.fn(), visit: vi.fn(), get: vi.fn() },
        usePage: () => ({ props: pageProps, url: '/dashboard' }),
    };
});

// jsdom não implementa scrollIntoView.
beforeAll(() => {
    Element.prototype.scrollIntoView = vi.fn();
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
        day: { available: true, date: '2026-10-26', bounds: { start: '08:00', end: '17:00' }, items: [], totals: { lessons: 0, summaries_registered: 0, sessions: 0, sessions_done: 0, absences_recorded: 0 } },
        week: {
            week: { start: '2026-10-26', end: '2026-10-30', is_current: true }, has_timetable: true, href: '/lessons?view=semana&week=2026-10-26',
            days: [{ date: '2026-10-26', day: 26, weekday: 'Seg', is_today: true, is_past: false, non_teaching: null, lessons: [], events: [] }],
        },
        upcoming: [],
        pending: emptyPending,
        done: [],
        classes: [{ class: turma, label: '7.º A', subject: 'Português', students: 26, has_profile: true, next_lesson: null, absences_this_week: 0, open_interventions: 0, instruments: null, records_in_period: 0, last_record_at: null, self_assessments: null, hrefs: { class: '/classes/c7a', assessments: null, classifications: null, records: null }, pending: null }],
        period: { period: { ulid: 'p', label: '1.º Período', starts_on: '2026-09-15', ends_on: '2026-12-17', day: 42, days: 94, progress: 0.44, days_left: 52 }, marks: [] },
        ...overrides,
    };
}

const doneItems = (count: number): DoneItem[] => Array.from({ length: count }, (_, index) => ({ at: `2026-10-26T1${index}:00:00+00:00`, time: `1${index}:00`, kind: 'lesson', title: `Sumário — 7.º A (${index + 1})`, class: turma, href: '/lessons/x', count: 1 }));

function mountDashboard(overrides: Partial<DashboardData> = {}): VueWrapper {
    return mount(Dashboard, {
        attachTo: document.body,
        props: {
            teacherName: 'Ana Teste',
            readiness: { is_ready: true, items: [] },
            firstSteps: { dismissed: true, all_done: true, items: [] },
            dashboard: data(overrides),
        },
    });
}

const key = (user = 7, org = 'org-a') => layoutStorageKey(user, org);
const order = (wrapper: VueWrapper) => wrapper.findAll('[data-block]').map((node) => node.attributes('data-block'));
const spans = (wrapper: VueWrapper) => wrapper.findAll('[data-block]').map((node) => (node.classes().includes('@min-[56rem]:col-span-2') ? 'wide' : 'half'));

function baseDay(): Partial<DashboardData> {
    return { day: { available: false, date: '2026-10-26', bounds: null, items: [], totals: { lessons: 0, summaries_registered: 0, sessions: 0, sessions_done: 0, absences_recorded: 0 } } };
}

describe('Dashboard — disposição padrão', () => {
    beforeEach(() => {
        window.localStorage.clear();
        pageProps.auth = { user: { id: 7 }, organization: { ulid: 'org-a' } };
        document.body.innerHTML = '';
    });

    it('a ordem é a de origem e a régua «Hoje» fica fixa antes dos blocos', () => {
        const wrapper = mountDashboard({ done: doneItems(2) });

        expect(order(wrapper)).toEqual(['week', 'done', 'period', 'classes']);

        const html = wrapper.html();

        expect(html.indexOf('data-testid="day-ruler"')).toBeGreaterThan(-1);
        expect(html.indexOf('data-testid="day-ruler"')).toBeLessThan(html.indexOf('data-testid="dashboard-blocks"'));
        // O cabeçalho é anterior à régua.
        expect(html.indexOf('data-testid="hero-cards"')).toBeLessThan(html.indexOf('data-testid="day-ruler"'));
    });

    it('com atividades, Arrumado hoje e o período emparelham (meia largura cada)', () => {
        expect(spans(mountDashboard({ done: doneItems(2) }))).toEqual(['wide', 'half', 'half', 'wide']);
    });

    it('sem atividades, Arrumado hoje ocupa a sua linha e o período, sozinho, também', () => {
        const wrapper = mountDashboard({ done: [] });

        expect(spans(wrapper)).toEqual(['wide', 'wide', 'wide', 'wide']);
        expect(wrapper.find('[data-testid="done-today"]').text()).toContain('0 atividades');
        expect(wrapper.find('[data-testid="done-today"]').text()).toContain('Ainda nada registado hoje.');
    });

    it('na Base não há bloco do período (a régua do período já é a «Hoje») nem lugar para o ordenar', () => {
        pageProps.modules = ['assessments', 'records'];
        const wrapper = mountDashboard(baseDay());

        expect(order(wrapper)).toEqual(['week', 'done', 'classes']);
        expect(wrapper.findAll('[data-testid="period-ruler"]')).toHaveLength(1);
        pageProps.modules = ['lessons', 'assessments', 'records'];
    });

    it('sem turmas não há bloco das turmas', () => {
        expect(order(mountDashboard({ classes: [] }))).toEqual(['week', 'done', 'period']);
    });

    it('Arrumado hoje com mais de três linhas continua a abrir o resto do dia', async () => {
        const wrapper = mountDashboard({ done: doneItems(5) });
        const section = wrapper.find('[data-testid="done-today"]');

        expect(section.findAll('li')).toHaveLength(3);
        expect(section.text()).toContain('5 atividades · as 3 mais recentes');

        await section.find('[data-testid="done-toggle"]').trigger('click');

        expect(section.findAll('li')).toHaveLength(5);
    });
});

describe('Dashboard — Organizar painel', () => {
    beforeEach(() => {
        window.localStorage.clear();
        pageProps.auth = { user: { id: 7 }, organization: { ulid: 'org-a' } };
        pageProps.modules = ['lessons', 'assessments', 'records'];
        document.body.innerHTML = '';
    });

    it('o botão vive no rodapé de ligações, fechado por omissão e com aria-expanded', () => {
        const wrapper = mountDashboard();
        const button = wrapper.find('[data-testid="layout-open"]');

        expect(button.exists()).toBe(true);
        expect(button.element.closest('p')?.textContent).toContain('Minha atividade');
        expect(button.attributes('aria-expanded')).toBe('false');
        expect(wrapper.find('[data-testid="layout-editor"]').exists()).toBe(false);
    });

    it('não aparece enquanto o ano está por preparar', () => {
        const wrapper = mount(Dashboard, {
            props: { teacherName: 'Ana', readiness: { is_ready: false, items: [] }, firstSteps: { dismissed: true, all_done: true, items: [] }, dashboard: data() },
        });

        expect(wrapper.find('[data-testid="layout-open"]').exists()).toBe(false);
    });

    it('a lista tem os quatro blocos e nunca «Hoje»', async () => {
        const wrapper = mountDashboard();
        await wrapper.find('[data-testid="layout-open"]').trigger('click');

        const items = wrapper.find('[data-testid="layout-editor"]').findAll('ol > li').map((li) => li.text());

        expect(items).toHaveLength(4);
        expect(items.join(' ')).toContain('Esta semana');
        expect(items.join(' ')).not.toMatch(/Hoje/);
        expect(wrapper.find('[data-testid="layout-open"]').attributes('aria-expanded')).toBe('true');
    });

    it('mover mostra a pré-visualização na hora mas só «Guardar» persiste', async () => {
        const wrapper = mountDashboard({ done: doneItems(2) });
        await wrapper.find('[data-testid="layout-open"]').trigger('click');
        await wrapper.find('[data-move="classes:-1"]').trigger('click');
        await wrapper.find('[data-move="classes:-1"]').trigger('click');
        await wrapper.find('[data-move="classes:-1"]').trigger('click');

        expect(order(wrapper)).toEqual(['classes', 'week', 'done', 'period']);
        expect(window.localStorage.getItem(key())).toBeNull();
        expect(wrapper.find('[data-testid="layout-live"]').text()).toContain('As minhas turmas: posição 1 de 4');

        await wrapper.find('[data-testid="layout-save"]').trigger('click');

        expect(wrapper.find('[data-testid="layout-editor"]').exists()).toBe(false);
        expect(JSON.parse(window.localStorage.getItem(key()) ?? 'null')).toEqual(['classes', 'week', 'done', 'period']);
        expect(order(wrapper)).toEqual(['classes', 'week', 'done', 'period']);
    });

    it('«Cancelar» restaura o que estava guardado', async () => {
        window.localStorage.setItem(key(), JSON.stringify(['period', 'week', 'done', 'classes']));
        const wrapper = mountDashboard({ done: doneItems(2) });
        await wrapper.find('[data-testid="layout-open"]').trigger('click');
        await wrapper.find('[data-move="classes:-1"]').trigger('click');

        expect(order(wrapper)).toEqual(['period', 'week', 'classes', 'done']);

        await wrapper.find('[data-testid="layout-cancel"]').trigger('click');

        expect(order(wrapper)).toEqual(['period', 'week', 'done', 'classes']);
        expect(JSON.parse(window.localStorage.getItem(key()) ?? 'null')).toEqual(['period', 'week', 'done', 'classes']);
    });

    it('«Repor disposição original» só muda a pré-visualização; persiste depois de «Guardar»', async () => {
        window.localStorage.setItem(key(), JSON.stringify(['classes', 'week', 'done', 'period']));
        const wrapper = mountDashboard({ done: doneItems(2) });
        await wrapper.find('[data-testid="layout-open"]').trigger('click');
        await wrapper.find('[data-testid="layout-reset"]').trigger('click');

        expect(order(wrapper)).toEqual(['week', 'done', 'period', 'classes']);
        expect(JSON.parse(window.localStorage.getItem(key()) ?? 'null')).toEqual(['classes', 'week', 'done', 'period']);

        await wrapper.find('[data-testid="layout-cancel"]').trigger('click');

        expect(order(wrapper)).toEqual(['classes', 'week', 'done', 'period']);

        await wrapper.find('[data-testid="layout-open"]').trigger('click');
        await wrapper.find('[data-testid="layout-reset"]').trigger('click');
        await wrapper.find('[data-testid="layout-save"]').trigger('click');

        expect(JSON.parse(window.localStorage.getItem(key()) ?? 'null')).toEqual(['week', 'done', 'period', 'classes']);
    });

    it('a ordem guardada persiste ao voltar a abrir o painel (nova montagem)', () => {
        window.localStorage.setItem(key(), JSON.stringify(['classes', 'week', 'done', 'period']));

        expect(order(mountDashboard({ done: doneItems(2) }))).toEqual(['classes', 'week', 'done', 'period']);
    });

    it('uma ordem invulgar (turmas primeiro, período antes da semana) nunca deixa meia linha vazia', () => {
        window.localStorage.setItem(key(), JSON.stringify(['classes', 'period', 'week', 'done']));
        const wrapper = mountDashboard({ done: doneItems(2) });

        expect(order(wrapper)).toEqual(['classes', 'period', 'week', 'done']);
        // período e arrumado ficam separados pela semana: cada um, sozinho, ocupa a linha toda
        expect(spans(wrapper)).toEqual(['wide', 'wide', 'wide', 'wide']);
    });

    it('separa por utilizador e por organização', () => {
        window.localStorage.setItem(key(7, 'org-a'), JSON.stringify(['classes', 'week', 'done', 'period']));

        expect(order(mountDashboard({ done: doneItems(2) }))).toEqual(['classes', 'week', 'done', 'period']);

        pageProps.auth = { user: { id: 7 }, organization: { ulid: 'org-b' } };
        expect(order(mountDashboard({ done: doneItems(2) }))).toEqual(['week', 'done', 'period', 'classes']);

        pageProps.auth = { user: { id: 8 }, organization: { ulid: 'org-a' } };
        expect(order(mountDashboard({ done: doneItems(2) }))).toEqual(['week', 'done', 'period', 'classes']);
    });

    it('preferências inválidas voltam à ordem de origem, sem rebentar', () => {
        for (const raw of ['{corrompido', '"week"', 'null', '[1,2,3]', '["today","xpto"]']) {
            window.localStorage.setItem(key(), raw);

            expect(order(mountDashboard({ done: doneItems(2) }))).toEqual(['week', 'done', 'period', 'classes']);
        }
    });

    it('um bloco indisponível mantém a posição guardada para quando voltar', async () => {
        window.localStorage.setItem(key(), JSON.stringify(['classes', 'period', 'week', 'done']));
        pageProps.modules = ['assessments', 'records'];
        const base = mountDashboard({ ...baseDay(), done: doneItems(2) });

        expect(order(base)).toEqual(['classes', 'week', 'done']);

        await base.find('[data-testid="layout-open"]').trigger('click');
        await base.find('[data-move="week:-1"]').trigger('click');
        await base.find('[data-testid="layout-save"]').trigger('click');

        // «period» não estava à vista: continua no 2.º lugar; os visíveis ocupam os outros
        expect(JSON.parse(window.localStorage.getItem(key()) ?? 'null')).toEqual(['week', 'period', 'classes', 'done']);

        pageProps.modules = ['lessons', 'assessments', 'records'];

        expect(order(mountDashboard({ done: doneItems(2) }))).toEqual(['week', 'period', 'classes', 'done']);
    });

    it('com o storage bloqueado a interface continua a funcionar na sessão', async () => {
        const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        const wrapper = mountDashboard({ done: doneItems(2) });

        expect(order(wrapper)).toEqual(['week', 'done', 'period', 'classes']);

        await wrapper.find('[data-testid="layout-open"]').trigger('click');
        await wrapper.find('[data-move="classes:-1"]').trigger('click');
        await wrapper.find('[data-testid="layout-save"]').trigger('click');

        expect(order(wrapper)).toEqual(['week', 'done', 'classes', 'period']);

        getItem.mockRestore();
        setItem.mockRestore();
    });

    it('«Escape» cancela e o foco volta ao botão do rodapé', async () => {
        const wrapper = mountDashboard();
        await wrapper.find('[data-testid="layout-open"]').trigger('click');
        await flushPromises();

        expect(document.activeElement?.id).toBe('organizar-painel');

        await wrapper.find('[data-testid="layout-editor"]').trigger('keydown', { key: 'Escape' });
        await flushPromises();

        expect(wrapper.find('[data-testid="layout-editor"]').exists()).toBe(false);
        expect(document.activeElement).toBe(wrapper.find('[data-testid="layout-open"]').element);
    });

    it('depois de mover o foco fica no mesmo botão, ou no oposto se este se desativou', async () => {
        const wrapper = mountDashboard({ done: doneItems(2) });
        await wrapper.find('[data-testid="layout-open"]').trigger('click');
        await wrapper.find('[data-move="done:-1"]').trigger('click');
        await flushPromises();

        // «done» já é o 1.º: o «Subir» ficou desativado, o foco passa ao «Descer»
        expect((document.activeElement as HTMLElement).getAttribute('data-move')).toBe('done:1');
    });
});
