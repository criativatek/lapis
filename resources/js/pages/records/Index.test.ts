import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { ConsultationFilters, Tile } from '@/lib/recordFollowup';
import Index from './Index.vue';

const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    router: { get: routerGet, post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: { errors: {} }, url: '/records?year=Y1&period=P1' }),
}));

// The «Registar» menu is a reka portal; its items are not what these tests read.
vi.mock('@/components/ui/dropdown-menu', () => {
    const passThrough = defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) });

    return { DropdownMenu: passThrough, DropdownMenuContent: passThrough, DropdownMenuItem: passThrough, DropdownMenuTrigger: passThrough };
});

const wrappers: VueWrapper[] = [];

function filters(overrides: Partial<ConsultationFilters> = {}): ConsultationFilters {
    return {
        year: 'Y1', subject: null, class: null, student: null, kind: null,
        mode: 'period', period: 'P1', from: null, to: null, searched: false, view: null, tab: 'search',
        ...overrides,
    };
}

function tiles(): Tile[] {
    return [
        { category: 'homework_not_done', label: 'TPC não realizados', unit: 'occurrence', students: 0, cases: 0, occurrences: 0, pending: 0 },
        { category: 'lateness', label: 'Atrasos', unit: 'occurrence', students: 6, cases: 6, occurrences: 14, pending: 2 },
        { category: 'missing_material', label: 'Faltas de material', unit: 'occurrence', students: 1, cases: 1, occurrences: 1, pending: 0 },
    ];
}

function props(overrides: Record<string, unknown> = {}) {
    return {
        years: [{ ulid: 'Y1', label: '2026/2027' }],
        filters: filters(),
        options: {
            subjects: [{ ulid: 'S1', name: 'Matemática' }],
            classes: [{ ulid: 'C1', label: '7.º A', subject: 'Matemática', subject_ulid: 'S1', archived: false }],
            students: [],
            kinds: [{ value: 'lateness', label: 'Atraso', group_label: 'Comportamento e atitudes' }],
            periods: [{ ulid: 'P1', label: '1.º Período', starts_on: '2026-09-14', ends_on: '2026-12-16', is_current: true }],
        },
        context: {
            has_classes: true, year_label: '2026/2027', scope_label: 'Todas as turmas', student_label: null,
            window_label: '1.º Período (14/09/2026 a 16/12/2026)', period_is_current: true, needs_window: false,
            window_problem: null, periods_notice: null, overlaps: [],
        },
        register: null,
        panorama: null,
        pendingAlerts: 0,
        tiles: tiles(),
        alerts: [],
        results: null,
        followup: null,
        openCase: null,
        can: { confirm: true },
        ...overrides,
    };
}

function mountPage(overrides: Record<string, unknown> = {}) {
    const wrapper = mount(Index, { props: props(overrides) });
    wrappers.push(wrapper);

    return wrapper;
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    routerGet.mockReset();
});

describe('records Index — a consulta', () => {
    it('opens on filters and the summary, never on a list of records', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-test="initial-state"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="results"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="tile-lateness"]').text()).toContain('6 alunos · 14 ocorrências');
        expect(wrapper.find('[data-test="tile-lateness"]').text()).toContain('2 por tratar');
        expect(wrapper.find('[data-test="context"]').text()).toContain('período em curso');
    });

    it('tells an empty search apart from the initial state', () => {
        const wrapper = mountPage({
            filters: filters({ searched: true }),
            results: { data: [], current_page: 1, last_page: 1, total: 0, from: null, to: null, prev_url: null, next_url: null },
        });

        expect(wrapper.find('[data-test="initial-state"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="results"]').text()).toContain('Nenhum registo corresponde a estes filtros.');
    });

    it('asks for a period when today falls in none, without inventing one', () => {
        const wrapper = mountPage({
            filters: filters({ period: null }),
            tiles: [],
            context: { ...props().context, window_label: null, period_is_current: false, needs_window: true },
        });

        expect(wrapper.find('[data-test="needs-window"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="tile-lateness"]').exists()).toBe(false);
        expect((wrapper.find('[data-test="filter-period"]').element as HTMLSelectElement).selectedOptions[0]?.textContent?.trim()).toBe('Escolha um período');
    });

    it('searches only on «Pesquisar», with the filters chosen', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-test="filter-kind"]').setValue('lateness');
        expect(routerGet).not.toHaveBeenCalled();

        await wrapper.find('form').trigger('submit');

        expect(routerGet).toHaveBeenCalledWith('/records', { year: 'Y1', kind: 'lateness', period: 'P1', tab: 'search', searched: '1' }, { preserveScroll: true });
    });

    it('searches by dates in range mode', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-test="mode-range"]').trigger('click');
        await wrapper.find('[data-test="filter-from"]').setValue('2026-10-01');
        await wrapper.find('[data-test="filter-to"]').setValue('2026-10-31');
        await wrapper.find('form').trigger('submit');

        expect(routerGet).toHaveBeenCalledWith('/records', { year: 'Y1', mode: 'range', from: '2026-10-01', to: '2026-10-31', tab: 'search', searched: '1' }, { preserveScroll: true });
    });

    it('clears every filter back to the defaults', async () => {
        await mountPage().find('[data-test="clear"]').trigger('click');

        expect(routerGet).toHaveBeenCalledWith('/records', { tab: 'search' });
    });

    it('keeps the student filter closed until a class is chosen, then loads that roll without applying anything', async () => {
        const wrapper = mountPage();

        expect((wrapper.find('[data-test="filter-student"]').element as HTMLSelectElement).disabled).toBe(true);

        await wrapper.find('[data-test="filter-class"]').setValue('C1');

        expect(routerGet).toHaveBeenCalledWith('/records', { year: 'Y1', class: 'C1', period: 'P1', tab: 'search' }, expect.objectContaining({ only: ['options'], preserveUrl: true }));
    });

    it('opens a direct access in the applied scope', async () => {
        await mountPage().find('[data-test="tile-lateness"]').trigger('click');

        expect(routerGet).toHaveBeenCalledWith('/records', { year: 'Y1', period: 'P1', tab: 'search', view: 'lateness' }, { preserveScroll: true });
    });

    it('lists only the students with occurrences and opens one on demand', async () => {
        const wrapper = mountPage({
            filters: filters({ view: 'lateness' }),
            followup: {
                category: 'lateness',
                label: 'Atrasos',
                unit: 'occurrence',
                rows: {
                    data: [
                        { student_key: 'S1', student_cases: 1, class_ulid: 'C1', class_label: '7.º A', subject: 'Matemática', student_ulid: 'E1', number: 2, name: 'Rui Sousa', occurrences: 4, first_date: '2026-10-01', last_date: '2026-10-09', status: 'pending', origin: 'existing', days: null },
                        { student_key: 'S2', student_cases: 1, class_ulid: 'C1', class_label: '7.º A', subject: 'Matemática', student_ulid: 'E2', number: 1, name: 'Ana Costa', occurrences: 2, first_date: '2026-10-02', last_date: '2026-10-03', status: 'none', origin: null, days: null },
                    ],
                    current_page: 1, last_page: 1, total: 2, from: 1, to: 2, prev_url: null, next_url: null,
                },
            },
        });

        const list = wrapper.find('[data-test="followup-list"]');
        expect(list.findAll('li')).toHaveLength(2);
        expect(list.text()).toContain('Por tratar');
        expect(list.text()).toContain('sem alerta');

        await list.find('li button').trigger('click');

        expect(routerGet).toHaveBeenCalledWith(
            '/records?year=Y1&period=P1&case_class=C1&case_student=E1&case_category=lateness',
            {},
            { only: ['openCase'], preserveState: true, preserveScroll: true },
        );
    });

    it('opens an alert in its own period, with its list behind it', async () => {
        const wrapper = mountPage({
            alerts: [{
                case_ulid: 'K1', class_ulid: 'C1', class_label: '7.º A', subject: 'Matemática', student_ulid: 'E1', number: 5, name: 'Ana Costa',
                category: 'missing_material', category_label: 'Faltas de material', occurrences: 3, period_ulid: 'P0', period_label: '1.º Período', alerted_at: null,
            }],
        });

        expect(wrapper.find('[data-test="alerts"]').text()).toContain('n.º 5 · Ana Costa');

        await wrapper.find('[data-test="alerts"] button').trigger('click');

        expect(routerGet).toHaveBeenCalledWith('/records', {
            year: 'Y1', period: 'P0', tab: 'search', view: 'missing_material', case_class: 'C1', case_student: 'E1', case_category: 'missing_material',
        }, { preserveScroll: true });
    });

    it('says students and cases apart when a student is followed in two classes', () => {
        const wrapper = mountPage({
            tiles: [
                ...tiles().slice(0, 2),
                { category: 'missing_material', label: 'Faltas de material', unit: 'occurrence', students: 1, cases: 2, occurrences: 5, pending: 2 },
            ],
        });

        expect(wrapper.find('[data-test="tile-missing_material"]').text()).toContain('1 aluno · 2 casos · 5 ocorrências');
        expect(wrapper.find('[data-test="tile-lateness"]').text()).toContain('6 alunos · 14 ocorrências');
        expect(wrapper.find('[data-test="cases-hint"]').exists()).toBe(true);
        expect(mountPage().find('[data-test="cases-hint"]').exists()).toBe(false);
    });

    it('marks a line whose student is followed in another class too', () => {
        const row = { student_key: 'S1', student_cases: 2, class_ulid: 'C1', class_label: '7.º A', subject: 'Matemática', student_ulid: 'E1', number: 2, name: 'Rui Sousa', occurrences: 3, first_date: '2026-10-01', last_date: '2026-10-09', status: 'pending', origin: 'alert', days: null };
        const wrapper = mountPage({
            filters: filters({ view: 'lateness' }),
            followup: {
                category: 'lateness',
                label: 'Atrasos',
                unit: 'occurrence',
                rows: { data: [row, { ...row, class_ulid: 'C2', class_label: '7.º A (apoio)', student_ulid: 'E9' }], current_page: 1, last_page: 1, total: 2, from: 1, to: 2, prev_url: null, next_url: null },
            },
        });

        const lines = wrapper.findAll('[data-test="followup-list"] li');
        expect(lines[0].text().replace(/\s+/g, ' ')).toContain('7.º A · Matemática · também noutra turma');
        expect(lines[1].text()).toContain('7.º A (apoio)');
        expect(wrapper.find('[data-test="followup-list"]').text()).toContain('2 casos');
    });

    it('shows the periods notice exactly as worded', () => {
        const notice = 'Para receber alertas de acumulação, configure os períodos de avaliação e confirme as respetivas datas.';

        expect(mountPage({ context: { ...props().context, periods_notice: notice } }).find('[data-test="periods-notice"]').text()).toBe(notice);
    });

    it('opens a result in the class logbook with the way back to this exact consultation', () => {
        const wrapper = mountPage({
            filters: filters({ searched: true }),
            results: {
                data: [{ ulid: 'R1', class_ulid: 'C1', class_label: '7.º A', subject: 'Matemática', date: '2026-10-03', kind: 'lateness', kind_label: 'Atraso', detail_label: null, severity_label: null, student: { number: 5, name: 'Ana Costa' }, description: '' }],
                current_page: 1, last_page: 3, total: 54, from: 1, to: 25, prev_url: null, next_url: '/records?page=2',
            },
        });

        const open = wrapper.find('[data-test="results"] li a');
        expect(open.attributes('href')).toBe('/classes/C1/records?registo=R1&voltar=%2Frecords%3Fyear%3DY1%26period%3DP1');
        expect(wrapper.find('[data-test="results"]').text()).toContain('54 registos · Página 1 de 3');
    });

    it('shows the fourth access, absences, in absences — never as occurrences, never «por tratar»', () => {
        const wrapper = mountPage({
            tiles: [...tiles(), { category: 'absences', label: 'Faltas de presença', unit: 'absence', students: 2, cases: 2, occurrences: 3, pending: null }],
        });

        const tile = wrapper.find('[data-test="tile-absences"]').text();
        expect(tile).toContain('Faltas de presença');
        expect(tile).toContain('2 alunos · 3 faltas');
        expect(tile).not.toContain('por tratar');
    });

    it('lists absences per student with how many days they fall on', () => {
        const wrapper = mountPage({
            filters: filters({ view: 'absences' }),
            followup: {
                category: 'absences',
                label: 'Faltas de presença',
                unit: 'absence',
                rows: {
                    data: [{ student_key: 'S1', student_cases: 1, class_ulid: 'C1', class_label: '7.º A', subject: 'Matemática', student_ulid: 'E1', number: 1, name: 'Ana Costa', occurrences: 3, days: 2, first_date: '2026-10-05', last_date: '2026-10-09', status: null, origin: null }],
                    current_page: 1, last_page: 1, total: 1, from: 1, to: 1, prev_url: null, next_url: null,
                },
            },
        });

        const line = wrapper.find('[data-test="followup-list"] li').text().replace(/\s+/g, ' ');
        expect(line).toContain('3 faltas em 2 dias');
        expect(line).not.toContain('Por tratar');
    });
});

describe('records Index — registar e consultar, em três separadores', () => {
    it('says both purposes and shows the three paths, «Registar TPC» first and in plain sight', () => {
        const wrapper = mountPage({ filters: filters({ tab: 'tpc' }), register: { class: null, date: '2026-10-13', today: '2026-10-13', can_write: false } });

        expect(wrapper.text()).toContain('Regista e consulta informações dos alunos e acompanha os TPC, a assiduidade, os atrasos e as faltas de material.');
        // The full label (the short one replaces it only on a phone, by CSS).
        expect(wrapper.findAll('[role="tab"]').map((tab) => tab.findAll('span').at(-1)?.text())).toEqual(['Registar TPC', 'Panorama da turma', 'Pesquisar registos']);
        expect(wrapper.find('[data-test="tab-tpc"]').attributes('aria-selected')).toBe('true');
        expect(wrapper.find('[data-test="register-tab"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="search-tab"]').exists()).toBe(false);
        // No history of records shown on its own.
        expect(wrapper.find('[data-test="results"]').exists()).toBe(false);
    });

    it('leaves the search’s student and kind behind when going to register or to the panorama', async () => {
        const wrapper = mountPage({ filters: filters({ class: 'C1', student: 'E7', kind: 'lateness', searched: true }) });

        await wrapper.find('[data-test="tab-tpc"]').trigger('click');
        expect(routerGet).toHaveBeenCalledWith('/records', { tab: 'tpc', year: 'Y1', class: 'C1' }, { preserveScroll: true });

        await wrapper.find('[data-test="tab-panorama"]').trigger('click');
        expect(routerGet).toHaveBeenLastCalledWith('/records', { tab: 'panorama', year: 'Y1', class: 'C1', period: 'P1' }, { preserveScroll: true });
    });

    it('points to the alerts still waiting from the other tabs, and keeps creating the other kinds one click away', () => {
        const wrapper = mountPage({ filters: filters({ tab: 'panorama', class: 'C1' }), pendingAlerts: 2 });

        expect(wrapper.find('[data-test="pending-alerts"]').text()).toContain('2 alertas de acumulação por tratar');
        expect(wrapper.find('[data-test="new-record"]').attributes('href')).toBe('/classes/C1/records');

        const search = mountPage({ pendingAlerts: 2 });
        expect(search.find('[data-test="pending-alerts"]').exists()).toBe(false);
        expect(search.find('[data-test="new-record-menu"]').exists()).toBe(true);
    });
});
