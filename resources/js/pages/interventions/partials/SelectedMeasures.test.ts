import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { CatalogueType } from '@/lib/interventionPresentation';
import SelectedMeasures from './SelectedMeasures.vue';

const CATALOGUE: CatalogueType[] = [
    {
        value: 'non_significant_curricular_adaptation',
        label: 'Adaptação curricular não significativa',
        context: 'learning',
        context_label: 'Aprendizagem',
        requires_description: false,
        legal_mapping: {
            mode: 'direct',
            level: 'selective',
            level_label: 'Medida seletiva',
            measure: 'non_significant_curricular_adaptation',
            measure_label: 'Adaptação curricular não significativa',
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
];

function selected(props: Partial<InstanceType<typeof SelectedMeasures>['$props']> = {}) {
    return mount(SelectedMeasures, {
        props: { types: CATALOGUE, selected: [], removable: true, ...props } as never,
    });
}

describe('SelectedMeasures', () => {
    it('says plainly that nothing is chosen yet, rather than showing an empty list', () => {
        const wrapper = selected();

        expect(wrapper.text()).toContain('Ainda não escolheu nenhuma');
        expect(wrapper.findAll('li')).toHaveLength(0);
    });

    it('shows what is chosen with its name, its nature and its level — before any catalogue', () => {
        const wrapper = selected({ selected: ['non_significant_curricular_adaptation'] });

        expect(wrapper.text()).toContain('Adaptação curricular não significativa');
        expect(wrapper.text()).toContain('Medidas de suporte');
        expect(wrapper.text()).toContain('Medida seletiva');
        expect(wrapper.text()).toContain('Aprendizagem');
    });

    it('counts them, so the teacher never has to', () => {
        expect(selected({ selected: ['autonomy_promotion', 'non_significant_curricular_adaptation'] }).text()).toContain('(2)');
    });

    it('keeps the order the teacher chose them in, not the catalogue order', () => {
        const wrapper = selected({ selected: ['autonomy_promotion', 'non_significant_curricular_adaptation'] });
        const items = wrapper.findAll('li');

        expect(items[0].text()).toContain('Promoção da autonomia');
        expect(items[1].text()).toContain('Adaptação curricular');
    });

    it('shows no level at all for a plain pedagogical strategy, instead of «não especificado»', () => {
        const wrapper = selected({ selected: ['autonomy_promotion'] });

        expect(wrapper.text()).toContain('Estratégias pedagógicas');
        expect(wrapper.text()).not.toContain('Medida seletiva');
        expect(wrapper.text()).not.toContain('Não especificado');
    });

    it('names the measure in the remove button, so it is never a bare ×', () => {
        const wrapper = selected({ selected: ['autonomy_promotion'] });

        expect(wrapper.find('button').attributes('aria-label')).toBe('Remover Promoção da autonomia');
    });

    it('removes the one it names, and only that one', async () => {
        const wrapper = selected({ selected: ['autonomy_promotion', 'non_significant_curricular_adaptation'] });

        await wrapper.findAll('button')[1].trigger('click');

        expect(wrapper.emitted('remove')).toEqual([['non_significant_curricular_adaptation']]);
    });

    /**
     * Ao editar, a intervenção É aquela medida. Um × ali seria um convite a
     * gravar uma intervenção sem tipo nenhum — a remoção acidental que esta
     * página não pode ter.
     */
    it('offers no remove button when there is nothing safe to remove', () => {
        const wrapper = selected({ selected: ['autonomy_promotion'], removable: false });

        expect(wrapper.findAll('button')).toHaveLength(0);
        expect(wrapper.text()).toContain('Promoção da autonomia');
    });

    it('ignores a value the catalogue no longer has, instead of drawing an empty card', () => {
        const wrapper = selected({ selected: ['autonomy_promotion', 'a_measure_that_no_longer_exists'] });

        expect(wrapper.findAll('li')).toHaveLength(1);
        expect(wrapper.text()).toContain('(1)');
    });

    it('wraps a long label instead of pushing the card off a narrow screen', () => {
        const long = 'Desenvolvimento de competências de autonomia pessoal e social com acompanhamento individualizado';
        const wrapper = mount(SelectedMeasures, {
            props: { types: [{ ...CATALOGUE[1], label: long }], selected: ['autonomy_promotion'], removable: true } as never,
        });

        expect(wrapper.text()).toContain(long);
        expect(wrapper.html()).toContain('break-words');
    });
});
