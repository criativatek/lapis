import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { SelfAssessmentQuestion } from '@/types/self-assessment';
import SelfAssessmentBlocks from './SelfAssessmentBlocks.vue';

/**
 * A scale question is answered by choosing one of THAT scale's levels. A class
 * assessed on a percentage has none — and the form used to show a select
 * holding nothing but «—», which reads as a fault rather than as what it is.
 */
function question(overrides: Partial<SelfAssessmentQuestion>): SelfAssessmentQuestion {
    return {
        id: 1,
        block: 'performance',
        role: null,
        domain: 'Comportamento',
        prompt: 'Como avalio o meu desempenho em Comportamento?',
        answer_kind: 'scale',
        levels: [],
        scale: null,
        answer_level_id: null,
        answer_text: null,
        ...overrides,
    };
}

function mountWith(questions: SelfAssessmentQuestion[]) {
    return mount(SelfAssessmentBlocks, {
        props: {
            questions,
            earlierReflection: null,
            answers: Object.fromEntries(questions.map((item) => [item.id, null])),
            texts: {},
        },
    });
}

describe('SelfAssessmentBlocks — a scale question', () => {
    it('on a scale without levels, says so instead of offering an empty select', () => {
        const wrapper = mountWith([
            question({ id: 1, scale: 'Percentagem (0 a 100)' }),
            question({ id: 2, role: 'global', domain: null, prompt: 'Classificação que proponho para a minha avaliação neste período', scale: 'Percentagem (0 a 100)' }),
        ]);

        expect(wrapper.findAll('select')).toHaveLength(0);

        const notes = wrapper.findAll('[data-test="scale-without-levels"]');
        expect(notes).toHaveLength(2);
        expect(notes[0].text()).toContain('«Percentagem (0 a 100)» não tem níveis');
        // Nothing invented in its place.
        expect(wrapper.text()).not.toContain('Muito Bom');
    });

    it('on a scale with levels, offers exactly those levels, number first', () => {
        const wrapper = mountWith([
            question({
                scale: 'Escala 1 a 5',
                levels: [
                    { id: 11, code: '1', label: 'Fraco' },
                    { id: 13, code: '3', label: 'Suficiente' },
                    { id: 15, code: '5', label: 'Muito Bom' },
                ],
            }),
        ]);

        expect(wrapper.find('[data-test="scale-without-levels"]').exists()).toBe(false);

        const options = wrapper.findAll('select option').map((option) => option.text());
        expect(options).toEqual(['—', '1 — Fraco', '3 — Suficiente', '5 — Muito Bom']);
    });

    it('a written question is still a text box', () => {
        const wrapper = mountWith([question({ id: 3, block: 'reflection', role: 'rationale', domain: null, answer_kind: 'text', prompt: 'Porque proponho esta classificação?' })]);

        expect(wrapper.find('textarea').exists()).toBe(true);
        expect(wrapper.find('[data-test="scale-without-levels"]').exists()).toBe(false);
    });
});
