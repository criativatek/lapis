import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import type { SelfAssessmentQuestion } from '@/types/self-assessment';
import Edit from './Edit.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    useForm: <T extends object>(data: T) => reactive({ ...data, errors: {}, processing: false, post: vi.fn() }),
}));

const percentage: SelfAssessmentQuestion = {
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
};

function mountWith(questions: SelfAssessmentQuestion[], currentVersion: number | null, classScale = { scale: 'Percentagem (0 a 100)', scale_has_levels: false }) {
    return mount(Edit, {
        props: {
            schoolClass: { ulid: 'class-1', label: '8.º A' },
            period: { ulid: 'period-1', label: '1.º Semestre' },
            student: 'Aluno de teste',
            enrollmentUlid: 'enrollment-1',
            questions,
            earlierReflection: null,
            status: null,
            assessedBy: {
                profile: 'Avaliação final',
                version: 3,
                ...classScale,
                current_version: currentVersion,
            },
        },
    });
}

describe('self-assessments/Edit — the scale the class is actually on', () => {
    it('names the version and the scale of the class when a question has nothing to choose from', () => {
        const note = mountWith([percentage], 6).get('[data-test="assessed-by"]');

        expect(note.text()).toContain('versão 3 do perfil «Avaliação final», na escala «Percentagem (0 a 100)»');
        expect(note.text()).toContain('Essa escala não tem níveis');
        // The profile has moved on; the class has not.
        expect(note.text()).toContain('versão mais recente (6), mas esta turma continua na versão 3');
        // Saving now fixes these questions for the class; the teacher is told
        // before, not after.
        expect(note.text()).toContain('desde que ainda não haja autoavaliações guardadas nesta turma');
    });

    it('explains questions kept from an earlier configuration when the class itself is now on levels', () => {
        const note = mountWith([percentage], null, { scale: 'Escala 1 a 5', scale_has_levels: true }).get('[data-test="assessed-by"]');

        expect(note.text()).toContain('na escala «Escala 1 a 5»');
        expect(note.text()).toContain('vêm de uma configuração anterior da turma');
        expect(note.text()).not.toContain('Essa escala não tem níveis');
    });

    it('does not mention a newer version when the class is on the active one', () => {
        const note = mountWith([percentage], null).get('[data-test="assessed-by"]');

        expect(note.text()).not.toContain('versão mais recente');
    });

    it('says nothing when every scale question has levels to choose from', () => {
        const wrapper = mountWith([{ ...percentage, scale: 'Escala 1 a 5', levels: [{ id: 4, code: '4', label: 'Bom' }] }], 6);

        expect(wrapper.find('[data-test="assessed-by"]').exists()).toBe(false);
    });
});
