import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import Grid from './Grid.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { post: vi.fn() },
    useForm: () => ({ reason: '', errors: {}, processing: false, reset: vi.fn(), clearErrors: vi.fn(), post: vi.fn() }),
    usePage: () => ({ props: { auth: { user: { id: 17 }, organization: { ulid: 'organization-a' } } } }),
}));

/**
 * Uma ficha importada do Intuitivo: cinco grupos (4+4+4+4+1 perguntas), cotações 16/18/20/22/24 = 100.
 * A grelha mostra, por aluno, cada pergunta, o subtotal de cada grupo e o total com a cotação global.
 */
const groups = ['GRUPO I Oralidade', 'GRUPO II - Leitura', 'GRUPO III - Educação literária', 'GRUPO IV - Gramática', 'GRUPO V - Expressão escrita'].map((label, index) => ({ ulid: `g${index}`, label }));
// Cotações por pergunta: 4,4,4,4 | 4,4,4,6 | 5,5,5,5 | 6,7,4,5 | 24
const maxima = [4, 4, 4, 4, 4, 4, 4, 6, 5, 5, 5, 5, 6, 7, 4, 5, 24];
const groupOf = [0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 4];
const items = maxima.map((points, index) => ({
    id: 100 + index,
    group_index: groupOf[index],
    code: `${index + 1}`,
    label: null,
    points_possible: points,
    is_bonus: false,
    domains: [],
}));

const student = (id: number, name: string) => ({ enrollment_id: id, name, photo_url: null, class_number: id, enrolled_on: '2026-01-01', is_late_entry: false, joined_after_instrument: false });
const score = (enrollment: number, itemIndex: number, points: number | null) => ({
    enrollment_id: enrollment,
    instrument_item_id: 100 + itemIndex,
    result_state: points === null ? 'pending' : 'assessed',
    points_earned: points,
    state_reason: null,
    lock_version: 1,
});

function build(overrides: Record<string, unknown> = {}, scores: ReturnType<typeof score>[] = []) {
    return mount(Grid, {
        props: {
            instrument: {
                ulid: 'i', title: 'Ficha de Português', applied_on: '2026-10-20', status_label: 'Em correção', is_completed: false, can_complete: false,
                pending_count: 0, applicable_count: 2, completed_count: 0, completed_at: null, pending_students: [], status: 'applied',
                cancellation_reason: null, total_points: 100, class_label: '7.º A', class_ulid: 'c', period: '1.º Semestre',
            },
            groups,
            items,
            students: [student(1, 'Aluno Um'), student(2, 'Aluno Dois')],
            scores,
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

/** Aluno 1: todas as perguntas com valores de duas casas; aluno 2: nada registado. */
const fullRow = [4, 0, 4, 4, 3.33, 3.33, 3.34, 6, 2.5, 5, 5, 0, 6, 7, 4, 5, 19.5];
const scoresOfOne = fullRow.map((points, index) => score(1, index, points));

describe('instruments/Grid — grupos, subtotais e total', () => {
    it('desenha uma fila com os cinco grupos e a cotação de cada um, e um subtotal no fim de cada grupo', () => {
        const wrapper = build({}, scoresOfOne);

        const headers = wrapper.findAll('[data-testid="group-header"]').map((th) => th.findAll('div').map((line) => line.text()).join('|'));
        expect(headers).toEqual([
            'GRUPO I Oralidade|16 pts',
            'GRUPO II - Leitura|18 pts',
            'GRUPO III - Educação literária|20 pts',
            'GRUPO IV - Gramática|22 pts',
            'GRUPO V - Expressão escrita|24 pts',
        ]);
        expect(wrapper.findAll('[data-testid="subtotal-header"]')).toHaveLength(5);
        // 17 perguntas, cada uma com a sua cotação no cabeçalho.
        expect(wrapper.findAll('thead tr')[1].findAll('th').filter((th) => /pts/.test(th.text()) && !th.attributes('data-testid')).length).toBe(17);
    });

    it('sem cotação global declarada (uma ficha importada) o total usa a soma das cotações: 82/100', () => {
        const wrapper = build({ instrument: { ulid: 'i', title: 'Ficha', applied_on: '2026-10-20', status_label: 'Em correção', is_completed: false, can_complete: false, pending_count: 0, applicable_count: 2, completed_count: 0, completed_at: null, pending_students: [], status: 'applied', cancellation_reason: null, total_points: null, class_label: '7.º A', class_ulid: 'c', period: '1.º Semestre' } }, scoresOfOne);

        expect(wrapper.findAll('tbody tr')[0].get('[data-testid="total-cell"]').text().replace(/\s+/g, '').startsWith('82/100')).toBe(true);
    });

    it('mostra, para o aluno, os cinco subtotais obtidos/máximo (soma exata das classificações, com decimais)', () => {
        const wrapper = build({}, scoresOfOne);

        const row = wrapper.findAll('tbody tr')[0];
        const subtotals = row.findAll('[data-testid="subtotal-cell"]').map((td) => td.text().replace(/\s+/g, ''));

        // 4+0+4+4=12 · 3.33+3.33+3.34+6=16 · 2.5+5+5+0=12.5 · 6+7+4+5=22 · 19.5
        expect(subtotals).toEqual(['12/16', '16/18', '12,5/20', '22/22', '19,5/24']);
    });

    it('o total é a soma dos cinco subtotais, com a cotação global, sem ruído de vírgula flutuante', () => {
        const wrapper = build({}, scoresOfOne);

        const total = wrapper.findAll('tbody tr')[0].get('[data-testid="total-cell"]').text().replace(/\s+/g, '');

        // 12 + 16 + 12,5 + 22 + 19,5 = 82 — e 3,33 + 3,33 + 3,34 não pode aparecer como 9,99999999.
        expect(total.startsWith('82/100')).toBe(true);
    });

    it('mostra cada classificação individual no seu campo, incluindo os zeros reais', () => {
        const wrapper = build({}, scoresOfOne);

        const values = wrapper.findAll('tbody tr')[0].findAll('input[data-cell]').map((input) => (input.element as HTMLInputElement).value);

        expect(values).toEqual(fullRow.map((points) => String(points).replace('.', ',')));
        expect(values.filter((value) => value === '0')).toHaveLength(2);
    });

    it('uma pergunta sem classificação fica vazia (não é zero), e um grupo sem nenhuma mostra «—», não 0', () => {
        const wrapper = build({}, scoresOfOne);

        const empty = wrapper.findAll('tbody tr')[1];
        const inputs = empty.findAll('input[data-cell]').map((input) => (input.element as HTMLInputElement).value);
        expect(inputs.every((value) => value === '')).toBe(true);
        expect(empty.findAll('[data-testid="subtotal-cell"]').map((td) => td.text().trim())).toEqual(['—', '—', '—', '—', '—']);
        expect(empty.get('[data-testid="total-cell"]').text().trim()).toBe('—');
    });

    it('um subtotal parcial soma só o que está classificado (a pergunta em falta não conta como zero)', () => {
        const wrapper = build({}, [score(1, 0, 4), score(1, 1, 3), score(1, 2, null), score(1, 3, null)]);

        const first = wrapper.findAll('tbody tr')[0].findAll('[data-testid="subtotal-cell"]')[0].text().replace(/\s+/g, '');

        expect(first).toBe('7/16');
    });

    it('um teste simples (um só grupo) continua uma só fila de perguntas, sem grupos nem subtotais', () => {
        const wrapper = build({ groups: [{ ulid: 'g', label: null }] }, scoresOfOne.slice(0, 2));

        expect(wrapper.find('[data-testid="group-header-row"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="subtotal-cell"]').exists()).toBe(false);
        expect(wrapper.findAll('thead tr')).toHaveLength(1);
    });
});
