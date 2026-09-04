import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { h } from 'vue';
import TableShell from './TableShell.vue';

/**
 * O contrato: as linhas das páginas caem dentro de thead/tbody verdadeiros, e
 * a moldura traz o `overflow-x-auto` — a regra de que uma tabela larga rola
 * dentro de si própria e nunca arrasta a página (mobile 375).
 */
describe('TableShell', () => {
    it('slots caem em thead e tbody', () => {
        const wrapper = mount(TableShell, {
            slots: {
                head: () => h('tr', [h('th', 'Aluno')]),
                body: () => h('tr', [h('td', 'Ana Marques')]),
            },
        });

        expect(wrapper.get('thead th').text()).toBe('Aluno');
        expect(wrapper.get('tbody td').text()).toBe('Ana Marques');
    });

    it('a moldura rola dentro de si própria', () => {
        const wrapper = mount(TableShell);

        expect(wrapper.classes()).toContain('overflow-x-auto');
        expect(wrapper.get('table').classes()).toContain('w-full');
    });
});
