import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import type { SelfAssessmentQuestion } from '@/types/self-assessment';
import PublicEdit from './PublicEdit.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    useForm: <T extends object>(data: T) => reactive({ ...data, errors: {}, processing: false, post: vi.fn() }),
}));

// The signed page wears the guest layout; the page itself is what is under test.
vi.mock('@/layouts/auth/AuthSimpleLayout.vue', () => ({ default: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }) }));

const question: SelfAssessmentQuestion = {
    id: 1,
    block: 'performance',
    role: 'global',
    domain: null,
    prompt: 'Classificação que proponho para a minha avaliação neste período',
    answer_kind: 'scale',
    levels: [],
    scale: 'Percentagem (0 a 100)',
    answer_level_id: null,
    answer_text: null,
    answer: { mode: 'number', levels: [], min: '0', max: '100', step: '1', decimals: 0, unit: '%', instruction: 'Escreve uma percentagem de 0 a 100, sem casas decimais.', problem: null },
    answer_number: null,
};

function mountWith(overrides: Record<string, unknown> = {}) {
    return mount(PublicEdit, {
        props: {
            schoolClass: { ulid: 'class-1', label: '8.º A' },
            period: { ulid: 'period-1', label: '1.º Semestre' },
            student: 'Aluno de teste',
            template: 'TEMPLATE-ULID',
            criteria: {
                scale: 'Percentagem (0 a 100)',
                domains: [
                    { name: 'Conhecimento', weight: '60' },
                    { name: 'Atitudes', weight: '12.5' },
                ],
            },
            hasEarlierRound: false,
            questions: [question],
            earlierReflection: null,
            status: null,
            submitUrl: '/signed',
            ...overrides,
        },
    });
}

describe('self-assessments/PublicEdit — what the student is told', () => {
    it('explains how the subject is assessed: domains, weights and the scale — no profile, no version', () => {
        const section = mountWith().get('[data-test="how-assessed"]');

        expect(section.text()).toContain('Como é feita a avaliação nesta disciplina');
        expect(section.text()).toContain('Conhecimento — 60%');
        expect(section.text()).toContain('Atitudes — 12,5%');
        expect(section.text()).toContain('Respondes na escala da turma: Percentagem (0 a 100).');
        expect(section.text()).not.toMatch(/perfil|versão/i);
    });

    it('never shows the technical configuration to the student', () => {
        expect(mountWith().text()).not.toMatch(/perfil|versão/i);
    });

    it('has no criteria block when the server sends none', () => {
        expect(mountWith({ criteria: null }).find('[data-test="how-assessed"]').exists()).toBe(false);
    });

    it('says an earlier answer was kept and this one is new', () => {
        expect(mountWith({ hasEarlierRound: true }).get('[data-test="earlier-round-note"]').text()).toBe(
            'Já tinhas respondido a uma autoavaliação deste período com critérios anteriores. Essa resposta ficou guardada; esta é uma nova autoavaliação.',
        );
    });

    it('has no earlier-round note otherwise', () => {
        expect(mountWith().find('[data-test="earlier-round-note"]').exists()).toBe(false);
    });

    it('asks the percentage in a text field with the student phrase under the global question', () => {
        const wrapper = mountWith();

        expect(wrapper.find('select').exists()).toBe(false);
        expect(wrapper.get('input[type="text"]').attributes('inputmode')).toBe('decimal');
        expect(wrapper.text()).toContain('É a tua apreciação pessoal da classificação que consideras merecer.');
    });
});
