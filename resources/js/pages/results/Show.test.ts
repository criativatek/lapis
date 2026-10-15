import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import type { SelfAssessmentReading } from '@/lib/selfAssessmentReading';
import Show from './Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: vi.fn(), post: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
    useForm: (data: Record<string, unknown>) => reactive({ ...data, errors: {}, processing: false, clearErrors: vi.fn(), transform: vi.fn(), post: vi.fn() }),
}));

/**
 * A coluna «Autoavaliação» de Resultados: o valor que o aluno propôs, na escala em
 * que o propôs. Uma leitura de outros critérios nunca toma a cor da escala de hoje.
 */

const criteria = 'Avaliação final · versão 1 · Percentagem (0 a 100)';

function mountWith(selfAssessment: SelfAssessmentReading) {
    return mount(Show, {
        props: {
            schoolClass: { ulid: 'cls-1', label: '7.º A', subject: 'Português', has_profile: true, scale_name: 'Escala 1 a 5' },
            periods: [{ ulid: 'per-1', label: '1.º Semestre', selected: true }],
            domains: [],
            weightedAverageLabel: 'Média Ponderada',
            isFirstPeriod: true,
            scaleBands: [
                { label: 'Fraco', sequence: 1, is_negative: true },
                { label: 'Bom', sequence: 2, is_negative: false },
                { label: 'Muito Bom', sequence: 3, is_negative: false },
            ],
            decision: { label: 'Nível atribuído', classifies_by_level: true, levels: [], min_value: null, max_value: null },
            ai: { available: false, reason: 'plan', has_enough_evidence: true, minimum_students: 3, action: '/x' },
            aiAnalysis: null,
            aiAnalysisError: null,
            rows: [
                {
                    enrollment_ulid: 'enr-1',
                    name: 'Carolina Nunes',
                    photo_url: null,
                    class_number: 1,
                    overall: '78.000000',
                    proposal: { value: '4', state: 'resolved', is_percentage: false },
                    has_value: true,
                    coverage_warning: false,
                    coverage: { absences: [], no_elements: false, excluded_domain_ids: [] },
                    domains: [],
                    evolution: null,
                    accumulated: null,
                    self_assessment: selfAssessment,
                    classification: null,
                },
            ],
        },
    });
}

describe('results/Show — autoavaliação', () => {
    it('mostra o valor do aluno com a cor da banda quando é dos critérios atuais, sem legenda', () => {
        const wrapper = mountWith({ code: '3', label: 'Muito Bom', display: '3', sequence: 3, is_negative: false, previous_criteria: false, earlier: false });
        const value = wrapper.get('[data-test="self-assessment-value"]');

        expect(value.text()).toBe('3');
        expect(value.classes().join(' ')).toContain('emerald');
        expect(wrapper.find('[data-test="self-assessment-legend"]').exists()).toBe(false);
    });

    it('marca «(ant.)», não dá cor e explica a marca quando foi respondida com critérios anteriores', () => {
        const wrapper = mountWith({ code: '70', label: '70%', display: '70%', sequence: null, is_negative: false, scale: 'Percentagem (0 a 100)', previous_criteria: true, criteria, earlier: false });
        const value = wrapper.get('[data-test="self-assessment-value"]');

        expect(value.text()).toBe('70% (ant.)');
        expect(value.attributes('aria-label')).toBe(`Autoavaliação do aluno: 70% (Percentagem (0 a 100)) — respondida com critérios anteriores: ${criteria}`);
        expect(value.classes().join(' ')).not.toMatch(/emerald|red-|blue-|amber-/);
        expect(wrapper.get('[data-test="self-assessment-legend"]').text()).toContain('(ant.) — ');
    });

    it('marca como anterior a proposta de uma autoavaliação anterior, mesmo na escala de hoje', () => {
        const wrapper = mountWith({ code: '3', label: 'Muito Bom', display: '3', sequence: 3, is_negative: false, previous_criteria: false, criteria: null, earlier: true });
        const value = wrapper.get('[data-test="self-assessment-value"]');

        expect(value.text()).toBe('3 (ant.)');
        expect(value.attributes('aria-label')).toContain('A nova autoavaliação não tem proposta global. Proposta anterior: 3');
        expect(value.classes().join(' ')).not.toContain('emerald');
    });

    it('escreve «—» quando o aluno não respondeu', () => {
        const wrapper = mountWith(null);

        expect(wrapper.find('[data-test="self-assessment-value"]').exists()).toBe(false);
        expect(wrapper.find('td span[title="O aluno não respondeu à autoavaliação global deste período."]').text()).toBe('—');
    });
});
