import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { CatalogueType } from '@/lib/interventionPresentation';
import MeasurePicker from './MeasurePicker.vue';

const CATALOGUE: CatalogueType[] = [
    {
        value: 'pedagogical_differentiation',
        label: 'Diferenciação pedagógica',
        context: 'learning',
        context_label: 'Aprendizagem',
        requires_description: false,
        legal_mapping: {
            mode: 'direct',
            level: 'universal',
            level_label: 'Medida universal',
            measure: 'pedagogical_differentiation',
            measure_label: 'Diferenciação pedagógica',
            evaluation_adaptation: null,
            evaluation_adaptation_label: null,
        },
    },
    {
        value: 'tutorial_support',
        label: 'Apoio tutorial',
        context: 'learning',
        context_label: 'Aprendizagem',
        requires_description: false,
        legal_mapping: {
            mode: 'direct',
            level: 'selective',
            level_label: 'Medida seletiva',
            measure: 'tutorial_support',
            measure_label: 'Apoio tutorial',
            evaluation_adaptation: null,
            evaluation_adaptation_label: null,
        },
    },
    {
        value: 'autonomy_promotion',
        label: 'Promoção da autonomia',
        context: 'study_autonomy',
        context_label: 'Métodos de estudo e autonomia',
        requires_description: false,
        legal_mapping: null,
    },
    {
        value: 'extra_time',
        label: 'Tempo suplementar em situação de avaliação',
        context: 'evaluation',
        context_label: 'Avaliação',
        requires_description: false,
        legal_mapping: {
            mode: 'evaluation_only',
            level: null,
            level_label: null,
            measure: null,
            measure_label: null,
            evaluation_adaptation: 'extra_time',
            evaluation_adaptation_label: 'Tempo suplementar',
        },
    },
];

function picker(props: Partial<InstanceType<typeof MeasurePicker>['$props']> = {}) {
    return mount(MeasurePicker, {
        props: { types: CATALOGUE, modelValue: [], multiple: true, max: 10, ...props } as never,
    });
}

describe('MeasurePicker — escolher', () => {
    it('offers every catalogue entry, losing none to the grouping', () => {
        expect(picker().findAll('input[type="checkbox"]')).toHaveLength(CATALOGUE.length);
    });

    it('adds a measure to the selection without dropping the ones already there', async () => {
        const wrapper = picker({ modelValue: ['tutorial_support'] });

        await wrapper.find('input[value="pedagogical_differentiation"]').setValue(true);

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['tutorial_support', 'pedagogical_differentiation']]);
    });

    it('removes a measure when its card is unchecked', async () => {
        const wrapper = picker({ modelValue: ['tutorial_support', 'autonomy_promotion'] });

        await wrapper.find('input[value="tutorial_support"]').trigger('change');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['autonomy_promotion']]);
    });

    /**
     * Ao editar, a intervenção É uma medida: escolher outra substitui, e o
     * controlo é um radio — a semântica que um leitor de ecrã anuncia como
     * «um de vários», não como uma caixa que se pode deixar vazia.
     */
    it('replaces instead of accumulating when only one may be chosen', async () => {
        const wrapper = picker({ modelValue: ['tutorial_support'], multiple: false, max: undefined });

        expect(wrapper.findAll('input[type="radio"]')).toHaveLength(CATALOGUE.length);

        await wrapper.find('input[value="autonomy_promotion"]').trigger('change');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['autonomy_promotion']]);
    });

    it('stops at the ceiling the server imposes, and says so in words', () => {
        const wrapper = picker({ modelValue: ['tutorial_support'], max: 1 });

        expect(wrapper.text()).toContain('1 de 1');
        expect(wrapper.find('input[value="autonomy_promotion"]').attributes('disabled')).toBeDefined();
        // A que já está escolhida nunca fica inerte — tem de poder sair.
        expect(wrapper.find('input[value="tutorial_support"]').attributes('disabled')).toBeUndefined();
    });

    it('never blocks a ceiling that was not asked for', () => {
        const wrapper = picker({ modelValue: ['tutorial_support'], multiple: false, max: undefined });

        expect(wrapper.find('input[value="autonomy_promotion"]').attributes('disabled')).toBeUndefined();
    });
});

describe('MeasurePicker — pesquisa e filtros', () => {
    it('narrows locally, with no request of its own', async () => {
        const wrapper = picker();

        await wrapper.find('input[type="search"]').setValue('tutorial');

        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(1);
        expect(wrapper.text()).toContain('Apoio tutorial');
    });

    it('finds an accented label typed without accents', async () => {
        const wrapper = picker();

        await wrapper.find('input[type="search"]').setValue('diferenciacao');

        expect(wrapper.text()).toContain('Diferenciação pedagógica');
    });

    it('says so plainly when nothing matches, instead of an empty box', async () => {
        const wrapper = picker();

        await wrapper.find('input[type="search"]').setValue('xilofone');

        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0);
        expect(wrapper.text()).toContain('Nenhuma medida ou estratégia corresponde');
    });

    it('offers a filter for each family the catalogue really has, and no other', () => {
        const labels = picker()
            .findAll('[role="group"] button')
            .map((button) => button.text());

        expect(labels).toEqual(['Todas', 'Medidas de suporte', 'Estratégias pedagógicas', 'Adaptações à avaliação']);
        expect(labels).not.toContain('Apoios e recursos');
    });

    it('filters down to one family and back', async () => {
        const wrapper = picker();
        const strategies = wrapper.findAll('[role="group"] button').find((button) => button.text() === 'Estratégias pedagógicas');

        await strategies?.trigger('click');
        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(1);

        await strategies?.trigger('click');
        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(CATALOGUE.length);
    });

    it('combines the search with the family filter rather than replacing it', async () => {
        const wrapper = picker();

        await wrapper.findAll('[role="group"] button').find((button) => button.text() === 'Medidas de suporte')?.trigger('click');
        await wrapper.find('input[type="search"]').setValue('tutorial');

        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(1);
    });
});

describe('MeasurePicker — acessibilidade', () => {
    /**
     * A regra da casa: a cor é reforço, nunca a mensagem. Uma medida
     * universal e uma seletiva distinguem-se pelo rótulo antes de se
     * distinguirem pelo tom.
     */
    it('writes the level out in full next to its colour', () => {
        const text = picker().text();

        expect(text).toContain('Medida universal');
        expect(text).toContain('Medida seletiva');
    });

    it('names each family in words as well as by its icon', () => {
        const text = picker().text();

        expect(text).toContain('Medidas de suporte');
        expect(text).toContain('Estratégias pedagógicas');
        expect(text).toContain('Adaptações à avaliação');
    });

    it('keeps the pedagogical category visible beside the family — two axes, not one', () => {
        const text = picker().text();

        expect(text).toContain('Aprendizagem');
        expect(text).toContain('Métodos de estudo e autonomia');
    });

    it('gives the search a label even with no visible one', () => {
        expect(picker().find('input[type="search"]').attributes('aria-label')).toBe('Pesquisar medidas ou estratégias');
    });

    it('makes each card a real focusable control, so Tab reaches every option', () => {
        // sr-only, não `hidden`: continua na ordem de tabulação e continua a
        // ser anunciado. Um `div` com aria-pressed não teria nenhuma das duas.
        const inputs = picker().findAll('input[type="checkbox"]');

        expect(inputs).toHaveLength(CATALOGUE.length);
        inputs.forEach((input) => {
            expect(input.classes()).toContain('sr-only');
            expect(input.attributes('hidden')).toBeUndefined();
        });
    });

    it('carries the selected state on the control itself, not only in a colour', () => {
        const wrapper = picker({ modelValue: ['tutorial_support'] });

        expect((wrapper.find('input[value="tutorial_support"]').element as HTMLInputElement).checked).toBe(true);
        expect((wrapper.find('input[value="autonomy_promotion"]').element as HTMLInputElement).checked).toBe(false);
    });

    it('announces how many results the search left', async () => {
        const wrapper = picker();
        const status = wrapper.find('[role="status"]');

        expect(status.attributes('aria-live')).toBe('polite');
        expect(status.text()).toContain('4 resultados');

        await wrapper.find('input[type="search"]').setValue('tutorial');
        expect(wrapper.find('[role="status"]').text()).toContain('1 resultado');
    });

    it('marks the active family filter with aria-pressed, not just a border', async () => {
        const wrapper = picker();
        const strategies = wrapper.findAll('[role="group"] button').find((button) => button.text() === 'Estratégias pedagógicas');

        expect(strategies?.attributes('aria-pressed')).toBe('false');
        await strategies?.trigger('click');
        expect(wrapper.findAll('[role="group"] button').find((button) => button.text() === 'Estratégias pedagógicas')?.attributes('aria-pressed')).toBe('true');
    });
});

describe('MeasurePicker — casos extremos', () => {
    it('survives an empty catalogue without a filter bar or a crash', () => {
        const wrapper = picker({ types: [] });

        expect(wrapper.find('[role="group"]').exists()).toBe(false);
        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0);
    });

    it('lays a very long label out without forcing the page sideways', () => {
        const long = 'Desenvolvimento de competências de autonomia pessoal e social com acompanhamento individualizado continuado ao longo do ano letivo';
        const wrapper = picker({ types: [{ ...CATALOGUE[2], label: long }] });

        expect(wrapper.text()).toContain(long);
        // `break-words` é o que impede um rótulo sem espaços de empurrar o
        // card para fora do ecrã a 390px.
        expect(wrapper.html()).toContain('break-words');
    });

    it('copes with a catalogue of a single family by hiding the filter bar entirely', () => {
        const wrapper = picker({ types: [CATALOGUE[2]] });

        expect(wrapper.find('[role="group"]').exists()).toBe(false);
        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(1);
    });
});
