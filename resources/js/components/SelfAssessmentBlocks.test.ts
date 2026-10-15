import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { SelfAssessmentAnswerScale, SelfAssessmentQuestion } from '@/types/self-assessment';
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
        answer: null,
        answer_number: null,
        ...overrides,
    };
}

function answerScale(overrides: Partial<SelfAssessmentAnswerScale>): SelfAssessmentAnswerScale {
    return {
        mode: 'number',
        levels: [],
        min: '0',
        max: '20',
        step: '1',
        decimals: 0,
        unit: null,
        instruction: 'Escreve um valor de 0 a 20, sem casas decimais.',
        problem: null,
        ...overrides,
    };
}

function mountWith(questions: SelfAssessmentQuestion[], extra: Record<string, unknown> = {}) {
    return mount(SelfAssessmentBlocks, {
        props: {
            questions,
            earlierReflection: null,
            answers: Object.fromEntries(questions.map((item) => [item.id, null])),
            texts: {},
            numbers: Object.fromEntries(questions.map((item) => [item.id, ''])),
            ...extra,
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

describe('SelfAssessmentBlocks — how a scale question is answered', () => {
    it('on levels, shows the instruction under the prompt and links it to the select', () => {
        const levels = [
            { id: 11, code: '1', label: 'Fraco' },
            { id: 15, code: '5', label: 'Muito Bom' },
        ];
        const wrapper = mountWith([question({ levels, answer: answerScale({ mode: 'levels', levels, min: null, max: null, instruction: 'Escolhe um nível de 1 (Fraco) a 5 (Muito Bom).' }) })]);

        const help = wrapper.get('[data-test="answer-instruction"]');
        expect(help.text()).toBe('Escolhe um nível de 1 (Fraco) a 5 (Muito Bom).');
        expect(wrapper.get('select').attributes('aria-describedby')).toBe(help.attributes('id'));
        expect(wrapper.get('select').classes()).toContain('min-h-11');
    });

    it('on an interval scale, asks for a number with the instruction — never a select', () => {
        const wrapper = mountWith([question({ scale: 'Escala 0 a 20', answer: answerScale({}) })]);

        expect(wrapper.findAll('select')).toHaveLength(0);

        const input = wrapper.get('input[type="text"]');
        expect(input.attributes('inputmode')).toBe('decimal');
        expect(input.classes()).toContain('min-h-11');

        const help = wrapper.get('[data-test="answer-instruction"]');
        expect(help.text()).toBe('Escreve um valor de 0 a 20, sem casas decimais.');
        expect(input.attributes('aria-describedby')).toBe(help.attributes('id'));
    });

    it('shows the % suffix on a percentage', () => {
        const wrapper = mountWith([question({ answer: answerScale({ max: '100', unit: '%', instruction: 'Escreve uma percentagem de 0 a 100, sem casas decimais.' }) })]);

        expect(wrapper.text()).toContain('%');
    });

    it('on an unavailable scale, shows the amber note with the instruction and no control', () => {
        const wrapper = mountWith([question({ answer: answerScale({ mode: 'unavailable', min: null, max: null, instruction: 'Esta pergunta não pode ser respondida por agora. Avisa o teu professor.' }) })]);

        expect(wrapper.find('select').exists()).toBe(false);
        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.get('[data-test="scale-without-levels"]').text()).toBe('Esta pergunta não pode ser respondida por agora. Avisa o teu professor.');
    });

    it('tells the teacher what is wrong with the scale instead of the sentence meant for the student', () => {
        const answer = answerScale({ mode: 'unavailable', min: null, max: null, instruction: 'Esta pergunta não pode ser respondida por agora. Avisa o teu professor.', problem: 'A escala «Níveis QA» é de níveis, mas não tem nenhum nível definido.' });

        expect(mountWith([question({ answer })], { audience: 'teacher' }).get('[data-test="scale-without-levels"]').text()).toBe('A escala «Níveis QA» é de níveis, mas não tem nenhum nível definido.');
        expect(mountWith([question({ answer })]).get('[data-test="scale-without-levels"]').text()).toBe('Esta pergunta não pode ser respondida por agora. Avisa o teu professor.');
    });
});

describe('SelfAssessmentBlocks — errors and the global question', () => {
    it('puts the error of a question right after its field, as an alert', () => {
        const wrapper = mountWith([question({ id: 7, answer: answerScale({}) })], { errors: { 'numbers.7': 'Indica um valor de 0 a 20, sem casas decimais.' } });

        expect(wrapper.get('[data-test="question-error"]').text()).toBe('Indica um valor de 0 a 20, sem casas decimais.');
        expect(wrapper.get('[data-test="question-error"]').attributes('role')).toBe('alert');
        expect(wrapper.get('input').attributes('aria-invalid')).toBe('true');
    });

    it('says at the top that nothing was saved when a question was refused', () => {
        const wrapper = mountWith([question({ id: 7, answer: answerScale({}) })], { errors: { 'numbers.7': 'Indica um valor de 0 a 20, sem casas decimais.' } });

        expect(wrapper.get('[data-test="question-errors-summary"]').text()).toBe('Nada foi guardado: há respostas por corrigir, assinaladas junto de cada pergunta.');
    });

    it('has no summary without a question error', () => {
        expect(mountWith([question({ answer: answerScale({}) })], { errors: { answers: 'As perguntas mudaram.' } }).find('[data-test="question-errors-summary"]').exists()).toBe(false);
    });

    it('shows the error of a level question under the answers key', () => {
        const levels = [{ id: 11, code: '1', label: 'Fraco' }];
        const wrapper = mountWith([question({ id: 8, levels, answer: answerScale({ mode: 'levels', levels }) })], { errors: { 'answers.8': 'Escolhe um dos níveis da escala «Escala 1 a 5».' } });

        expect(wrapper.get('[data-test="question-error"]').text()).toContain('Escolhe um dos níveis');
    });

    it('says to the student that the global answer is a personal appreciation, not an average', () => {
        const wrapper = mountWith([question({ role: 'global', domain: null, answer: answerScale({}) })]);

        expect(wrapper.get('[data-test="global-support"]').text()).toBe('É a tua apreciação pessoal da classificação que consideras merecer. Não é uma média das respostas anteriores.');
    });

    it('changes the person for the teacher', () => {
        const wrapper = mountWith([question({ role: 'global', domain: null, answer: answerScale({}) })], { audience: 'teacher' });

        expect(wrapper.get('[data-test="global-support"]').text()).toBe('É a apreciação pessoal do aluno — não é uma média das respostas por domínio nem altera a classificação atribuída.');
    });

    it('does not add the sentence to a domain question', () => {
        expect(mountWith([question({ answer: answerScale({}) })]).find('[data-test="global-support"]').exists()).toBe(false);
    });
});
