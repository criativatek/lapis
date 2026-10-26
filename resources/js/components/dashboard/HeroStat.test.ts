import { CalendarDays } from '@lucide/vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import HeroStat from './HeroStat.vue';

describe('HeroStat', () => {
    it('por omissão é um div com título, número e rótulo', () => {
        const wrapper = mount(HeroStat, { props: { title: 'Hoje', value: 3, label: 'aulas', tone: 'sky', icon: CalendarDays } });

        expect(wrapper.element.tagName).toBe('DIV');
        expect(wrapper.text()).toContain('Hoje');
        expect(wrapper.find('[data-testid="hero-stat-value"]').text()).toBe('3');
        expect(wrapper.find('[data-testid="hero-stat-value"]').classes()).toContain('tabular-nums');
        expect(wrapper.find('[data-testid="hero-stat-label"]').text()).toBe('aulas');
        expect(wrapper.classes().join(' ')).toContain('bg-sky-50/60');
        expect(wrapper.classes()).toContain('card-soft');
        expect(wrapper.find('[aria-expanded]').exists()).toBe(false);
    });

    it('clicável é um botão real com aria-expanded/aria-controls e emite click', async () => {
        const wrapper = mount(HeroStat, { props: { title: 'Pendentes', value: 2, label: 'pendências', tone: 'amber', clickable: true, expanded: false, controls: 'painel' } });

        expect(wrapper.element.tagName).toBe('BUTTON');
        expect(wrapper.attributes('type')).toBe('button');
        expect(wrapper.attributes('aria-expanded')).toBe('false');
        expect(wrapper.attributes('aria-controls')).toBe('painel');
        expect(wrapper.classes().join(' ')).toContain('bg-amber-50/70');
        await wrapper.trigger('click');
        expect(wrapper.emitted('click')).toHaveLength(1);
        // HTML válido: dentro do botão só há spans (e o SVG do ícone).
        expect(wrapper.find('button p, button div').exists()).toBe(false);
    });

    it('não clicável não emite nada ao clicar', async () => {
        const wrapper = mount(HeroStat, { props: { title: 'Hoje', value: 1, label: 'aula' } });

        await wrapper.trigger('click');
        expect(wrapper.emitted('click')).toBeUndefined();
    });

    it('mostra a nota e o aviso âmbar quando existem', () => {
        const wrapper = mount(HeroStat, { props: { title: 'Sumários', value: '0/2', label: 'concluídos', hint: '1 de hoje em falta', warning: '2 sumários anteriores em falta' } });

        expect(wrapper.find('[data-testid="hero-stat-hint"]').text()).toBe('1 de hoje em falta');
        expect(wrapper.find('[data-testid="hero-stat-warning"]').text()).toBe('2 sumários anteriores em falta');
        expect(wrapper.find('[data-testid="hero-stat-warning"]').classes().join(' ')).toContain('text-amber-800');
        expect(mount(HeroStat, { props: { title: 'a', value: 1, label: 'b' } }).find('[data-testid="hero-stat-warning"]').exists()).toBe(false);
    });
});
