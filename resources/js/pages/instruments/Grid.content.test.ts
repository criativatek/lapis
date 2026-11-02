import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
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

const groups = [{ ulid: 'g0', label: 'GRUPO I' }, { ulid: 'g1', label: 'GRUPO II' }];

// Três perguntas em dois grupos: «Item 1» é o rótulo por defeito de uma importação (igual ao código).
const items = [
    { id: 100, group_index: 0, code: 'Item 1', label: 'Item 1', points_possible: 5, is_bonus: false, domains: [] },
    { id: 101, group_index: 0, code: 'Item 2', label: 'Inferências', points_possible: 5, is_bonus: false, domains: [] },
    { id: 102, group_index: 1, code: 'Item 3', label: null, points_possible: 10, is_bonus: false, domains: [] },
];

const student = (id: number, name: string) => ({ enrollment_id: id, name, photo_url: null, class_number: id, enrolled_on: '2026-01-01', is_late_entry: false, joined_after_instrument: false });

function build(instrument: Record<string, unknown> = {}, overrides: Record<string, unknown> = {}) {
    return mount(Grid, {
        props: {
            instrument: {
                ulid: 'i', title: 'Ficha', applied_on: '2026-10-20', status_label: 'Em correção', is_completed: false, can_complete: false,
                pending_count: 0, applicable_count: 1, completed_count: 0, completed_at: null, pending_students: [], status: 'applied',
                cancellation_reason: null, total_points: 20, class_label: '7.º A', class_ulid: 'c', period: '1.º Semestre',
                ...instrument,
            },
            groups,
            items,
            students: [student(1, 'Aluno Um')],
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

describe('instruments/Grid — linha «Conteúdo»', () => {
    beforeEach(() => {
        inertia.put.mockReset();
    });

    it('desenha a linha com um input por pergunta e células vazias nos subtotais e no fim', () => {
        const wrapper = build();
        const row = wrapper.get('[data-testid="content-row"]');

        expect(row.get('th').text()).toBe('Conteúdo');
        expect(row.findAll('[data-testid="content-input"]')).toHaveLength(3);
        expect(row.findAll('td').filter((td) => td.text() === '' && td.find('input').exists() === false)).toHaveLength(4);
        expect(row.findAll('td')).toHaveLength(3 + 2 + 2);
    });

    it('começa vazia quando o rótulo é igual ao código ou nulo, e preenchida quando difere', () => {
        const inputs = build().findAll<HTMLInputElement>('[data-testid="content-input"]');

        expect(inputs.map((input) => input.element.value)).toEqual(['', 'Inferências', '']);
        expect(inputs[1].attributes('aria-label')).toBe('Conteúdo avaliado em Item 2');
    });

    it('grava ao sair da célula com alteração, uma pergunta de cada vez', async () => {
        const wrapper = build();
        const input = wrapper.findAll('[data-testid="content-input"]')[0];

        await input.setValue('  Texto narrativo ');
        await input.trigger('blur');

        expect(inertia.put).toHaveBeenCalledTimes(1);
        expect(inertia.put.mock.calls[0][0]).toBe('/instruments/i/items/labels');
        expect(inertia.put.mock.calls[0][1]).toEqual({ labels: { 100: 'Texto narrativo' } });
    });

    it('não grava se nada mudou', async () => {
        const wrapper = build();

        await wrapper.findAll('[data-testid="content-input"]')[1].trigger('blur');

        expect(inertia.put).not.toHaveBeenCalled();
    });

    it('com a correção concluída continua editável', () => {
        const wrapper = build({ is_completed: true });

        expect(wrapper.findAll('[data-testid="content-input"]')).toHaveLength(3);
    });

    it('anulado mostra o conteúdo como texto, sem inputs', () => {
        const wrapper = build({ status: 'cancelled', cancellation_reason: 'Engano' });

        expect(wrapper.findAll('[data-testid="content-input"]')).toHaveLength(0);
        expect(wrapper.findAll('[data-testid="content-text"]').map((cell) => cell.text())).toEqual(['—', 'Inferências', '—']);
    });

    it('os inputs não fazem parte da navegação por setas das classificações', () => {
        const wrapper = build();

        for (const input of wrapper.findAll('[data-testid="content-input"]')) {
            expect(input.attributes('data-cell')).toBeUndefined();
        }
    });
});
