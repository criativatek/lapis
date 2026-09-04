import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { h } from 'vue';
import PageHeader from './PageHeader.vue';

/**
 * O contrato: mesma tipografia do Heading, e um sítio ÚNICO para as acções —
 * era isso que 84 páginas não tinham e resolviam com flex à mão.
 */
describe('PageHeader', () => {
    it('mostra título e descrição com a tipografia do Heading', () => {
        const wrapper = mount(PageHeader, {
            props: { title: 'Alunos', description: 'Os alunos das suas turmas.' },
        });

        expect(wrapper.get('h2').text()).toBe('Alunos');
        expect(wrapper.get('h2').classes()).toContain('text-xl');
        expect(wrapper.text()).toContain('Os alunos das suas turmas.');
    });

    it('as acções entram no slot, à direita', () => {
        const wrapper = mount(PageHeader, {
            props: { title: 'Turmas' },
            slots: { actions: () => h('button', 'Nova turma') },
        });

        expect(wrapper.get('button').text()).toBe('Nova turma');
        expect(wrapper.get('header').classes()).toContain('justify-between');
    });

    it('sem slot de acções não rende contentor vazio', () => {
        const wrapper = mount(PageHeader, { props: { title: 'Turmas' } });

        expect(wrapper.findAll('div').length).toBe(1);
    });

    it('a variante small encolhe como no Heading', () => {
        const wrapper = mount(PageHeader, {
            props: { title: 'As minhas turmas', variant: 'small' },
        });

        expect(wrapper.get('h2').classes()).toContain('text-base');
    });
});
