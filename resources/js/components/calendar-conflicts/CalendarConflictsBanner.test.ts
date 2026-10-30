import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import CalendarConflictsBanner from './CalendarConflictsBanner.vue';

vi.mock('@/components/ui/dialog', () => {
    const passthrough = defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) });

    return {
        Dialog: defineComponent({
            props: { open: { type: Boolean, default: false } },
            setup: (props, { slots }) => () => (props.open ? h('div', slots.default?.()) : null),
        }),
        DialogContent: passthrough,
        DialogDescription: passthrough,
        DialogFooter: passthrough,
        DialogHeader: passthrough,
        DialogTitle: passthrough,
    };
});

const wrappers: VueWrapper[] = [];

const REVIEW = {
    ulid: 'lesson-c',
    date: '2026-12-22',
    time: '09:30',
    class: 'c1',
    class_label: '8.º A',
    group: null,
    reasons: [{ code: 'taught', label: 'Aula já lecionada.' }],
    exception_titles: ['Interrupção de Natal'],
};

function conflicts(overrides: Record<string, unknown> = {}) {
    return {
        academic_year: { ulid: 'y', label: '2026/2027' },
        can_regularize: true,
        counts: { A: 2, B: 1, C: 1 },
        classes: [],
        review_lessons: [REVIEW],
        ...overrides,
    };
}

function plan(overrides: Record<string, unknown> = {}) {
    return {
        plan_token: 'b'.repeat(64),
        scope: 'teacher',
        operation: 'regularize',
        counts: { A: 2, B: 1, C: 1 },
        totals: { retired: 2, shifted: 1, awaiting_teacher: 0, flagged: 1 },
        classes: [
            {
                class: 'c1',
                class_label: '8.º A',
                retired: 2,
                shifted: 1,
                awaiting_teacher: 0,
                flagged: 1,
                renumbered: 0,
                gaps: [6],
                planning: [{ origin: 'retired', from_date: '2026-12-23', to_date: '2027-01-05', new_lesson: false, pending: false }],
                lessons: [
                    { ulid: 'a1', date: '2026-12-21', time: '09:30', group: null, kind: 'A', retired: true, reasons: [], exception_titles: [], moves: [], pending: false },
                    { ulid: 'a2', date: '2026-12-22', time: '09:30', group: null, kind: 'A', retired: true, reasons: [], exception_titles: [], moves: [], pending: false },
                    {
                        ulid: 'b1',
                        date: '2026-12-23',
                        time: '09:30',
                        group: null,
                        kind: 'B',
                        retired: true,
                        reasons: [],
                        exception_titles: [],
                        moves: [{ to_lesson: 'x', to_date: '2027-01-05', to_time: '09:30', new_lesson: false, fields: ['content'] }],
                        pending: false,
                    },
                    {
                        ulid: 'lesson-c',
                        date: '2026-12-22',
                        time: '09:30',
                        group: null,
                        kind: 'C',
                        retired: false,
                        reasons: [{ code: 'taught', label: 'Aula já lecionada.' }],
                        exception_titles: ['Interrupção de Natal'],
                        moves: [],
                        pending: false,
                    },
                ],
            },
        ],
        gaps: [],
        freed_days: [],
        existing_on_freed_days: { count: 0 },
        changes_anything: true,
        ...overrides,
    };
}

type Route = { status?: number; body: unknown };

/** Um servidor de brincar: responde por «MÉTODO url». */
function stubServer(routes: Record<string, Route | (() => Route)>) {
    const fetchMock = vi.fn((url: string, init?: RequestInit) => {
        const key = `${init?.method ?? 'GET'} ${url}`;
        const route = routes[key];

        if (route === undefined) {
            return Promise.reject(new Error(`unexpected ${key}`));
        }

        const { status = 200, body } = typeof route === 'function' ? route() : route;

        return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
    });

    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function mountBanner() {
    const wrapper = mount(CalendarConflictsBanner, { attachTo: document.body });
    wrappers.push(wrapper);
    await flushPromises();

    return wrapper;
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('CalendarConflictsBanner', () => {
    it('draws nothing when there are no conflicts', async () => {
        stubServer({ 'GET /lessons/calendar-conflicts': { body: conflicts({ counts: { A: 0, B: 0, C: 0 }, review_lessons: [] }) } });

        const wrapper = await mountBanner();

        expect(wrapper.find('[data-testid="calendar-conflicts"]').exists()).toBe(false);
    });

    it('draws nothing, silently, when the request fails', async () => {
        stubServer({ 'GET /lessons/calendar-conflicts': { status: 500, body: null } });

        const wrapper = await mountBanner();

        expect(wrapper.find('[data-testid="calendar-conflicts"]').exists()).toBe(false);
    });

    it('draws nothing, silently, when the network is down', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new Error('offline'))));

        const wrapper = await mountBanner();

        expect(wrapper.find('[data-testid="calendar-conflicts"]').exists()).toBe(false);
    });

    it('announces the lessons in non-teaching days, with the interruption titles', async () => {
        stubServer({ 'GET /lessons/calendar-conflicts': { body: conflicts() } });

        const wrapper = await mountBanner();
        const alert = wrapper.get('[data-testid="calendar-conflicts-alert"]');

        expect(alert.text()).toContain('Há 4 aulas das tuas turmas em dias sem aulas (Interrupção de Natal)');
        expect(wrapper.get('[data-testid="calendar-conflicts-open"]').text()).toBe('Ver e corrigir');
    });

    it('opens the regularize dialog with the three sections, per class, and without any pedagogical text', async () => {
        stubServer({
            'GET /lessons/calendar-conflicts': { body: conflicts() },
            'POST /lessons/calendar-conflicts/preview': { body: plan() },
        });

        const wrapper = await mountBanner();
        await wrapper.get('[data-testid="calendar-conflicts-open"]').trigger('click');
        await flushPromises();

        const text = wrapper.text();

        expect(text).toContain('Vai ser corrigido');
        expect(text).toContain('2 aulas vazias são retiradas: não têm nada escrito');
        expect(text).toContain('O planeamento passa para a aula seguinte');
        expect(text).toContain('1 aula com planeamento é retirada. O planeamento não se apaga');
        expect(text).toMatch(/O planeamento de .*23\/12 passa para .*05\/01\./);
        expect(text).toContain('Atenção à numeração');
        expect(text).toContain('A lição 6 fica sem aula.');
        expect(text).toContain('As aulas por dar ocupam o lugar das retiradas; as lecionadas mantêm o número.');
        expect(text).toContain('Precisa de revisão');
        expect(text).toContain('Aulas para rever uma a uma');
        expect(text).toContain('Aula já lecionada.');
        expect(wrapper.get('[data-testid="regularize-review-item"] a').attributes('href')).toBe('/lessons/lesson-c');
        expect(text).not.toMatch(/plan_token|materializ|ocorrência|\bA\/B\/C\b/i);
    });

    it('says a plan with no next lesson stays as a recoverable pending item', async () => {
        const pending = plan();
        pending.classes[0].planning = [
            { origin: 'retired', from_date: '2026-12-23', to_date: '2027-01-05', new_lesson: false, pending: false },
            { origin: 'advanced', from_date: '2027-01-05', to_date: '2027-01-05', new_lesson: false, pending: true },
        ];
        stubServer({
            'GET /lessons/calendar-conflicts': { body: conflicts() },
            'POST /lessons/calendar-conflicts/preview': { body: pending },
        });

        const wrapper = await mountBanner();
        await wrapper.get('[data-testid="calendar-conflicts-open"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="regularize-kept"]').text()).toMatch(
            /O planeamento que já estava na aula de .*05\/01 fica lá guardado como planeamento pendente, porque não há mais aulas no horário\./,
        );
    });

    it('names every interruption of the counted lessons and keeps a 44 px target at every width', async () => {
        stubServer({ 'GET /lessons/calendar-conflicts': { body: conflicts({ exception_titles: ['Ponte', 'Interrupção de Natal'], review_lessons: [] }) } });

        const wrapper = await mountBanner();

        expect(wrapper.get('[data-testid="calendar-conflicts-alert"]').text()).toContain('Há 4 aulas das tuas turmas em dias sem aulas (Ponte, Interrupção de Natal)');
        const classes = wrapper.get('[data-testid="calendar-conflicts-open"]').classes();
        expect(classes).toContain('min-h-11');
        expect(classes.some((name) => name.startsWith('sm:min-h-'))).toBe(false);
    });

    it('stops promising a correction when only lessons to review are left', async () => {
        const fetchMock = stubServer({
            'GET /lessons/calendar-conflicts': { body: conflicts({ counts: { A: 0, B: 0, C: 1 } }) },
        });

        const wrapper = await mountBanner();
        const alert = wrapper.get('[data-testid="calendar-conflicts-alert"]').text();

        expect(alert).toContain('Há 1 aula das tuas turmas em dias sem aulas (Interrupção de Natal) que precisa da tua revisão');
        expect(wrapper.get('[data-testid="calendar-conflicts-text"]').text()).toContain('Já foi corrigido o que podia ser corrigido automaticamente.');
        // Uma só aula: no singular.
        expect(wrapper.get('[data-testid="calendar-conflicts-text"]').text()).toContain('Esta fica como está: abre-a e decide.');
        expect(alert).not.toContain('Podem ser corrigidas daqui');
        expect(wrapper.get('[data-testid="calendar-conflicts-open"]').text()).toBe('Ver aulas para rever');

        await wrapper.get('[data-testid="calendar-conflicts-open"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Aulas para rever');
        expect(wrapper.find('[data-testid="regularize-review-item"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="regularize-confirm"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="regularize-close"]').text()).toBe('Fechar');
        // Nada a corrigir: não pede pré-visualização.
        expect(fetchMock.mock.calls.some(([url]) => url === '/lessons/calendar-conflicts/preview')).toBe(false);
    });

    it('confirms, shows the server message, reloads the data and tells the page', async () => {
        let conflictCalls = 0;
        const fetchMock = stubServer({
            'GET /lessons/calendar-conflicts': () => {
                conflictCalls++;

                return { body: conflictCalls === 1 ? conflicts() : conflicts({ counts: { A: 0, B: 0, C: 1 } }) };
            },
            'POST /lessons/calendar-conflicts/preview': { body: plan() },
            'POST /lessons/calendar-conflicts/regularize': { body: { ...plan(), message: 'Foram retiradas 2 aulas vazias.' } },
        });

        const wrapper = await mountBanner();
        await wrapper.get('[data-testid="calendar-conflicts-open"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-testid="regularize-confirm"]').trigger('click');
        await flushPromises();

        const regularize = fetchMock.mock.calls.find(([url]) => url === '/lessons/calendar-conflicts/regularize') as unknown as [string, RequestInit];
        expect(JSON.parse(String(regularize[1].body))).toEqual({ plan_token: 'b'.repeat(64) });
        expect(wrapper.get('[data-testid="calendar-conflicts-feedback"]').text()).toBe('Foram retiradas 2 aulas vazias.');
        expect(conflictCalls).toBe(3);
        expect(wrapper.emitted('changed')).toHaveLength(1);
        // O diálogo fechou.
        expect(wrapper.find('[data-testid="regularize-dialog"]').exists()).toBe(false);
    });

    it('explains a stale plan and asks for the preview again', async () => {
        let previews = 0;
        stubServer({
            'GET /lessons/calendar-conflicts': { body: conflicts() },
            'POST /lessons/calendar-conflicts/preview': () => {
                previews++;

                return { body: plan() };
            },
            'POST /lessons/calendar-conflicts/regularize': {
                status: 422,
                body: { errors: { plan_token: ['O calendário ou as aulas mudaram desde a pré-visualização.'] } },
            },
        });

        const wrapper = await mountBanner();
        await wrapper.get('[data-testid="calendar-conflicts-open"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-testid="regularize-confirm"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="regularize-error"]').text()).toContain(
            'O calendário ou as aulas mudaram entretanto. Vê de novo a pré-visualização antes de confirmar.',
        );
        expect(previews).toBe(2);
        expect(wrapper.emitted('changed')).toBeUndefined();
    });

    it('with a closed year shows only the informative list, with no way to apply', async () => {
        const fetchMock = stubServer({
            'GET /lessons/calendar-conflicts': { body: conflicts({ can_regularize: false, counts: { A: 0, B: 0, C: 1 } }) },
        });

        const wrapper = await mountBanner();

        expect(wrapper.get('[data-testid="calendar-conflicts-open"]').text()).toBe('Ver aulas');

        await wrapper.get('[data-testid="calendar-conflicts-open"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="regularize-review-item"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="regularize-confirm"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="regularize-close"]').text()).toBe('Fechar');
        // E nem sequer pede pré-visualização: só o aviso, outra vez, ao abrir.
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls.some(([url]) => url === '/lessons/calendar-conflicts/preview')).toBe(false);
    });

    it('with the lessons module read-only says so, offers only «Ver aulas» and never asks for a preview', async () => {
        const fetchMock = stubServer({
            'GET /lessons/calendar-conflicts': { body: conflicts({ can_regularize: false, regularize_blocked: 'read_only' }) },
        });

        const wrapper = await mountBanner();

        expect(wrapper.get('[data-testid="calendar-conflicts-open"]').text()).toBe('Ver aulas');
        expect(wrapper.get('[data-testid="calendar-conflicts-alert"]').text()).not.toContain('Podem ser corrigidas daqui');

        await wrapper.get('[data-testid="calendar-conflicts-open"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('O plano da organização só permite consultar as aulas');
        expect(wrapper.text()).not.toContain('tenta outra vez');
        expect(fetchMock.mock.calls.some(([url]) => url === '/lessons/calendar-conflicts/preview')).toBe(false);
    });

    it('asks for the warning again when the dialog opens', async () => {
        let calls = 0;
        stubServer({
            'GET /lessons/calendar-conflicts': () => {
                calls++;

                return { body: conflicts() };
            },
            'POST /lessons/calendar-conflicts/preview': { body: plan() },
        });

        const wrapper = await mountBanner();
        expect(calls).toBe(1);

        await wrapper.get('[data-testid="calendar-conflicts-open"]').trigger('click');
        await flushPromises();

        expect(calls).toBe(2);
    });

    it('closes through «Cancelar» without writing anything', async () => {
        const fetchMock = stubServer({
            'GET /lessons/calendar-conflicts': { body: conflicts() },
            'POST /lessons/calendar-conflicts/preview': { body: plan() },
        });

        const wrapper = await mountBanner();
        await wrapper.get('[data-testid="calendar-conflicts-open"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-testid="regularize-close"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="regularize-dialog"]').exists()).toBe(false);
        expect(fetchMock.mock.calls.some(([url]) => url === '/lessons/calendar-conflicts/regularize')).toBe(false);
    });
});
