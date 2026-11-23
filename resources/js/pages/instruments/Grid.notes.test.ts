import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import Grid from './Grid.vue';

const inertia = vi.hoisted(() => ({ put: vi.fn(), post: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { post: inertia.post, put: inertia.put },
    useForm: () => ({ reason: '', errors: {}, processing: false, reset: vi.fn(), clearErrors: vi.fn(), post: vi.fn() }),
    usePage: () => ({ props: { auth: { user: { id: 17 }, organization: { ulid: 'organization-a' } } } }),
}));

// reka-ui mede o gatilho do tooltip com um ResizeObserver, que o jsdom não tem.
vi.stubGlobal(
    'ResizeObserver',
    class {
        observe = vi.fn();
        unobserve = vi.fn();
        disconnect = vi.fn();
    },
);

const items = [
    { id: 100, group_index: 0, code: 'Q1', label: null, criterion_note: null, points_possible: 5, is_bonus: false, domains: [] },
    { id: 101, group_index: 0, code: 'Q2', label: null, criterion_note: 'Audibilidade 5 + dicção 10', points_possible: 5, is_bonus: false, domains: [] },
];

function build(instrument: Record<string, unknown> = {}, overrides: Record<string, unknown> = {}) {
    return mount(Grid, {
        attachTo: document.body,
        props: {
            instrument: {
                ulid: 'i', title: 'Ficha', applied_on: '2026-10-20', status_label: 'Em correção', is_completed: false, can_complete: false,
                pending_count: 0, applicable_count: 1, completed_count: 0, completed_at: null, pending_students: [], status: 'applied',
                cancellation_reason: null, total_points: 10, class_label: '7.º A', class_ulid: 'c', period: '1.º Semestre',
                ...instrument,
            },
            can_edit: true,
            items,
            students: [{ enrollment_id: 1, name: 'Aluno Um', photo_url: null, class_number: 1, enrolled_on: '2026-01-01', is_late_entry: false, joined_after_instrument: false }],
            scores: [],
            states: [
                { value: 'pending', label: 'Por avaliar', carries_value: false, resolves: false },
                { value: 'assessed', label: 'Classificado', carries_value: true, resolves: true },
            ],
            scaleBands: [],
            official: { status: 'provisional', label: 'Classificação provisória', threshold: null, domains: [], students: {} },
            ...overrides,
        },
    });
}

describe('instruments/Grid — comentário por questão', () => {
    beforeEach(() => {
        inertia.put.mockReset();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('só a questão com comentário tem o indicador; sem comentário e sem edição é só o código', () => {
        const wrapper = build({}, { can_edit: false });

        expect(wrapper.findAll('[data-testid="criterion-note-trigger"]')).toHaveLength(1);
        expect(wrapper.get('[data-testid="criterion-note-trigger"]').text()).toBe('Q2');
        expect(wrapper.text()).toContain('Q1');
    });

    it('mostra o comentário quando o código recebe o foco do teclado', async () => {
        const wrapper = build({}, { can_edit: false });

        await wrapper.get('[data-testid="criterion-note-trigger"]').trigger('focus');
        await flushPromises();

        expect(document.body.textContent).toContain('Audibilidade 5 + dicção 10');
    });

    it('quem pode editar abre o editor ao clicar e grava com o PUT certo', async () => {
        const wrapper = build();
        const triggers = wrapper.findAll('[data-testid="criterion-note-trigger"]');

        expect(triggers).toHaveLength(2);
        await triggers[0].trigger('click');
        await flushPromises();

        const input = document.body.querySelector<HTMLTextAreaElement>('[data-testid="criterion-note-input"]')!;
        input.value = '  Nível 4 exige contacto visual  ';
        input.dispatchEvent(new Event('input'));
        await flushPromises();
        document.body.querySelector<HTMLButtonElement>('[data-testid="criterion-note-save"]')!.click();
        await flushPromises();

        expect(inertia.put).toHaveBeenCalledTimes(1);
        expect(inertia.put.mock.calls[0][0]).toBe('/instruments/i/items/notes');
        expect(inertia.put.mock.calls[0][1]).toEqual({ notes: { 100: 'Nível 4 exige contacto visual' } });
        expect(inertia.put.mock.calls[0][2]).toMatchObject({ preserveScroll: true, preserveState: true, only: [] });
    });

    it('«Apagar comentário» envia o texto vazio', async () => {
        const wrapper = build();

        await wrapper.findAll('[data-testid="criterion-note-trigger"]')[1].trigger('click');
        await flushPromises();
        document.body.querySelector<HTMLButtonElement>('[data-testid="criterion-note-delete"]')!.click();
        await flushPromises();

        expect(inertia.put.mock.calls[0][1]).toEqual({ notes: { 101: '' } });
    });

    it('sem can_edit, ou com o elemento anulado, o clique não abre editor', async () => {
        const readOnly = build({}, { can_edit: false });
        await readOnly.get('[data-testid="criterion-note-trigger"]').trigger('click');
        await flushPromises();
        expect(document.body.querySelector('[data-testid="criterion-note-input"]')).toBeNull();
        readOnly.unmount();

        const cancelled = build({ status: 'cancelled' });
        await cancelled.get('[data-testid="criterion-note-trigger"]').trigger('click');
        await flushPromises();
        expect(document.body.querySelector('[data-testid="criterion-note-input"]')).toBeNull();
        expect(cancelled.findAll('[data-testid="criterion-note-trigger"]')).toHaveLength(1);
    });
});
