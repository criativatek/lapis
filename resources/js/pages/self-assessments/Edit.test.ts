import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import type { SelfAssessmentConfiguration, SelfAssessmentEarlierRound, SelfAssessmentQuestion } from '@/types/self-assessment';
import Edit from './Edit.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    useForm: <T extends object>(data: T) => reactive({ ...data, errors: {}, processing: false, post: vi.fn() }),
}));

const global: SelfAssessmentQuestion = {
    id: 1,
    block: 'performance',
    role: 'global',
    domain: null,
    prompt: 'Classificação que proponho para a minha avaliação neste período',
    answer_kind: 'scale',
    levels: [],
    scale: 'Escala 0 a 20',
    answer_level_id: null,
    answer_text: null,
    answer: { mode: 'number', levels: [], min: '0', max: '20', step: '1', decimals: 0, unit: null, instruction: 'Escreve um valor de 0 a 20, sem casas decimais.', problem: null },
    answer_number: '16',
};

function configurationOf(overrides: Partial<SelfAssessmentConfiguration> = {}): SelfAssessmentConfiguration {
    return {
        profile: 'Avaliação final',
        version: 3,
        scale: 'Escala 0 a 20',
        domains: [
            { name: 'Conhecimento', weight: '60' },
            { name: 'Atitudes', weight: '12.5' },
        ],
        answer: null,
        state: 'current',
        class_criteria: { profile: 'Avaliação final', version: 3, scale: 'Escala 0 a 20' },
        saved_count: 0,
        can_renew: false,
        can_edit: true,
        class_url: '/classes/class-1',
        review: null,
        ...overrides,
    };
}

function mountWith(configuration: SelfAssessmentConfiguration, earlierRounds: SelfAssessmentEarlierRound[] = []) {
    return mount(Edit, {
        props: {
            schoolClass: { ulid: 'class-1', label: '8.º A' },
            period: { ulid: 'period-1', label: '1.º Semestre' },
            student: 'Aluno de teste',
            enrollmentUlid: 'enrollment-1',
            template: 'TEMPLATE-ULID',
            questions: [global],
            earlierReflection: null,
            earlierRounds,
            status: null,
            configuration,
        },
    });
}

describe('self-assessments/Edit — the criteria of the class', () => {
    it('says the form uses the criteria of the class, lists the domains with their weights and points to the class', () => {
        const wrapper = mountWith(configurationOf({ review: { version: 4, scale: 'Escala 1 a 5', url: '/classes/class-1/profile-migration?to=x' } }));
        const panel = wrapper.get('[data-test="criteria-panel"]');

        expect(wrapper.get('[data-test="criteria-current"]').text()).toBe('Esta autoavaliação utiliza os critérios de avaliação da turma: Avaliação final · versão 3 · Escala 0 a 20.');
        expect(panel.text()).toContain('Conhecimento — 60%');
        expect(panel.text()).toContain('Atitudes — 12,5%');
        expect(panel.text()).toContain('Os domínios, as ponderações e a escala vêm do perfil da turma e não se alteram aqui.');

        const links = wrapper.findAll('a');
        expect(links.find((link) => link.text() === 'Abrir a página da turma')?.attributes('href')).toBe('/classes/class-1');
        expect(links.find((link) => link.text().startsWith('Rever e aplicar a versão 4'))?.text()).toBe('Rever e aplicar a versão 4 (Escala 1 a 5)');
    });

    it('does not offer a review when there is none', () => {
        expect(mountWith(configurationOf()).find('[data-test="criteria-review"]').exists()).toBe(false);
    });

    it('in the previous state, tells the teacher the class moved on and the answers did not', () => {
        const wrapper = mountWith(configurationOf({ state: 'previous', saved_count: 3, class_criteria: { profile: 'Avaliação final', version: 4, scale: 'Escala 1 a 5' } }));
        const note = wrapper.get('[data-test="criteria-previous"]');

        expect(note.attributes('role')).toBe('note');
        expect(note.text()).toContain('A turma passou a ser avaliada com «Avaliação final · versão 4 · Escala 1 a 5».');
        expect(note.text()).toContain('continua com os critérios em que foi preenchida (Avaliação final · versão 3 · Escala 0 a 20)');
        expect(note.text()).toContain('já tem 3 autoavaliações guardadas');
        expect(wrapper.find('[data-test="criteria-current"]').exists()).toBe(false);
        // The one-student page cannot renew: it points to the list.
        expect(wrapper.findAll('a').some((link) => link.attributes('href') === '/classes/class-1/self-assessments')).toBe(true);
    });

    it('in the no_profile state, explains there is nothing to ask yet and links to the class', () => {
        const wrapper = mountWith(configurationOf({ state: 'no_profile', profile: null, version: null, scale: null, domains: [], class_criteria: null }));

        expect(wrapper.get('[data-test="criteria-no-profile"]').text()).toContain('Esta turma ainda não tem critérios de avaliação.');
        expect(wrapper.text()).toContain('depois de associar um perfil na página da turma');
        expect(wrapper.findAll('a').some((link) => link.attributes('href') === '/classes/class-1')).toBe(true);
    });

    it('explains an answer scale that cannot be answered and how to fix it', () => {
        const answer = { mode: 'unavailable' as const, levels: [], min: null, max: null, step: '1', decimals: 0, unit: null, instruction: 'x', problem: 'A escala «Escala X» não tem limites mínimo e máximo válidos.' };
        const note = mountWith(configurationOf({ answer })).get('[data-test="criteria-problem"]');

        expect(note.text()).toContain('A escala «Escala X» não tem limites mínimo e máximo válidos.');
        expect(note.text()).toContain('use «Rever e aplicar alterações»');
    });

    it('no longer shows the old «assessed-by» warning', () => {
        expect(mountWith(configurationOf()).find('[data-test="assessed-by"]').exists()).toBe(false);
    });

    it('shows earlier rounds read-only, with the criteria they were filled in under', () => {
        const wrapper = mountWith(configurationOf(), [
            { submitted_at: '2026-06-10T10:00:00+01:00', criteria: 'Avaliação final · versão 2 · Percentagem (0 a 100)', answers: [{ prompt: 'Como avalio o meu desempenho em Conhecimento?', value: '80%' }] },
        ]);
        const section = wrapper.get('[data-test="earlier-rounds"]');

        expect(section.text()).toContain('Autoavaliação anterior deste período');
        expect(section.text()).toContain('Preenchida com os critérios anteriores: Avaliação final · versão 2 · Percentagem (0 a 100)');
        expect(section.text()).toContain('10 de junho de 2026');
        expect(section.text()).toContain('80%');
        expect(section.find('input, select, textarea').exists()).toBe(false);
    });

    it('has no earlier-rounds section when there are none', () => {
        expect(mountWith(configurationOf()).find('[data-test="earlier-rounds"]').exists()).toBe(false);
    });

    it('opens the numeric answer already filled in', () => {
        expect((mountWith(configurationOf()).get('input[type="text"]').element as HTMLInputElement).value).toBe('16');
    });
});
