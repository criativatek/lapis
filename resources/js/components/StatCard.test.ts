import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { SURFACE } from '@/lib/surfaces';
import StatCard from './StatCard.vue';

/**
 * O contrato: a tinta vem de `surfaces.ts` (uma paleta, não duas), o número é
 * `tabular-nums` (colunas que não dançam), e sem tom pedido o cartão é papel
 * simples — a cor é opt-in, como tudo o resto nesta passagem.
 */
describe('StatCard', () => {
    it('sem tom é papel simples', () => {
        const wrapper = mount(StatCard, { props: { label: 'Turmas', value: 3 } });

        for (const cssClass of SURFACE.plain.split(' ')) {
            expect(wrapper.classes()).toContain(cssClass);
        }
    });

    it('o tom pedido vem da paleta de superfícies', () => {
        const wrapper = mount(StatCard, { props: { label: 'Por confirmar', value: 2, tone: 'amber' } });

        for (const cssClass of SURFACE.amber.split(' ')) {
            expect(wrapper.classes()).toContain(cssClass);
        }
    });

    it('o número não dança: tabular-nums', () => {
        const wrapper = mount(StatCard, { props: { label: 'Turmas', value: 12 } });

        expect(wrapper.get('p').classes()).toContain('tabular-nums');
        expect(wrapper.get('p').text()).toBe('12');
    });

    it('hint aparece sob o rótulo quando existe', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Por publicar', value: 9, hint: 'da turma 7.º A' },
        });

        expect(wrapper.text()).toContain('da turma 7.º A');
    });
});
