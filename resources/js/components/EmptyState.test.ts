import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { h } from 'vue';
import EmptyState from './EmptyState.vue';

/**
 * «Estados vazios são vazios, nunca zero» — e um vazio sem saída é um beco.
 * O contrato aqui é o slot de acção existir e a moldura ser a tracejada.
 */
describe('EmptyState', () => {
    it('diz o que não existe e qual é o próximo passo', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'Ainda não tem turmas.', description: 'Crie a primeira para começar.' },
            slots: { action: () => h('a', { href: '/classes' }, 'Ir para Turmas') },
        });

        expect(wrapper.text()).toContain('Ainda não tem turmas.');
        expect(wrapper.get('a').text()).toBe('Ir para Turmas');
        expect(wrapper.classes().join(' ')).toContain('border-dashed');
    });

    it('sem acção não rende o contentor dela', () => {
        const wrapper = mount(EmptyState, { props: { title: 'Sem resultados.' } });

        expect(wrapper.findAll('div').length).toBe(1);
    });
});
