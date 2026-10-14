import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Show from './Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: vi.fn(), post: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
    useForm: (data: Record<string, unknown>) =>
        reactive({
            ...data,
            errors: {},
            processing: false,
            clearErrors: vi.fn(),
            transform: vi.fn(),
            post: vi.fn(),
        }),
}));

/**
 * «Nível atribuído» — a decisão escrita só como valor lê-se na escala em que foi
 * tomada.
 *
 * O caso de produção: uma turma passou de «Percentagem (0 a 100)» a «Escala 1 a
 * 5», e a única decisão publicada é um 65. Aparecia «65» numa coluna de nível.
 * É 65 %, e diz-se de que escala veio. Os outros casos não mudam: um nível é o
 * seu código, um valor em escala de intervalo é o número, sem «%».
 */

type DecisionOverrides = {
    code: string;
    label: string | null;
    scale_level_id: number | null;
    is_percentage?: boolean;
    scale_name?: string | null;
};

function mountWith(decision: DecisionOverrides | null, classifiesByLevel: boolean) {
    return mount(Show, {
        props: {
            schoolClass: {
                ulid: 'cls-1',
                label: '7.º A',
                subject: 'Português',
                has_profile: true,
                scale_name: classifiesByLevel ? 'Escala 1 a 5' : 'Escala 0 a 20',
                decision_label: classifiesByLevel ? 'Nível atribuído' : 'Classificação atribuída',
                classifies_by_level: classifiesByLevel,
                levels: [],
                min_value: null,
                max_value: null,
            },
            periods: [{ ulid: 'per-1', label: '1.º Semestre', selected: true }],
            rows: [
                {
                    enrollment_ulid: 'enr-1',
                    name: 'Carolina Nunes',
                    photo_url: null,
                    class_number: 1,
                    weighted_average: '78.000000',
                    self_assessment: null,
                    classification: {
                        ulid: 'cla-1',
                        status: 'published',
                        status_label: 'Publicada',
                        proposal: { value: '4', state: 'resolved', is_percentage: false },
                        proposed_scale_level_id: 4,
                        decision,
                        overridden: false,
                        observation: null,
                        can_confirm: false,
                        can_change: false,
                        is_published: true,
                    },
                },
            ],
        },
    });
}

function decisionCell(wrapper: ReturnType<typeof mountWith>) {
    const cell = wrapper.find('span.ink-settle');

    expect(cell.exists()).toBe(true);

    return cell;
}

describe('classifications/Show — a decisão lê-se na escala em que foi tomada', () => {
    it('um valor decidido em percentagem lê-se «65%» e nunca «65» numa coluna de nível', () => {
        const wrapper = mountWith(
            { code: '65.000', label: null, scale_level_id: null, is_percentage: true, scale_name: 'Percentagem (0 a 100)' },
            true,
        );

        const cell = decisionCell(wrapper);

        expect(cell.text()).toBe('65%');
        expect(cell.text()).not.toBe('65');
        expect(cell.text()).not.toContain('65.000');
    });

    it('diz de que escala veio quando difere da da turma hoje', () => {
        const wrapper = mountWith(
            { code: '65.000', label: null, scale_level_id: null, is_percentage: true, scale_name: 'Percentagem (0 a 100)' },
            true,
        );

        expect(decisionCell(wrapper).attributes('title')).toBe(
            '65% — Decidida na escala «Percentagem (0 a 100)» (versão anterior do perfil)',
        );
    });

    it('uma turma que nunca migrou lê 65 % sem nota de escala', () => {
        const wrapper = mountWith({ code: '65.000', label: null, scale_level_id: null, is_percentage: true, scale_name: null }, false);

        const cell = decisionCell(wrapper);

        expect(cell.text()).toBe('65%');
        expect(cell.attributes('title')).toBe('65%');
    });

    it('um valor numa escala de intervalo continua a ser só o número, sem «%»', () => {
        const wrapper = mountWith({ code: '16.000', label: null, scale_level_id: null, is_percentage: false, scale_name: null }, false);

        const cell = decisionCell(wrapper);

        expect(cell.text()).toBe('16');
        expect(cell.text()).not.toContain('%');
        expect(cell.attributes('title')).toBe('16');
    });

    it('um nível continua a ser o seu código, com a menção no título', () => {
        const wrapper = mountWith({ code: '4', label: 'Bom', scale_level_id: 4, is_percentage: false, scale_name: null }, true);

        const cell = decisionCell(wrapper);

        expect(cell.text()).toBe('4');
        expect(cell.attributes('title')).toBe('4 — Bom');
    });

    it('um payload sem as chaves novas (servidor antigo) lê-se como antes', () => {
        const wrapper = mountWith({ code: '16.000', label: null, scale_level_id: null }, false);

        expect(decisionCell(wrapper).text()).toBe('16');
    });
});
