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
        const wrapper = mount(HeroStat, { props: { title: 'Pendentes', value: 2, label: 'pendências', tone: 'plain', attention: true, clickable: true, expanded: false, controls: 'painel' } });

        expect(wrapper.element.tagName).toBe('BUTTON');
        expect(wrapper.attributes('type')).toBe('button');
        expect(wrapper.attributes('aria-expanded')).toBe('false');
        expect(wrapper.attributes('aria-controls')).toBe('painel');
        expect(wrapper.classes().join(' ')).toContain('bg-card border-border/70');
        expect(wrapper.classes().join(' ')).not.toContain('amber');
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

    it('o aviso leva um ícone de relógio decorativo e nenhum ponto', () => {
        const wrapper = mount(HeroStat, { props: { title: 'Sumários', value: '1/2', label: 'concluídos', warning: '1 sumário anterior em falta' } });
        const warning = wrapper.get('[data-testid="hero-stat-warning"]');

        expect(warning.find('svg').attributes('aria-hidden')).toBe('true');
        expect(warning.find('svg').classes()).toContain('lucide-clock');
        expect(warning.find('svg').classes()).toContain('size-3.5');
        expect(warning.find('.rounded-full').exists()).toBe(false);
        expect(warning.text()).toBe('1 sumário anterior em falta');
    });

    it('o indicador âmbar junto ao título só existe com attention e é decorativo', () => {
        const on = mount(HeroStat, { props: { title: 'Pendentes', value: 2, label: 'pendências', attention: true } });
        const off = mount(HeroStat, { props: { title: 'Pendentes', value: 0, label: 'Tudo em dia' } });

        expect(on.get('[data-testid="hero-stat-attention"]').attributes('aria-hidden')).toBe('true');
        expect(on.get('[data-testid="hero-stat-attention"]').text()).toBe('');
        expect(off.find('[data-testid="hero-stat-attention"]').exists()).toBe(false);
    });

    it('aplica a altura mínima recebida', () => {
        expect(mount(HeroStat, { props: { title: 'a', value: 1, label: 'b', minHeight: '@min-[40.5rem]:min-h-[94px]' } }).classes()).toContain('@min-[40.5rem]:min-h-[94px]');
    });
});
